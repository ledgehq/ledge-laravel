<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\TestResponse;
use LedgeHQ\Ledge\Checks\PhpVersionCheck;
use LedgeHQ\Ledge\LedgeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const KEY = 'test-secret-key';

    /** 2026-01-01 00:00:00 UTC; see isolateFromHost(). */
    public const PINNED_NOW = 1767225600;

    protected ?string $secretKey = self::KEY;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LedgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('ledge.secret_key', $this->secretKey);
        $app['config']->set('database.default', 'testing');

        $this->isolateFromHost($app);
    }

    /**
     * Keeps the suite's results independent of the machine running it. The
     * disk, memory and phpVersion checks still run for real and still report
     * their measurements, but nothing about the host can grade them: a full
     * disk, a tight memory_limit, or a PHP branch nearing end of life would
     * otherwise turn /health into a 503 (or change an asserted status) on
     * one machine and not another. Config set here also overrides any
     * LEDGE_* variables exported in the host environment.
     *
     * ThresholdsTest and PhpVersionCheckTest cover the grading with injected
     * values. A feature test that needs real thresholds sets them in its test
     * body, before the first request: DefineEnvironment attribute methods run
     * before this and would be overwritten.
     */
    protected function isolateFromHost(Application $app): void
    {
        foreach (['disk_degraded_at', 'disk_unhealthy_at', 'disk_min_free_bytes', 'memory_degraded_at', 'memory_unhealthy_at'] as $key) {
            $app['config']->set("ledge.{$key}", null);
        }

        // phpVersion grades PHP_VERSION against the wall clock, so it drifts
        // to degraded/unhealthy as the running branch nears end of life.
        // Pin "now" to a date when every branch this package supports is
        // well inside its support window; the thresholds keep their defaults.
        // `$now` is typed Closure, so the container resolves it by class name.
        $app->when(PhpVersionCheck::class)
            ->needs(Closure::class)
            ->give(fn (): Closure => fn (): int => self::PINNED_NOW);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function health(array $headers = ['X-Ledge-Key' => self::KEY]): TestResponse
    {
        return $this->getJson('/_ledge/health', $headers);
    }
}
