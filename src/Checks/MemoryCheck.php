<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use LedgeHQ\Ledge\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

class MemoryCheck implements CheckInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'memory';
    }

    public function run(): ?CheckResult
    {
        try {
            $memoryLimit = $this->getMemoryLimitBytes();
            $memoryUsage = memory_get_usage(true);
            $peakUsage = memory_get_peak_usage(true);

            if ($memoryLimit <= 0) {
                return CheckResult::healthy($this->getName(), [
                    'usageBytes' => $memoryUsage,
                    'peakBytes' => $peakUsage,
                    'limitBytes' => null,
                    'usedPercent' => null,
                ]);
            }

            $degradedAt = $this->settings->memoryDegradedAt;
            $unhealthyAt = $this->settings->memoryUnhealthyAt;
            $usedPercent = (int) round(($memoryUsage / $memoryLimit) * 100);

            $meta = [
                'usageBytes' => $memoryUsage,
                'peakBytes' => $peakUsage,
                'limitBytes' => $memoryLimit,
                'usedPercent' => $usedPercent,
                'thresholds' => Thresholds::describe($degradedAt, $unhealthyAt),
            ];

            $status = Thresholds::status($usedPercent, $degradedAt, $unhealthyAt);
            $output = $status === CheckResult::STATUS_HEALTHY ? null : "Memory usage at {$usedPercent}%";

            return new CheckResult($this->getName(), $status, $meta, $output);
        } catch (Throwable $e) {
            $this->logger->error('Ledge memory check failed: '.$e->getMessage());

            return CheckResult::degraded($this->getName(), [], 'Check unavailable');
        }
    }

    private function getMemoryLimitBytes(): int
    {
        $limit = (string) ini_get('memory_limit');

        if ($limit === '-1' || $limit === '') {
            return -1;
        }

        if (strlen($limit) < 2) {
            return (int) $limit;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => (int) $limit,
        };
    }
}
