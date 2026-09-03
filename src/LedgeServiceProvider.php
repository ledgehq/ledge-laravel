<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge;

use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LedgeHQ\Ledge\Checks\DatabaseCheck;
use LedgeHQ\Ledge\Checks\DebugModeCheck;
use LedgeHQ\Ledge\Checks\DiskSpaceCheck;
use LedgeHQ\Ledge\Checks\EnvironmentCheck;
use LedgeHQ\Ledge\Checks\FailedLoginsCheck;
use LedgeHQ\Ledge\Checks\MemoryCheck;
use LedgeHQ\Ledge\Checks\QueueCheck;
use LedgeHQ\Ledge\Checks\SchedulerCheck;
use LedgeHQ\Ledge\Checks\StorageCheck;
use LedgeHQ\Ledge\Console\GenerateKeyCommand;
use LedgeHQ\Ledge\Http\Controllers\DependenciesController;
use LedgeHQ\Ledge\Http\Controllers\HealthController;
use LedgeHQ\Ledge\Http\Middleware\VerifyLedgeKey;
use LedgeHQ\Ledge\Listeners\RecordFailedLogin;
use LedgeHQ\Ledge\Listeners\RecordSchedulerRun;
use LedgeHQ\Ledge\Support\FailedLoginStore;
use Psr\Log\LoggerInterface;

class LedgeServiceProvider extends ServiceProvider
{
    /** @var list<class-string<Checks\CheckInterface>> */
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

        $this->app->singleton(HealthService::class, function (Application $app): HealthService {
            $service = new HealthService(
                $app,
                $app->make(Settings::class),
                $app->make(DependenciesService::class),
                $app->make(LoggerInterface::class),
            );

            foreach (self::DEFAULT_CHECKS as $check) {
                $service->register($app->make($check));
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
            $this->commands([GenerateKeyCommand::class]);
        }

        $this->registerListeners();
        $this->registerRoutes();
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
    }
}
