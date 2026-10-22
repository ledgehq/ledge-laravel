<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Container\Container;
use LedgeHQ\Ledge\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Horizon state, when laravel/horizon is installed. The status comes from
 * the master supervisors: none running means no workers are processing
 * jobs; a paused master is draining. Supervisor, process, wait-time, and
 * recent-failure figures are reported for information, since Horizon is
 * the only place they are observable on the redis driver. Apps without
 * Horizon omit the check.
 */
class HorizonCheck implements CheckInterface
{
    public const MASTER_REPOSITORY = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

    public const SUPERVISOR_REPOSITORY = 'Laravel\Horizon\Contracts\SupervisorRepository';

    public const JOB_REPOSITORY = 'Laravel\Horizon\Contracts\JobRepository';

    public const WAIT_TIME_CALCULATOR = 'Laravel\Horizon\WaitTimeCalculator';

    public function __construct(
        private readonly Container $container,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'horizon';
    }

    public function run(): ?CheckResult
    {
        if (! $this->container->bound(self::MASTER_REPOSITORY)) {
            return null;
        }

        $stoppedStatus = $this->settings->horizonStoppedStatus;
        $pausedStatus = $this->settings->horizonPausedStatus;
        $meta = [
            'masters' => 0,
            'paused' => 0,
            'supervisors' => null,
            'processes' => null,
            'waitTimes' => null,
            'longestWaitSeconds' => null,
            'recentlyFailed' => null,
            'thresholds' => ['stoppedStatus' => $stoppedStatus, 'pausedStatus' => $pausedStatus],
        ];

        try {
            $masters = $this->call(self::MASTER_REPOSITORY, 'all');
        } catch (Throwable $e) {
            $this->logger->error('Ledge horizon check failed: '.$e->getMessage());

            return CheckResult::degraded($this->getName(), $meta, 'Horizon state unavailable');
        }

        $meta['masters'] = count($masters);
        $meta['paused'] = count(array_filter($masters, fn (mixed $master): bool => is_object($master) && ($master->status ?? null) === 'paused'));
        $meta = $this->stats($meta);

        if ($masters === []) {
            return new CheckResult($this->getName(), $stoppedStatus, $meta, 'Horizon is not running');
        }

        if ($meta['paused'] > 0) {
            return new CheckResult($this->getName(), $pausedStatus, $meta, "{$meta['paused']} Horizon master(s) paused");
        }

        return CheckResult::healthy($this->getName(), $meta);
    }

    /**
     * Informational figures; each is best-effort and left null on failure so
     * a flaky metric never hides the master status.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function stats(array $meta): array
    {
        try {
            $supervisors = $this->call(self::SUPERVISOR_REPOSITORY, 'all');
            $meta['supervisors'] = count($supervisors);
            $meta['processes'] = array_sum(array_map(
                fn (mixed $supervisor): int => is_object($supervisor) && is_array($supervisor->processes ?? null) ? (int) array_sum($supervisor->processes) : 0,
                $supervisors,
            ));
        } catch (Throwable) {
        }

        try {
            $waits = $this->container->bound(self::WAIT_TIME_CALCULATOR) ? $this->call(self::WAIT_TIME_CALCULATOR, 'calculate') : [];
            $waits = array_map(fn (mixed $seconds): int => (int) $seconds, array_filter($waits, is_numeric(...)));
            $meta['waitTimes'] = $waits;
            $meta['longestWaitSeconds'] = $waits === [] ? 0 : max($waits);
        } catch (Throwable) {
        }

        try {
            if ($this->container->bound(self::JOB_REPOSITORY)) {
                $repository = $this->container->make(self::JOB_REPOSITORY);
                $meta['recentlyFailed'] = is_object($repository) && method_exists($repository, 'countRecentlyFailed')
                    ? (int) $repository->countRecentlyFailed()
                    : null;
            }
        } catch (Throwable) {
        }

        return $meta;
    }

    /**
     * @return array<mixed>
     */
    private function call(string $abstract, string $method): array
    {
        $service = $this->container->make($abstract);

        if (! is_object($service) || ! method_exists($service, $method)) {
            return [];
        }

        $result = $service->{$method}();

        return is_iterable($result) ? iterator_to_array($result, true) : [];
    }
}
