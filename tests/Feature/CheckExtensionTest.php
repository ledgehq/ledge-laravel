<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Checks\CheckInterface;
use LedgeHQ\Ledge\Checks\CheckResult;
use LedgeHQ\Ledge\Checks\SpatieHealthCheck;
use LedgeHQ\Ledge\Facades\Ledge;
use LedgeHQ\Ledge\LedgeServiceProvider;
use LedgeHQ\Ledge\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;

final class CheckExtensionTest extends TestCase
{
    protected function configuredChecks(Application $app): void
    {
        $app['config']->set('ledge.checks', [ConfiguredCheck::class, 'Nope\\Missing', self::class]);
    }

    #[DefineEnvironment('configuredChecks')]
    public function test_checks_listed_in_config_are_registered_and_invalid_entries_skipped(): void
    {
        $this->health()
            ->assertJsonPath('checks.configured.status', 'degraded')
            ->assertJsonPath('checks.configured.source', 'config');

        self::assertArrayHasKey('configured', Ledge::checks());
        self::assertCount(count(LedgeServiceProvider::DEFAULT_CHECKS) + 1, Ledge::checks());
    }

    public function test_closure_checks_can_be_registered_inline(): void
    {
        Ledge::check('search', fn (string $name): CheckResult => CheckResult::healthy($name, ['documents' => 12]));
        Ledge::check('silent', fn (): ?CheckResult => null);

        $this->health()
            ->assertJsonPath('checks.search.status', 'healthy')
            ->assertJsonPath('checks.search.documents', 12)
            ->assertJsonMissingPath('checks.silent');
    }

    public function test_spatie_health_checks_are_mapped_onto_ledge_statuses(): void
    {
        Ledge::register(SpatieHealthCheck::wrap($this->spatie(fn (Result $r) => $r->ok()->meta(['used' => 40]))));
        Ledge::register(SpatieHealthCheck::wrap($this->spatie(fn (Result $r) => $r->warning('Getting full')), 'warn'));
        Ledge::register(SpatieHealthCheck::wrap($this->spatie(fn (Result $r) => $r->failed('Disk full')), 'fail'));
        Ledge::register(SpatieHealthCheck::wrap($this->spatie(function (Result $r): Result {
            $r->status = Status::crashed();

            return $r;
        }), 'crash'));
        Ledge::register(SpatieHealthCheck::wrap($this->spatie(function (Result $r): Result {
            $r->status = Status::skipped();

            return $r;
        }), 'skip'));
        Ledge::register(SpatieHealthCheck::wrap($this->spatie(fn () => throw new \RuntimeException('boom')), 'throws'));

        $response = $this->health();

        $response->assertJsonPath('checks.fake.status', 'healthy')
            ->assertJsonPath('checks.fake.used', 40)
            ->assertJsonPath('checks.fake.source', 'spatie/laravel-health')
            ->assertJsonPath('checks.warn.status', 'degraded')
            ->assertJsonPath('checks.warn.output', 'Getting full')
            ->assertJsonPath('checks.fail.status', 'unhealthy')
            ->assertJsonPath('checks.fail.output', 'Disk full')
            ->assertJsonPath('checks.crash.status', 'unhealthy')
            ->assertJsonMissingPath('checks.skip')
            ->assertJsonPath('checks.throws.status', 'unhealthy')
            ->assertJsonPath('checks.throws.output', 'boom');
    }

    /**
     * @param  callable(Result): mixed  $build
     */
    private function spatie(callable $build): Check
    {
        return new class($build) extends Check
        {
            /** @var callable */
            private $build;

            public function __construct(callable $build)
            {
                parent::__construct();
                $this->build = $build;
                $this->name = 'Fake';
            }

            public function run(): Result
            {
                $result = Result::make();
                $built = ($this->build)($result);

                return $built instanceof Result ? $built : $result;
            }
        };
    }
}

final class ConfiguredCheck implements CheckInterface
{
    public function getName(): string
    {
        return 'configured';
    }

    public function run(): ?CheckResult
    {
        return CheckResult::degraded($this->getName(), ['source' => 'config']);
    }
}
