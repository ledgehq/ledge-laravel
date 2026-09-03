<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

class DiskSpaceCheck implements CheckInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'disk';
    }

    public function run(): ?CheckResult
    {
        try {
            $path = $this->app->storagePath();
            $totalSpace = @disk_total_space($path);
            $freeSpace = @disk_free_space($path);

            if ($totalSpace === false || $freeSpace === false || $totalSpace < 1) {
                return CheckResult::unhealthy($this->getName(), [], 'Unable to determine disk space');
            }

            $usedPercent = (int) round((($totalSpace - $freeSpace) / $totalSpace) * 100);
            $degradedAt = $this->settings->diskDegradedAt;
            $unhealthyAt = $this->settings->diskUnhealthyAt;
            $minFreeBytes = $this->settings->diskMinFreeBytes;

            $meta = [
                'usedPercent' => $usedPercent,
                'freeBytes' => $freeSpace,
                'totalBytes' => $totalSpace,
                'thresholds' => Thresholds::describe($degradedAt, $unhealthyAt) + ['minFreeBytes' => $minFreeBytes],
            ];

            if ($minFreeBytes !== null && $freeSpace < $minFreeBytes) {
                return CheckResult::unhealthy($this->getName(), $meta, "Only {$freeSpace} bytes free (minimum: {$minFreeBytes})");
            }

            $status = Thresholds::status($usedPercent, $degradedAt, $unhealthyAt);

            return match ($status) {
                CheckResult::STATUS_UNHEALTHY => CheckResult::unhealthy($this->getName(), $meta, "Disk usage at {$usedPercent}% (threshold: {$unhealthyAt}%)"),
                CheckResult::STATUS_DEGRADED => CheckResult::degraded($this->getName(), $meta, "Disk usage approaching threshold at {$usedPercent}%"),
                default => CheckResult::healthy($this->getName(), $meta),
            };
        } catch (Throwable $e) {
            $this->logger->error('Ledge disk check failed: '.$e->getMessage());

            return CheckResult::degraded($this->getName(), [], 'Check unavailable');
        }
    }
}
