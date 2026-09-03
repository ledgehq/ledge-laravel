<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use LedgeHQ\Ledge\Settings;
use LedgeHQ\Ledge\Support\FailedLoginStore;
use Psr\Log\LoggerInterface;
use Throwable;

class FailedLoginsCheck implements CheckInterface
{
    public function __construct(
        private readonly FailedLoginStore $store,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'failedLogins';
    }

    public function run(): ?CheckResult
    {
        try {
            $windowSeconds = $this->settings->failedLoginsWindow;
            $degradedAt = $this->settings->failedLoginsDegradedAt;
            $unhealthyAt = $this->settings->failedLoginsUnhealthyAt;
            $count = $this->store->count($windowSeconds);
            $windowHours = round($windowSeconds / 3600, 1);

            $meta = [
                'count' => $count,
                'window' => "{$windowHours}h",
                'thresholds' => Thresholds::describe($degradedAt, $unhealthyAt) + ['windowSeconds' => $windowSeconds],
            ];

            $status = Thresholds::status($count, $degradedAt, $unhealthyAt);
            $output = $status === CheckResult::STATUS_HEALTHY ? null : "{$count} failed login attempts in the last {$windowHours} hours";

            return new CheckResult($this->getName(), $status, $meta, $output);
        } catch (Throwable $e) {
            $this->logger->error('Ledge failedLogins check failed: '.$e->getMessage());

            return CheckResult::degraded($this->getName(), [], 'Check unavailable');
        }
    }
}
