<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit;

use LedgeHQ\Ledge\Checks\PhpVersionCheck;
use LedgeHQ\Ledge\Settings;
use PHPUnit\Framework\TestCase;

final class PhpVersionCheckTest extends TestCase
{
    public function test_phases_follow_the_support_calendar(): void
    {
        self::assertSame(['active', 'healthy'], $this->phase('8.3.10', '2025-06-01'));
        self::assertSame(['security', 'degraded'], $this->phase('8.3.10', '2026-10-22'));
        self::assertSame(['eol', 'unhealthy'], $this->phase('8.3.10', '2028-01-01'));
        self::assertSame(['eol', 'unhealthy'], $this->phase('8.1.2', '2026-10-22'));
        self::assertSame(['active', 'healthy'], $this->phase('8.5.0', '2026-10-22'));
        self::assertSame(['unknown', 'healthy'], $this->phase('9.0.0', '2026-10-22'));
    }

    public function test_statuses_are_configurable(): void
    {
        $settings = Settings::fromArray(['php_security_only_status' => 'healthy', 'php_eol_status' => 'degraded']);

        self::assertSame('healthy', (new PhpVersionCheck($settings, '8.3.0', fn (): int => (int) strtotime('2026-10-22')))->run()?->status);
        self::assertSame('degraded', (new PhpVersionCheck($settings, '8.2.0', fn (): int => (int) strtotime('2027-03-01')))->run()?->status);
    }

    /**
     * @return array{0: string, 1: string} phase and status
     */
    private function phase(string $version, string $today): array
    {
        $result = (new PhpVersionCheck(Settings::fromArray([]), $version, fn (): int => (int) strtotime($today.' 12:00:00 UTC')))->run();

        return [$result?->meta['phase'], $result?->status];
    }
}
