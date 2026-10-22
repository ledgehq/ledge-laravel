<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Checks\HorizonCheck;
use LedgeHQ\Ledge\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class PlatformChecksTest extends TestCase
{
    protected function brokenCacheStore(Application $app): void
    {
        $app['config']->set('cache.default', 'broken');
        // A database store on a connection that has no cache table: the store
        // builds fine and every operation fails at runtime.
        $app['config']->set('cache.stores.broken', ['driver' => 'database', 'connection' => 'testing', 'table' => 'cache_missing']);
    }

    protected function redisQueue(Application $app): void
    {
        $app['config']->set('queue.default', 'redis');
        $app['config']->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']);
        $app['config']->set('database.redis.default', ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.1]);
    }

    public function test_cache_roundtrip_is_healthy_on_the_array_store(): void
    {
        $this->health()
            ->assertJsonPath('checks.cache.status', 'healthy')
            ->assertJsonPath('checks.cache.driver', 'array');
    }

    #[DefineEnvironment('brokenCacheStore')]
    public function test_cache_failure_is_unhealthy(): void
    {
        $this->health()
            ->assertJsonPath('checks.cache.status', 'unhealthy')
            ->assertJsonPath('checks.cache.store', 'broken')
            ->assertJsonPath('checks.cache.output', 'Cache store is unavailable');
    }

    public function test_redis_and_horizon_are_omitted_when_unused(): void
    {
        $this->health()
            ->assertJsonMissingPath('checks.redis')
            ->assertJsonMissingPath('checks.horizon');
    }

    #[DefineEnvironment('redisQueue')]
    public function test_redis_is_checked_when_the_queue_uses_it(): void
    {
        $this->health()
            ->assertJsonPath('checks.redis.status', 'unhealthy')
            ->assertJsonPath('checks.redis.usedBy', ['queue']);
    }

    public function test_horizon_reports_master_supervisors(): void
    {
        $this->bindHorizon([]);
        $this->health()->assertJsonPath('checks.horizon.status', 'unhealthy')->assertJsonPath('checks.horizon.masters', 0);

        $this->bindHorizon([(object) ['status' => 'running'], (object) ['status' => 'paused']]);
        $this->health()->assertJsonPath('checks.horizon.status', 'degraded')->assertJsonPath('checks.horizon.paused', 1);

        $this->bindHorizon([(object) ['status' => 'running']]);
        $this->health()->assertJsonPath('checks.horizon.status', 'healthy')
            ->assertJsonPath('checks.horizon.masters', 1)
            ->assertJsonPath('checks.horizon.supervisors', null)
            ->assertJsonPath('checks.horizon.recentlyFailed', null);
    }

    public function test_horizon_reports_queue_stats_when_available(): void
    {
        $this->bindHorizon([(object) ['status' => 'running']]);
        $this->app->instance(HorizonCheck::SUPERVISOR_REPOSITORY, $this->repository([
            (object) ['processes' => ['default' => 3, 'mail' => 1]],
            (object) ['processes' => ['reports' => 2]],
        ]));
        $this->app->instance(HorizonCheck::WAIT_TIME_CALCULATOR, new class
        {
            /** @return array<string, float> */
            public function calculate(): array
            {
                return ['redis:default' => 4.2, 'redis:mail' => 0.0, 'redis:reports' => 95.0];
            }
        });
        $this->app->instance(HorizonCheck::JOB_REPOSITORY, new class
        {
            public function countRecentlyFailed(): int
            {
                return 7;
            }
        });

        $this->health()
            ->assertJsonPath('checks.horizon.status', 'healthy')
            ->assertJsonPath('checks.horizon.supervisors', 2)
            ->assertJsonPath('checks.horizon.processes', 6)
            ->assertJsonPath('checks.horizon.waitTimes', ['redis:default' => 4, 'redis:mail' => 0, 'redis:reports' => 95])
            ->assertJsonPath('checks.horizon.longestWaitSeconds', 95)
            ->assertJsonPath('checks.horizon.recentlyFailed', 7);
    }

    public function test_queue_notes_when_staleness_is_not_observable(): void
    {
        $this->health()
            ->assertJsonPath('checks.queue.stale', null)
            ->assertJsonPath('checks.queue.note', 'Stale jobs are not observable on the sync driver; see the horizon check when Horizon is installed');
    }

    public function test_optimized_only_trips_in_production(): void
    {
        $this->health()
            ->assertJsonPath('checks.optimized.status', 'healthy')
            ->assertJsonPath('checks.optimized.configCached', false)
            ->assertJsonPath('checks.optimized.routesCached', false);

        $this->app['env'] = 'production';

        $this->health()
            ->assertJsonPath('checks.optimized.status', 'degraded')
            ->assertJsonPath('checks.optimized.output', 'Not cached: config, routes');
    }

    public function test_php_version_is_reported(): void
    {
        $this->health()
            ->assertJsonPath('checks.phpVersion.version', PHP_VERSION)
            ->assertJsonPath('checks.phpVersion.branch', PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
    }

    /**
     * @param  list<object>  $masters
     */
    private function bindHorizon(array $masters): void
    {
        $this->app->instance(HorizonCheck::MASTER_REPOSITORY, $this->repository($masters));
    }

    /**
     * @param  list<object>  $items
     */
    private function repository(array $items): object
    {
        return new class($items)
        {
            /** @param list<object> $items */
            public function __construct(private readonly array $items) {}

            /** @return list<object> */
            public function all(): array
            {
                return $this->items;
            }
        };
    }
}
