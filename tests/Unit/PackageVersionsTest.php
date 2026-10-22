<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit;

use LedgeHQ\Ledge\Support\PackageVersions;
use PHPUnit\Framework\TestCase;

final class PackageVersionsTest extends TestCase
{
    public function test_latest_stable_ignores_prereleases_and_branches(): void
    {
        self::assertSame('13.38.0', PackageVersions::latestStable(['v13.30.1', 'v13.38.0', 'v14.0.0-beta.1', 'v14.0.0-RC1', '13.x-dev', 'dev-main']));
        self::assertNull(PackageVersions::latestStable(['dev-main', '2.0.0-alpha']));
        self::assertNull(PackageVersions::latestStable([]));
    }

    public function test_outdated_compares_stable_versions_only(): void
    {
        self::assertTrue(PackageVersions::isOutdated('v13.30.1', '13.38.0'));
        self::assertFalse(PackageVersions::isOutdated('13.38.0', '13.38.0'));
        self::assertFalse(PackageVersions::isOutdated('14.0.0', '13.38.0'));
        self::assertFalse(PackageVersions::isOutdated('dev-main', '13.38.0'));
        self::assertFalse(PackageVersions::isOutdated('2.0.0-beta.1', '2.0.0'));
        self::assertFalse(PackageVersions::isOutdated('1.0.0', null));
    }
}
