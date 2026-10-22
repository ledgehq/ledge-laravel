<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Foundation\CachesRoutes;
use LedgeHQ\Ledge\Settings;

/**
 * Whether `php artisan optimize` (or the individual cache commands) has run.
 * Config and route caches are what matter for request performance, so only
 * those trip the status, and only when APP_ENV is production. The event
 * cache is reported for information.
 */
class OptimizedCheck implements CheckInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly Settings $settings,
    ) {}

    public function getName(): string
    {
        return 'optimized';
    }

    public function run(): ?CheckResult
    {
        $production = $this->app->environment('production');
        $status = $this->settings->notOptimizedStatus;

        $config = $this->app instanceof CachesConfiguration ? $this->app->configurationIsCached() : null;
        $routes = $this->app instanceof CachesRoutes ? $this->app->routesAreCached() : null;
        $events = method_exists($this->app, 'eventsAreCached') ? (bool) $this->app->eventsAreCached() : null;

        $meta = [
            'environment' => $this->app->environment(),
            'configCached' => $config,
            'routesCached' => $routes,
            'eventsCached' => $events,
            'thresholds' => ['notOptimizedStatus' => $status, 'enforcedIn' => 'production'],
        ];

        $missing = array_keys(array_filter(['config' => $config === false, 'routes' => $routes === false]));

        if ($production && $missing !== [] && $status !== CheckResult::STATUS_HEALTHY) {
            return new CheckResult($this->getName(), $status, $meta, 'Not cached: '.implode(', ', $missing));
        }

        return CheckResult::healthy($this->getName(), $meta);
    }
}
