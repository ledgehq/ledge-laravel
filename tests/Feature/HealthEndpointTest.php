<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Checks\CheckInterface;
use LedgeHQ\Ledge\Checks\CheckResult;
use LedgeHQ\Ledge\Facades\Ledge;
use LedgeHQ\Ledge\LedgeServiceProvider;
use LedgeHQ\Ledge\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class HealthEndpointTest extends TestCase
{
    public function test_rejects_requests_without_a_key(): void
    {
        $this->health([])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'Unauthorized'])
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }

    public function test_rejects_a_wrong_key(): void
    {
        $this->health(['X-Ledge-Key' => 'nope'])->assertStatus(401);
    }

    public function test_ignores_the_query_key_by_default(): void
    {
        $this->getJson('/_ledge/health?key='.self::KEY)->assertStatus(401);
    }

    public function test_returns_the_payload_with_a_valid_key(): void
    {
        $response = $this->health()->assertOk();

        $response->assertJsonStructure(['status', 'platform' => ['name', 'version', 'edition', 'environment'], 'acquireEnabled', 'backupProfileSupported', 'dependenciesHash', 'composerLockHash', 'checks']);
        $response->assertJsonPath('platform.name', 'laravel');
        $response->assertJsonPath('acquireEnabled', false);
        $response->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');

        foreach (LedgeServiceProvider::DEFAULT_CHECKS as $class) {
            $check = $this->app->make($class);

            if ($check->run() === null) {
                continue; // omitted on purpose (redis / horizon not in use here)
            }

            self::assertArrayHasKey($check->getName(), $response->json('checks'), "check '{$check->getName()}' missing from payload");
        }

        $response->assertJsonPath('checks.database.status', 'healthy');
        $response->assertJsonPath('checks.queue.driver', 'sync');
        $response->assertJsonPath('checks.scheduler.lastRunAt', null);
        $response->assertJsonPath('checks.failedLogins.count', 0);
    }

    public function test_reports_503_when_a_check_is_unhealthy(): void
    {
        Ledge::register($this->check('custom', CheckResult::unhealthy('custom', [], 'boom')));

        $this->health()
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('checks.custom.output', 'boom');
    }

    public function test_a_throwing_check_is_reported_not_fatal(): void
    {
        Ledge::register(new class implements CheckInterface
        {
            public function getName(): string
            {
                return 'explodes';
            }

            public function run(): ?CheckResult
            {
                throw new \RuntimeException('kaboom');
            }
        });

        $this->health()
            ->assertStatus(503)
            ->assertJsonPath('checks.explodes.output', 'Check threw an exception');
    }

    public function test_a_null_result_is_omitted(): void
    {
        Ledge::register($this->check('silent', null));

        $this->health()->assertOk()->assertJsonMissingPath('checks.silent');
    }

    #[DefineEnvironment('disableChecksFromEnv')]
    public function test_disabled_checks_env_omits_checks(): void
    {
        $this->health()->assertOk()
            ->assertJsonMissingPath('checks.scheduler')
            ->assertJsonMissingPath('checks.failedLogins')
            ->assertJsonPath('checks.database.status', 'healthy');
    }

    #[DefineEnvironment('allowQueryKey')]
    public function test_query_key_when_enabled(): void
    {
        $this->getJson('/_ledge/health?key='.self::KEY)->assertOk();
        $this->getJson('/_ledge/health?token='.self::KEY)->assertStatus(401);
    }

    #[DefineEnvironment('customHeaderAndPath')]
    public function test_custom_header_and_path(): void
    {
        $this->getJson('/status/ledge', ['X-Monitor-Key' => self::KEY])->assertOk();
        $this->getJson('/status/ledge', ['X-Ledge-Key' => self::KEY])->assertStatus(401);
        $this->getJson('/_ledge/health', ['X-Monitor-Key' => self::KEY])->assertStatus(404);
    }

    #[DefineEnvironment('noSecretKey')]
    public function test_unconfigured_key_is_a_401_with_a_hint(): void
    {
        $this->health()->assertStatus(401)->assertJsonPath('error', 'LEDGE_SECRET_KEY is not configured');
    }

    #[DefineEnvironment('debugOn')]
    public function test_debug_mode_degrades(): void
    {
        $this->health()->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.debugMode.status', 'degraded')
            ->assertJsonPath('checks.debugMode.thresholds.debugModeStatus', 'degraded');
    }

    protected function disableChecksFromEnv(Application $app): void
    {
        $app['config']->set('ledge.disabled_checks', 'scheduler, failedLogins');
    }

    protected function allowQueryKey(Application $app): void
    {
        $app['config']->set('ledge.allow_query_key', 'true');
    }

    protected function customHeaderAndPath(Application $app): void
    {
        $app['config']->set('ledge.key_header', 'X-Monitor-Key');
        $app['config']->set('ledge.health_path', 'status/ledge');
    }

    protected function noSecretKey(Application $app): void
    {
        $this->secretKey = null;
    }

    protected function debugOn(Application $app): void
    {
        $app['config']->set('app.debug', true);
    }

    private function check(string $name, ?CheckResult $result): CheckInterface
    {
        return new class($name, $result) implements CheckInterface
        {
            public function __construct(private readonly string $name, private readonly ?CheckResult $result) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function run(): ?CheckResult
            {
                return $this->result;
            }
        };
    }
}
