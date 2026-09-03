<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use LedgeHQ\Ledge\Tests\TestCase;

final class GenerateKeyCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = $this->app->environmentFilePath();
        @unlink($this->envPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);

        parent::tearDown();
    }

    public function test_show_prints_without_writing(): void
    {
        $this->artisan('ledge:generate-key', ['--show' => true])
            ->expectsOutputToContain('LEDGE_SECRET_KEY=')
            ->assertSuccessful();

        self::assertFileDoesNotExist($this->envPath);
    }

    public function test_appends_to_env_and_replaces_an_existing_key(): void
    {
        file_put_contents($this->envPath, "APP_NAME=Test\n");

        $this->artisan('ledge:generate-key')->assertSuccessful();

        $contents = (string) file_get_contents($this->envPath);
        self::assertMatchesRegularExpression('/^APP_NAME=Test\n\nLEDGE_SECRET_KEY=[A-Za-z0-9]{32}\n$/', $contents);

        $this->artisan('ledge:generate-key')->assertSuccessful();

        $after = (string) file_get_contents($this->envPath);
        self::assertSame(1, preg_match_all('/^LEDGE_SECRET_KEY=/m', $after));
        self::assertNotSame($contents, $after);
    }
}
