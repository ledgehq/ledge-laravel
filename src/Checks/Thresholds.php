<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

/**
 * Shared "value against a degraded/unhealthy pair" evaluation. A null tier
 * is disabled: a check with `degradedAt: null` never reports degraded.
 */
final class Thresholds
{
    public static function status(int|float $value, ?int $degradedAt, ?int $unhealthyAt): string
    {
        if ($unhealthyAt !== null && $value >= $unhealthyAt) {
            return CheckResult::STATUS_UNHEALTHY;
        }

        if ($degradedAt !== null && $value >= $degradedAt) {
            return CheckResult::STATUS_DEGRADED;
        }

        return CheckResult::STATUS_HEALTHY;
    }

    /**
     * @return array{degradedAt: ?int, unhealthyAt: ?int}
     */
    public static function describe(?int $degradedAt, ?int $unhealthyAt): array
    {
        return ['degradedAt' => $degradedAt, 'unhealthyAt' => $unhealthyAt];
    }

    public static function worst(string $a, string $b): string
    {
        $priority = [
            CheckResult::STATUS_HEALTHY => 0,
            CheckResult::STATUS_DEGRADED => 1,
            CheckResult::STATUS_UNHEALTHY => 2,
        ];

        return ($priority[$b] ?? 0) > ($priority[$a] ?? 0) ? $b : $a;
    }
}
