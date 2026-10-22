<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LedgeHQ\Ledge\Acquire\Database\DatabaseDumper;
use LedgeHQ\Ledge\Acquire\Database\DatabaseInspector;
use LedgeHQ\Ledge\Acquire\Database\SpatieDatabaseDumper;
use LedgeHQ\Ledge\Checks\CacheCheck;
use LedgeHQ\Ledge\Checks\CheckInterface;
use LedgeHQ\Ledge\Checks\DatabaseCheck;
use LedgeHQ\Ledge\Checks\DebugModeCheck;
use LedgeHQ\Ledge\Checks\DiskSpaceCheck;
use LedgeHQ\Ledge\Checks\EnvironmentCheck;
use LedgeHQ\Ledge\Checks\FailedLoginsCheck;
use LedgeHQ\Ledge\Checks\HorizonCheck;
use LedgeHQ\Ledge\Checks\MemoryCheck;
use LedgeHQ\Ledge\Checks\OptimizedCheck;
use LedgeHQ\Ledge\Checks\PackagesCheck;
use LedgeHQ\Ledge\Checks\PhpVersionCheck;
use LedgeHQ\Ledge\Checks\QueueCheck;
use LedgeHQ\Ledge\Checks\RedisCheck;
use LedgeHQ\Ledge\Checks\SchedulerCheck;
use LedgeHQ\Ledge\Checks\StorageCheck;
use LedgeHQ\Ledge\Console\GenerateKeyCommand;
use LedgeHQ\Ledge\Console\RefreshPackagesCommand;
use LedgeHQ\Ledge\Http\Controllers\AcquireController;
use LedgeHQ\Ledge\Http\Controllers\DependenciesController;
use LedgeHQ\Ledge\Http\Controllers\HealthController;
use LedgeHQ\Ledge\Http\Middleware\VerifyLedgeKey;
use LedgeHQ\Ledge\Jobs\BackupJob;
use LedgeHQ\Ledge\Listeners\RecordFailedLogin;
use LedgeHQ\Ledge\Listeners\RecordSchedulerRun;
use LedgeHQ\Ledge\Support\FailedLoginStore;
use LedgeHQ\Ledge\Support\PackagesStore;
use LedgeHQ\Ledge\Support\PackagistClient;
use Psr\Log\LoggerInterface;
use Throwable;

class LedgeServiceProvider extends ServiceProvider
{
    /** @var list<class-string<CheckInterface>> */
    public const DEFAULT_CHECKS = [
        DatabaseCheck::class,
        QueueCheck::class,
        DiskSpaceCheck::class,
        MemoryCheck::class,
        DebugModeCheck::class,
        EnvironmentCheck::class,
        StorageCheck::class,
        SchedulerCheck::class,
        FailedLoginsCheck::class,
        CacheCheck::class,
        RedisCheck::class,
        HorizonCheck::class,
        OptimizedCheck::class,
        PhpVersionCheck::class,
        PackagesCheck::class,
    ];

