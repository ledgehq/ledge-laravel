<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Testing\TestResponse;
use LedgeHQ\Ledge\LedgeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const KEY = 'test-secret-key';

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
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function health(array $headers = ['X-Ledge-Key' => self::KEY]): TestResponse
    {
        return $this->getJson('/_ledge/health', $headers);
    }
}
