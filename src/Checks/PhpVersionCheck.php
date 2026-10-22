<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Closure;
use LedgeHQ\Ledge\Settings;

/**
 * Support phase of the running PHP branch against php.net's release
 * calendar. `active` is healthy, `security` (bug fixes have stopped) reports
 * `php_security_only_status`, `eol` reports `php_eol_status`. Branches the
 * table does not know are reported as `unknown` and never trip.
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
    ) {}

    public function getName(): string
    {
        return 'phpVersion';
    }

    public function run(): ?CheckResult
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

        $meta = [
            'version' => $version,
            'branch' => $branch,
            'phase' => $phase,
            'activeSupportUntil' => $support[0] ?? null,
            'securitySupportUntil' => $support[1] ?? null,
            'thresholds' => ['securityOnlyStatus' => $securityStatus, 'eolStatus' => $eolStatus],
        ];

        if ($phase === 'eol' && $eolStatus !== CheckResult::STATUS_HEALTHY) {
            return new CheckResult($this->getName(), $eolStatus, $meta, "PHP {$branch} reached end of life on {$support[1]}");
        }

        if ($phase === 'security' && $securityStatus !== CheckResult::STATUS_HEALTHY) {
            return new CheckResult($this->getName(), $securityStatus, $meta, "PHP {$branch} receives security fixes only until {$support[1]}");
        }

        return CheckResult::healthy($this->getName(), $meta);
    }

    private static function endOfDay(string $date): int
    {
        return (int) strtotime($date.' 23:59:59 UTC');
    }
}
