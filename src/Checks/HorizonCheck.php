<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Container\Container;
use LedgeHQ\Ledge\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Horizon master supervisor state, when laravel/horizon is installed. No
 * master running means no workers are processing jobs; a paused master is
 * draining. Apps without Horizon omit the check.
 */
class HorizonCheck implements CheckInterface
{
    public const MASTER_REPOSITORY = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

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
            'thresholds' => ['stoppedStatus' => $stoppedStatus, 'pausedStatus' => $pausedStatus],
        ];

        try {
            $repository = $this->container->make(self::MASTER_REPOSITORY);
            $masters = is_object($repository) && method_exists($repository, 'all') ? $repository->all() : [];
            $masters = is_iterable($masters) ? iterator_to_array($masters, false) : [];
        } catch (Throwable $e) {
            $this->logger->error('Ledge horizon check failed: '.$e->getMessage());

            return CheckResult::degraded($this->getName(), $meta, 'Horizon state unavailable');
        }

        $meta['masters'] = count($masters);
        $meta['paused'] = count(array_filter($masters, fn (mixed $master): bool => is_object($master) && ($master->status ?? null) === 'paused'));

        if ($masters === []) {
            return new CheckResult($this->getName(), $stoppedStatus, $meta, 'Horizon is not running');
        }

        if ($meta['paused'] > 0) {
            return new CheckResult($this->getName(), $pausedStatus, $meta, "{$meta['paused']} Horizon master(s) paused");
        }

        return CheckResult::healthy($this->getName(), $meta);
    }
}