    /** @var array<string, class-string<Jobs\AbstractAcquireJob>> */
    public const DEFAULT_PROFILES = [
        'backup' => BackupJob::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ledge.php', 'ledge');

        $this->app->singleton(Settings::class, function (Application $app): Settings {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('ledge', []);

            return Settings::fromArray($config, $app->make(LoggerInterface::class));
        });

        $this->app->singleton(DependenciesService::class, fn (Application $app): DependenciesService => new DependenciesService($app->basePath()));

        $this->app->singleton(FailedLoginStore::class, fn (Application $app): FailedLoginStore => new FailedLoginStore(
            $app->make(CacheRepository::class),
            $app->make(Settings::class)->failedLoginsWindow,
        ));

        $this->registerAcquire();

        $this->app->singleton(PackagesStore::class, fn (Application $app): PackagesStore => new PackagesStore($app->make(CacheRepository::class)));
        $this->app->bind(PackagistClient::class, fn (Application $app): PackagistClient => new PackagistClient($app->make(ClientInterface::class)));

        $this->app->singleton(HealthService::class, function (Application $app): HealthService {
            /** @var Settings $settings */
            $settings = $app->make(Settings::class);
            /** @var LoggerInterface $logger */
            $logger = $app->make(LoggerInterface::class);

            $service = new HealthService(
                $app,
                $settings,
                $app->make(DependenciesService::class),
                $app->make(AcquireService::class),
                $logger,
            );

            foreach ([...self::DEFAULT_CHECKS, ...$settings->checks] as $class) {
                try {
                    $check = class_exists($class) ? $app->make($class) : null;
                } catch (Throwable $e) {
                    $logger->warning("Ledge: could not build check '{$class}': {$e->getMessage()}");

                    continue;
                }

                if (! $check instanceof CheckInterface) {
                    $logger->warning("Ledge: '{$class}' in ledge.checks is not a CheckInterface implementation; skipped");

                    continue;
                }

                $service->register($check);
            }

            return $service;
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ledge.php' => $this->app->configPath('ledge.php'),
        ], 'ledge-config');

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateKeyCommand::class, RefreshPackagesCommand::class]);
        }

        $this->scheduleRefreshes();

        $this->registerListeners();
        $this->registerRoutes();
    }

    private function scheduleRefreshes(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ($this->app->make(Settings::class)->packagesAutoRefresh) {
                $schedule->command('ledge:packages:refresh')->daily()->withoutOverlapping()->name('ledge:packages:refresh');
            }
        });
    }

    private function registerAcquire(): void
    {
        $this->app->bindIf(ClientInterface::class, Client::class);

        $this->app->singleton(AcquireService::class, function (Application $app): AcquireService {
            $service = new AcquireService(
                $app->make(Settings::class),
                $app->make(CacheRepository::class),
                $app->make(ClientInterface::class),
                $app->make(BusDispatcher::class),
                $app->storagePath('framework/ledge'),
            );

            foreach (self::DEFAULT_PROFILES as $profile => $job) {
                $service->registerProfile($profile, $job);
            }

            return $service;
        });

        $this->app->bind(DatabaseDumper::class, function (Application $app): DatabaseDumper {
            /** @var Settings $settings */
            $settings = $app->make(Settings::class);
            /** @var string $connection */
            $connection = $app['config']->get('database.default');
            /** @var array<string, mixed> $config */
            $config = $app['config']->get("database.connections.{$connection}", []);

            return new SpatieDatabaseDumper($config, $settings->acquireDumpBinaryPath, $settings->acquireJobTimeout);
        });

        $this->app->bind(DatabaseInspector::class, fn (Application $app): DatabaseInspector => new DatabaseInspector(
            $app->make(DatabaseManager::class)->connection(),
        ));
    }

    private function registerListeners(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);
        $events->listen(Failed::class, RecordFailedLogin::class);
        $events->listen(CommandFinished::class, RecordSchedulerRun::class);
    }

    private function registerRoutes(): void
    {
        if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
            return;
        }

        /** @var Settings $settings */
        $settings = $this->app->make(Settings::class);
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->middleware(VerifyLedgeKey::class)->group(function (Router $router) use ($settings): void {
            $router->get($settings->healthPath, HealthController::class)->name('ledge.health');

            if ($settings->dependenciesEnabled) {
                $router->get($settings->dependenciesPath, DependenciesController::class)->name('ledge.dependencies');
            }
        });

        if ($settings->acquireEnabled) {
            $router->middleware(VerifyLedgeKey::class.':'.VerifyLedgeKey::MODE_ACQUIRE)->group(function (Router $router) use ($settings): void {
                $router->post($settings->acquirePath, [AcquireController::class, 'accept'])->name('ledge.acquire');
                $router->get($settings->acquirePath.'/{runId}', [AcquireController::class, 'status'])
                    ->where('runId', '[A-Za-z0-9\\-]{1,64}')
                    ->name('ledge.acquire.status');
            });
        }
    }
}
