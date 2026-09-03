<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use LedgeHQ\Ledge\Settings;
use LedgeHQ\Ledge\Support\SchedulerStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Last completion of `schedule:run`, recorded by RecordSchedulerRun. Until a
 * run has been seen the check is healthy with `lastRunAt: null`, since an
 * app with no scheduler is not broken.
 */
class SchedulerCheck implements CheckInterface
{
    public function __construct(
        private readonly SchedulerStore $store,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'scheduler';
    }

    public function run(): ?CheckResult
    {
        try {
            $lastRun = $this->store->lastRunAt();
            $staleAfter = $this->settings->schedulerStaleAfter;
            $staleStatus = $this->settings->schedulerStaleStatus;
            $age = $lastRun === null ? null : max(0, time() - $lastRun);

            $meta = [
                'lastRunAt' => $lastRun === null ? null : date('c', $lastRun),
                'ageSeconds' => $age,
                'thresholds' => ['staleAfter' => $staleAfter, 'staleStatus' => $staleStatus],
            ];

            if ($age !== null && $age > $staleAfter && $staleStatus !== CheckResult::STATUS_HEALTHY) {
                $minutes = (int) round($age / 60);

                return new CheckResult($this->getName(), $staleStatus, $meta, "Scheduler last ran {$minutes} minute(s) ago");
            }

            return CheckResult::healthy($this->getName(), $meta);
        } catch (Throwable $e) {
            $this->logger->error('Ledge scheduler check failed: '.$e->getMessage());

            return CheckResult::degraded($this->getName(), [], 'Check unavailable');
        }
    }
}
