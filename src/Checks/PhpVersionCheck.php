<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Closure;
use LedgeHQ\Ledge\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Support phase of the running PHP branch against php.net's release
 * calendar. `active` is healthy, `security` (bug fixes have stopped) reports
 * `php_security_only_status` (healthy by default), `eol` reports
 * `php_eol_status`. Independently of the phase, a branch within
 * `php_eol_degraded_within_days` / `php_eol_unhealthy_within_days` (180 / 90
 * by default) of its end of life trips that tier; the worse of the two wins,
 * so by default the status follows the time left before end of life rather
 * than the phase. Branches the table does not know are reported as
 * `unknown` and never trip. Mirrors the Craft plugin's `phpVersion` check.
 */
class PhpVersionCheck implements CheckInterface
{
    /**
     * branch => [active support ends, security support ends], per
     * https://www.php.net/supported-versions.php (2025 policy: two years of
     * active support followed by two years of security fixes).
     */
    public const SUPPORT = [
        '8.1' => ['2023-11-25', '2025-12-31'],
        '8.2' => ['2024-12-31', '2026-12-31'],
        '8.3' => ['2025-12-31', '2027-12-31'],
        '8.4' => ['2026-12-31', '2028-12-31'],
        '8.5' => ['2027-12-31', '2029-12-31'],
    ];

    /**
     * @param  (Closure(): int)|null  $now
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ?string $version = null,
        private readonly ?Closure $now = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getName(): string
    {
        return 'phpVersion';
    }

    public function run(): ?CheckResult
    {
        try {
            return $this->evaluate();
        } catch (Throwable $e) {
            $this->logger?->error('Ledge phpVersion check failed: '.$e->getMessage());

            return CheckResult::degraded($this->getName(), [], 'Check unavailable');
        }
    }

    private function evaluate(): CheckResult
    {
        $version = $this->version ?? PHP_VERSION;
        $branch = implode('.', array_slice(explode('.', $version), 0, 2));
        $support = self::SUPPORT[$branch] ?? null;
        $now = $this->now !== null ? ($this->now)() : time();

        $phase = 'unknown';

        if ($support !== null) {
            [$activeUntil, $securityUntil] = $support;
            $phase = match (true) {
                $now > self::endOfDay($securityUntil) => 'eol',
                $now > self::endOfDay($activeUntil) => 'security',
                default => 'active',
            };
        }

        $eolStatus = $this->settings->phpEolStatus;
        $securityStatus = $this->settings->phpSecurityOnlyStatus;
        $degradedWithin = $this->settings->phpEolDegradedWithinDays;
        $unhealthyWithin = $this->settings->phpEolUnhealthyWithinDays;
        $daysUntilEol = $support !== null && $phase !== 'eol'
            ? intdiv(self::endOfDay($support[1]) - $now, 86400)
            : null;

        $meta = [
            'version' => $version,
            'branch' => $branch,
            'phase' => $phase,
            'activeSupportUntil' => $support[0] ?? null,
            'securitySupportUntil' => $support[1] ?? null,
            'daysUntilEol' => $daysUntilEol,
            'thresholds' => [
                'securityOnlyStatus' => $securityStatus,
                'eolStatus' => $eolStatus,
                'eolDegradedWithinDays' => $degradedWithin,
                'eolUnhealthyWithinDays' => $unhealthyWithin,
            ],
        ];

        [$status, $output] = match ($phase) {
            'eol' => [$eolStatus, "PHP {$branch} reached end of life on {$support[1]}"],
            'security' => [$securityStatus, "PHP {$branch} receives security fixes only until {$support[1]}"],
            default => [CheckResult::STATUS_HEALTHY, null],
        };

        if ($daysUntilEol !== null) {
            $approaching = match (true) {
                $unhealthyWithin !== null && $daysUntilEol <= $unhealthyWithin => CheckResult::STATUS_UNHEALTHY,
                $degradedWithin !== null && $daysUntilEol <= $degradedWithin => CheckResult::STATUS_DEGRADED,
                default => CheckResult::STATUS_HEALTHY,
            };

            if (Thresholds::worst($status, $approaching) !== $status) {
                $status = $approaching;
                $output = "PHP {$branch} reaches end of life on {$support[1]} ({$daysUntilEol} days)";
            }
        }

        if ($status === CheckResult::STATUS_HEALTHY) {
            return CheckResult::healthy($this->getName(), $meta);
        }

        return new CheckResult($this->getName(), $status, $meta, $output);
    }

    private static function endOfDay(string $date): int
    {
        return (int) strtotime($date.' 23:59:59 UTC');
    }
}
