<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Support;

/**
 * Version arithmetic for the packages check. Only stable releases count as
 * candidates, and a package installed from a branch (`dev-main`) or a
 * pre-release is never reported as outdated.
 */
final class PackageVersions
{
    /**
     * Highest stable version among Packagist p2 entries, or null when there
     * is none.
     *
     * @param  list<string>  $versions
     */
    public static function latestStable(array $versions): ?string
    {
        $latest = null;

        foreach ($versions as $version) {
            $normalized = self::normalize($version);

            if ($normalized === null || ! self::isStable($normalized)) {
                continue;
            }

            if ($latest === null || version_compare($normalized, $latest, '>')) {
                $latest = $normalized;
            }
        }

        return $latest;
    }

    public static function isOutdated(string $installed, ?string $latest): bool
    {
        $installed = self::normalize($installed);

        if ($installed === null || $latest === null || ! self::isStable($installed)) {
            return false;
        }

        return version_compare($latest, $installed, '>');
    }

    public static function normalize(string $version): ?string
    {
        $version = trim($version);

        if ($version === '' || str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) {
            return null;
        }

        return ltrim($version, 'vV');
    }

    private static function isStable(string $version): bool
    {
        return preg_match('/^\d+(\.\d+)*$/', $version) === 1;
    }
}
