<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Checks\EnvironmentCheck;
use LedgeHQ\Ledge\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class EnvironmentCheckTest extends TestCase
{
    private const EXAMPLE = <<<'ENV'
    APP_NAME=Laravel
    APP_KEY=
    DB_CONNECTION=sqlite
    DB_PASSWORD=
    QUEUE_CONNECTION=database
    AWS_ACCESS_KEY_ID=
    AWS_DEFAULT_REGION=us-east-1
    LEDGE_ACQUIRE_QUEUE=backups
    MAIL_FROM_ADDRESS="hello@example.com"
    ENV;

    private string $dir;

    protected function ignoringRegion(Application $app): void
    {
        $app['config']->set('ledge.ignored_env_vars', ['AWS_DEFAULT_REGION']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/ledge-env-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/.env.example', self::EXAMPLE);
        file_put_contents($this->dir.'/.env', "APP_NAME=Ledge\nAPP_KEY=base64:abc\nDB_CONNECTION=sqlite\nQUEUE_CONNECTION=database\n");

        /** @var \Illuminate\Foundation\Application $app */
        $app = $this->app;
        $app->setBasePath($this->dir);
        $app->useEnvironmentPath($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.env.example');
        @unlink($this->dir.'/.env');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function test_only_keys_with_an_example_value_are_required(): void
    {
        $result = $this->app->make(EnvironmentCheck::class)->run();

        self::assertSame('degraded', $result?->status);
        self::assertSame(['AWS_DEFAULT_REGION', 'MAIL_FROM_ADDRESS'], $result?->meta['missing']);
        self::assertSame(['AWS_ACCESS_KEY_ID', 'DB_PASSWORD'], $result?->meta['optional']);
        self::assertSame(6, $result?->meta['expected']);
        self::assertSame('.env.example', $result?->meta['source']);
        self::assertSame('2 environment variable(s) declared in .env.example but not defined', $result?->output);
    }

    #[DefineEnvironment('ignoringRegion')]
    public function test_ignored_vars_are_not_reported(): void
    {
        $result = $this->app->make(EnvironmentCheck::class)->run();

        self::assertSame(['MAIL_FROM_ADDRESS'], $result?->meta['missing']);
    }

    public function test_the_packages_own_options_are_never_missing(): void
    {
        $result = $this->app->make(EnvironmentCheck::class)->run();

        self::assertNotContains('LEDGE_ACQUIRE_QUEUE', $result?->meta['missing'] ?? []);
        self::assertNotContains('LEDGE_ACQUIRE_QUEUE', $result?->meta['optional'] ?? []);
    }

    public function test_healthy_without_an_example_file(): void
    {
        unlink($this->dir.'/.env.example');

        $result = $this->app->make(EnvironmentCheck::class)->run();

        self::assertSame('healthy', $result?->status);
        self::assertSame([], $result?->meta['missing']);
        self::assertSame(0, $result?->meta['expected']);
        self::assertNull($result?->meta['source']);
    }

    public function test_example_parsing_distinguishes_blank_values(): void
    {
        self::assertSame(
            ['A' => true, 'B' => false, 'C' => false, 'D' => true],
            EnvironmentCheck::parseExample("A=1\nB=\nC=\"\"\nD=\"x y\"\n"),
        );
    }
}
