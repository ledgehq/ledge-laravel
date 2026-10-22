<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\DependenciesService;
use LedgeHQ\Ledge\HealthService;
use LedgeHQ\Ledge\Settings;
use LedgeHQ\Ledge\Support\PackagesStore;
use LedgeHQ\Ledge\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class PackagesCheckTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var Application $app */
        $app['config']->set('cache.default', 'array');
    }

    protected function autoRefreshOff(Application $app): void
    {
        $app['config']->set('ledge.packages_auto_refresh', false);
    }

    public function test_reports_healthy_with_a_hint_before_the_first_refresh(): void
    {
        $this->health()
            ->assertJsonPath('checks.packages.status', 'healthy')
            ->assertJsonPath('checks.packages.refreshedAt', null)
            ->assertJsonPath('checks.packages.frameworkVersion', ['installed' => $this->app->version(), 'latest' => null, 'updateAvailable' => false])
            ->assertJsonPath('checks.packages.output', 'Run `php artisan ledge:packages:refresh` to populate this check');
    }

    public function test_refresh_command_compares_with_packagist_and_the_check_reads_the_snapshot(): void
    {
        $this->fakeInstalled([
            ['name' => 'laravel/framework', 'version' => '13.30.1'],
            ['name' => 'acme/private', 'version' => '2.0.0'],
            ['name' => 'spatie/db-dumper', 'version' => '4.1.1'],
            ['name' => 'vendor/branch', 'version' => 'dev-main'],
        ]);

        $responses = [
            'laravel/framework' => $this->p2('laravel/framework', ['v13.30.1', 'v13.38.0', 'v14.0.0-beta.1', 'v13.x-dev']),
            'acme/private' => new Response(404),
            'spatie/db-dumper' => $this->p2('spatie/db-dumper', ['4.1.1', '4.0.0']),
            'vendor/branch' => $this->p2('vendor/branch', ['1.0.0']),
        ];
        $handler = new MockHandler;
        foreach ($responses as $name => $response) {
            $handler->append(function ($request) use ($responses) {
                $name = substr($request->getUri()->getPath(), strlen('/p2/'), -strlen('.json'));

                return $responses[$name];
            });
        }
        $this->app->instance(ClientInterface::class, new Client(['handler' => HandlerStack::create($handler)]));

        $this->artisan('ledge:packages:refresh')
            ->expectsOutputToContain('4 packages checked, 1 outdated, 1 not found on Packagist.')
            ->assertSuccessful();

        $response = $this->health();

        $response->assertJsonPath('checks.packages.status', 'healthy')
            ->assertJsonPath('checks.packages.total', 4)
            ->assertJsonPath('checks.packages.outdatedCount', 1)
            ->assertJsonPath('checks.packages.notOnPackagist', 1)
            ->assertJsonPath('checks.packages.outdated', [['name' => 'laravel/framework', 'installed' => '13.30.1', 'latest' => '13.38.0']])
            ->assertJsonPath('checks.packages.frameworkVersion', ['installed' => $this->app->version(), 'latest' => '13.38.0', 'updateAvailable' => true]);

        self::assertNotNull($response->json('checks.packages.refreshedAt'));

        $this->app['config']->set('ledge.update_available_status', 'degraded');
        $this->app->forgetInstance(Settings::class);
        $this->app->forgetInstance(HealthService::class);

        $this->health()
            ->assertJsonPath('checks.packages.status', 'degraded')
            ->assertJsonPath('checks.packages.output', '1 package(s) have updates available');
    }

    public function test_refresh_is_scheduled_daily_by_default(): void
    {
        $events = array_map(fn ($event): string => (string) $event->command, $this->app->make(Schedule::class)->events());

        self::assertCount(1, array_filter($events, fn (string $command): bool => str_contains($command, 'ledge:packages:refresh')));
    }

    #[DefineEnvironment('autoRefreshOff')]
    public function test_scheduling_can_be_turned_off(): void
    {
        $events = array_map(fn ($event): string => (string) $event->command, $this->app->make(Schedule::class)->events());

        self::assertCount(0, array_filter($events, fn (string $command): bool => str_contains($command, 'ledge:packages:refresh')));
    }

    /**
     * @param  list<array{name: string, version: string}>  $packages
     */
    private function fakeInstalled(array $packages): void
    {
        $this->app->instance(DependenciesService::class, new class($packages, $this->app->basePath()) extends DependenciesService
        {
            /** @param list<array{name: string, version: string}> $packages */
            public function __construct(private readonly array $packages, string $basePath)
            {
                parent::__construct($basePath);
            }

            public function getPackages(): array
            {
                return $this->packages;
            }
        });

        $this->app->make(PackagesStore::class)->forget();
    }

    /**
     * @param  list<string>  $versions
     */
    private function p2(string $name, array $versions): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'minified' => 'composer/2.0',
            'packages' => [$name => array_map(fn (string $v): array => ['version' => $v], $versions)],
        ]));
    }
}
