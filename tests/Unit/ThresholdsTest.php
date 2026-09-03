<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit;

use LedgeHQ\Ledge\Checks\CheckResult;
use LedgeHQ\Ledge\Checks\Thresholds;
use PHPUnit\Framework\TestCase;

final class ThresholdsTest extends TestCase
{
    public function test_tiers_are_inclusive(): void
    {
        self::assertSame(CheckResult::STATUS_HEALTHY, Thresholds::status(79, 80, 90));
        self::assertSame(CheckResult::STATUS_DEGRADED, Thresholds::status(80, 80, 90));
        self::assertSame(CheckResult::STATUS_DEGRADED, Thresholds::status(89, 80, 90));
        self::assertSame(CheckResult::STATUS_UNHEALTHY, Thresholds::status(90, 80, 90));
    }

    public function test_null_tier_is_disabled(): void
    {
        self::assertSame(CheckResult::STATUS_HEALTHY, Thresholds::status(85, null, 90));
        self::assertSame(CheckResult::STATUS_DEGRADED, Thresholds::status(95, 80, null));
        self::assertSame(CheckResult::STATUS_HEALTHY, Thresholds::status(100, null, null));
    }

    public function test_worst(): void
    {
        self::assertSame(CheckResult::STATUS_UNHEALTHY, Thresholds::worst(CheckResult::STATUS_DEGRADED, CheckResult::STATUS_UNHEALTHY));
        self::assertSame(CheckResult::STATUS_DEGRADED, Thresholds::worst(CheckResult::STATUS_DEGRADED, CheckResult::STATUS_HEALTHY));
        self::assertSame(CheckResult::STATUS_HEALTHY, Thresholds::worst(CheckResult::STATUS_HEALTHY, CheckResult::STATUS_HEALTHY));
    }

    public function test_describe(): void
    {
        self::assertSame(['degradedAt' => 80, 'unhealthyAt' => null], Thresholds::describe(80, null));
    }
}
