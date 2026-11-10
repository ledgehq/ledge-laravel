<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit;

use LedgeHQ\Ledge\Checks\CheckResult;
use LedgeHQ\Ledge\Checks\PhpVersionCheck;
use LedgeHQ\Ledge\Settings;
use PHPUnit\Framework\TestCase;

final class PhpVersionCheckTest extends TestCase
{
    public function test_phases_follow_the_support_calendar(): void
    {
        self::assertSame(['active', 'healthy'], $this->phase('8.3.10', '2025-06-01'));
        self::assertSame(['security', 'healthy'], $this->phase('8.3.10', '2026-10-22'));
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

    public function test_thresholds_echo_the_window(): void
    {
        $result = (new PhpVersionCheck(Settings::fromArray([]), '8.3.0', fn (): int => (int) strtotime('2026-10-22 12:00:00 UTC')))->run();

        self::assertSame([
            'securityOnlyStatus' => 'healthy',
            'eolStatus' => 'unhealthy',
            'eolDegradedWithinDays' => 180,
            'eolUnhealthyWithinDays' => 90,
        ], $result?->meta['thresholds']);
    }

    public function test_security_only_is_healthy_more_than_180_days_before_end_of_life_by_default(): void
    {
        $result = $this->check('8.3.10', '2026-11-10');

        self::assertSame('healthy', $result?->status);
        self::assertNull($result?->output);
        self::assertSame('security', $result?->meta['phase']);
        self::assertSame('2025-12-31', $result?->meta['activeSupportUntil']);
        self::assertSame('2027-12-31', $result?->meta['securitySupportUntil']);
        self::assertSame(416, $result?->meta['daysUntilEol']);

        self::assertSame('healthy', $this->check('8.3.10', '2027-07-03')?->status);
    }

    public function test_degraded_within_180_days_of_end_of_life_by_default(): void
    {
        $result = $this->check('8.3.10', '2027-07-04');

        self::assertSame('degraded', $result?->status);
        self::assertSame(180, $result?->meta['daysUntilEol']);
        self::assertSame('PHP 8.3 reaches end of life on 2027-12-31 (180 days)', $result?->output);
        self::assertSame('security', $result?->meta['phase']);

        self::assertSame('degraded', $this->check('8.3.10', '2027-10-01')?->status);
    }

    public function test_unhealthy_within_90_days_of_end_of_life_by_default(): void
    {
        $result = $this->check('8.3.10', '2027-10-02');

        self::assertSame('unhealthy', $result?->status);
        self::assertSame(90, $result?->meta['daysUntilEol']);
        self::assertSame('PHP 8.3 reaches end of life on 2027-12-31 (90 days)', $result?->output);
    }

    public function test_unhealthy_past_end_of_life_by_default(): void
    {
        $result = $this->check('8.3.10', '2028-01-01');

        self::assertSame('unhealthy', $result?->status);
        self::assertSame('eol', $result?->meta['phase']);
        self::assertNull($result?->meta['daysUntilEol']);
        self::assertSame('PHP 8.3 reached end of life on 2027-12-31', $result?->output);
    }

    public function test_explicit_security_only_status_still_applies(): void
    {
        $result = $this->check('8.3.10', '2026-11-10', ['php_security_only_status' => 'degraded']);

        self::assertSame('degraded', $result?->status);
        self::assertSame('PHP 8.3 receives security fixes only until 2027-12-31', $result?->output);
        self::assertSame('degraded', $result?->meta['thresholds']['securityOnlyStatus']);
    }

    public function test_explicit_null_degraded_window_stays_disabled(): void
    {
        $result = $this->check('8.3.10', '2027-07-04', ['php_eol_degraded_within_days' => null]);

        self::assertSame('healthy', $result?->status);
        self::assertNull($result?->meta['thresholds']['eolDegradedWithinDays']);
    }

    public function test_approaching_end_of_life_is_unhealthy_by_default(): void
    {
        self::assertSame(['security', 'degraded'], $this->phase('8.2.0', '2026-10-01'));
        self::assertSame(['security', 'unhealthy'], $this->phase('8.2.0', '2026-10-02'));

        $result = (new PhpVersionCheck(Settings::fromArray([]), '8.2.0', fn (): int => (int) strtotime('2026-10-28 12:00:00 UTC')))->run();

        self::assertSame(64, $result?->meta['daysUntilEol']);
        self::assertSame('PHP 8.2 reaches end of life on 2026-12-31 (64 days)', $result?->output);
    }

    public function test_days_until_eol_is_null_for_unknown_and_eol_branches(): void
    {
        $now = fn (): int => (int) strtotime('2026-10-28 12:00:00 UTC');

        self::assertNull((new PhpVersionCheck(Settings::fromArray([]), '9.0.0', $now))->run()?->meta['daysUntilEol']);
        self::assertNull((new PhpVersionCheck(Settings::fromArray([]), '8.1.0', $now))->run()?->meta['daysUntilEol']);
    }

    public function test_end_of_life_window_is_configurable(): void
    {
        $settings = Settings::fromArray([
            'php_security_only_status' => 'healthy',
            'php_eol_degraded_within_days' => 180,
            'php_eol_unhealthy_within_days' => null,
        ]);
        $now = fn (): int => (int) strtotime('2026-10-28 12:00:00 UTC');

        self::assertSame('degraded', (new PhpVersionCheck($settings, '8.2.0', $now))->run()?->status);
        self::assertSame('healthy', (new PhpVersionCheck($settings, '8.3.0', $now))->run()?->status);
    }

    public function test_window_tiers_turn_off_from_env_strings(): void
    {
        $settings = Settings::fromArray(['php_eol_unhealthy_within_days' => 'off']);

        self::assertNull($settings->phpEolUnhealthyWithinDays);
        self::assertSame('degraded', (new PhpVersionCheck($settings, '8.2.0', fn (): int => (int) strtotime('2026-12-30 12:00:00 UTC')))->run()?->status);
    }

    public function test_window_does_not_override_a_worse_phase_status(): void
    {
        $settings = Settings::fromArray(['php_security_only_status' => 'unhealthy', 'php_eol_unhealthy_within_days' => null, 'php_eol_degraded_within_days' => 90]);
        $result = (new PhpVersionCheck($settings, '8.2.0', fn (): int => (int) strtotime('2026-10-28 12:00:00 UTC')))->run();

        self::assertSame('unhealthy', $result?->status);
        self::assertSame('PHP 8.2 receives security fixes only until 2026-12-31', $result?->output);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function check(string $version, string $today, array $config = []): ?CheckResult
    {
        return (new PhpVersionCheck(Settings::fromArray($config), $version, fn (): int => (int) strtotime($today.' 12:00:00 UTC')))->run();
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
