<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit;

use LedgeHQ\Ledge\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class SettingsTest extends TestCase
{
    public function test_defaults(): void
    {
        $settings = Settings::fromArray([]);

        self::assertNull($settings->secretKey);
        self::assertSame('X-Ledge-Key', $settings->keyHeader);
        self::assertFalse($settings->allowQueryKey);
        self::assertSame(80, $settings->diskDegradedAt);
        self::assertNull($settings->diskMinFreeBytes);
        self::assertSame(300, $settings->queueStaleAfter);
        self::assertSame('degraded', $settings->debugModeStatus);
        self::assertSame([], $settings->ignoredEnvVars);
        self::assertTrue($settings->isCheckEnabled('anything'));
    }

    public function test_env_strings_are_coerced(): void
    {
        $settings = Settings::fromArray([
            'allow_query_key' => 'true',
            'dependencies_enabled' => '0',
            'disk_degraded_at' => '85',
            'disk_unhealthy_at' => 'off',
            'disk_min_free_bytes' => '',
            'queue_stale_after' => '120',
            'debug_mode_status' => 'UNHEALTHY',
            'ignored_env_vars' => 'FOO, BAR,,',
        ]);

        self::assertTrue($settings->allowQueryKey);
        self::assertFalse($settings->dependenciesEnabled);
        self::assertSame(85, $settings->diskDegradedAt);
        self::assertNull($settings->diskUnhealthyAt);
        self::assertNull($settings->diskMinFreeBytes);
        self::assertSame(120, $settings->queueStaleAfter);
        self::assertSame('unhealthy', $settings->debugModeStatus);
        self::assertSame(['FOO', 'BAR'], $settings->ignoredEnvVars);
    }

    public function test_explicit_null_disables_a_tier_but_not_a_required_option(): void
    {
        $settings = Settings::fromArray(['disk_degraded_at' => null, 'queue_stale_after' => null, 'key_header' => null]);

        self::assertNull($settings->diskDegradedAt);
        self::assertSame(300, $settings->queueStaleAfter);
        self::assertSame('X-Ledge-Key', $settings->keyHeader);
    }

    public function test_invalid_values_are_logged_and_replaced_by_the_default(): void
    {
        $logged = [];
        $logger = new class($logged) extends AbstractLogger
        {
            /** @param list<string> $logged */
            public function __construct(private array &$logged) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }
        };

        $settings = Settings::fromArray([
            'disk_degraded_at' => 150,
            'memory_degraded_at' => -1,
            'queue_stale_after' => 0,
            'debug_mode_status' => 'critical',
            'dependencies_enabled' => 'enabled',
            'failed_logins_degraded_at' => '1.5',
        ], $logger);

        self::assertSame(80, $settings->diskDegradedAt);
        self::assertSame(75, $settings->memoryDegradedAt);
        self::assertSame(300, $settings->queueStaleAfter);
        self::assertSame('degraded', $settings->debugModeStatus);
        self::assertTrue($settings->dependenciesEnabled);
        self::assertSame(10, $settings->failedLoginsDegradedAt);
        self::assertCount(6, $logged);
    }

    public function test_enabled_checks_map_wins_over_disabled_checks_env(): void
    {
        $settings = Settings::fromArray(['enabled_checks' => ['queue' => false, 'disk' => 'true'], 'disabled_checks' => 'memory']);

        self::assertFalse($settings->isCheckEnabled('queue'));
        self::assertTrue($settings->isCheckEnabled('disk'));
        self::assertTrue($settings->isCheckEnabled('memory'));

        $fromEnv = Settings::fromArray(['disabled_checks' => 'memory, queue']);

        self::assertFalse($fromEnv->isCheckEnabled('memory'));
        self::assertFalse($fromEnv->isCheckEnabled('queue'));
        self::assertTrue($fromEnv->isCheckEnabled('disk'));
    }
}
