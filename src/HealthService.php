<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Checks\CallbackCheck;
use LedgeHQ\Ledge\Checks\CheckInterface;
use LedgeHQ\Ledge\Checks\CheckResult;
use LedgeHQ\Ledge\Checks\Thresholds;
use Psr\Log\LoggerInterface;
use Throwable;

class HealthService
{
    /** @var array<string, CheckInterface> */
    private array $checks = [];

    public function __construct(
        private readonly Application $app,
        private readonly Settings $settings,
        private readonly DependenciesService $dependencies,
        private readonly AcquireService $acquire,
        private readonly LoggerInterface $logger,
    ) {}

    public function register(CheckInterface $check): void
    {
        $this->checks[$check->getName()] = $check;
    }

    /**
     * Register an inline check. The callback returns a CheckResult, or null to
     * leave the check out of the payload for that run.
     *
     * @param  Closure(string): ?CheckResult  $callback
     */
    public function check(string $name, Closure $callback): void
    {
        $this->register(new CallbackCheck($name, $callback));
    }

    public function forget(string $name): void
    {
        unset($this->checks[$name]);
    }

    /**
     * @return array<string, CheckInterface>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $results = [];
        $overallStatus = CheckResult::STATUS_HEALTHY;

        foreach ($this->checks as $name => $check) {
            if (! $this->settings->isCheckEnabled($name)) {
                continue;
            }

            try {
                $result = $check->run();
            } catch (Throwable $e) {
                $this->logger->error("Ledge check '{$name}' threw: {$e->getMessage()}");
                $result = CheckResult::unhealthy($name, [], 'Check threw an exception');
            }

            if ($result === null) {
                continue;
            }

            $results[$name] = $result->toArray();
            $overallStatus = Thresholds::worst($overallStatus, $result->status);
        }

        $acquireEnabled = $this->acquire->isEnabled();

        $payload = [
            'status' => $overallStatus,
            'platform' => $this->platform(),
            'acquireEnabled' => $acquireEnabled,
            'backupProfileSupported' => $acquireEnabled && $this->acquire->supportsProfile('backup'),
            'capabilities' => $acquireEnabled ? $this->acquire->supportedProfiles() : [],
            'updateTest' => ['strategy' => 'testsuite'],
        ];

        if ($this->settings->dependenciesEnabled) {
            $payload['dependenciesHash'] = $this->dependencies->getCurrentHash();
            $payload['composerLockHash'] = $this->dependencies->getComposerLockHash();
        }

        $payload['checks'] = $results;

        return $payload;
    }

    /**
     * @return array{name: string, version: ?string, edition: ?string, environment: ?string}
     */
    private function platform(): array
    {
        $platform = ['name' => 'laravel', 'version' => null, 'edition' => null, 'environment' => null];

        try {
            $platform['version'] = $this->app->version();
            $platform['environment'] = $this->app->environment();
        } catch (Throwable) {
        }

        return $platform;
    }
}
