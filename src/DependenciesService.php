<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge;

use Composer\InstalledVersions;
use Throwable;

/**
 * Installed package inventory for Ledge's security-advisory matching.
 *
 * Sourced strictly from Composer's runtime data (InstalledVersions), never a
 * lockfile: it reflects what is actually deployed, and on a production
 * `composer install --no-dev` the dev dependencies simply are not present.
 *
 * The lockfile is hashed separately (`getComposerLockHash()`) for a different
 * question: whether what is deployed matches what is committed to the repo.
 */
class DependenciesService
{
    public function __construct(private readonly string $basePath) {}

    /**
     * @return array<int, array{name: string, version: string}>
     */
    public function getPackages(): array
    {
        $installed = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $installed[$name] = InstalledVersions::getPrettyVersion($name);
        }

        return $this->normalizePackages($installed, InstalledVersions::getRootPackage()['name']);
    }

    /**
     * Root package dropped, versionless entries dropped, names lowercased,
     * leading `v` stripped, sorted by name. Must stay byte-identical to the
     * Craft plugin's normalization so `dependenciesHash` means the same thing.
     *
     * @param  array<string, string|null>  $installed  package name => pretty version
     * @return array<int, array{name: string, version: string}>
     */
    public function normalizePackages(array $installed, ?string $rootPackage): array
    {
        $packages = [];

        foreach ($installed as $name => $version) {
            if ($name === $rootPackage || $version === null) {
                continue;
            }

            $packages[] = [
                'name' => strtolower($name),
                'version' => ltrim($version, 'v'),
            ];
        }

        usort($packages, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $packages;
    }

    /**
     * @param  array<int, array{name: string, version: string}>  $packages
     */
    public function getHash(array $packages): string
    {
        return hash('sha256', (string) json_encode($packages));
    }

    public function getCurrentHash(): ?string
    {
        try {
            return $this->getHash($this->getPackages());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * SHA-256 of the raw bytes of the project's composer.lock. Null when the
     * file is absent or unreadable; that means "unknown", not "changed".
     */
    public function getComposerLockHash(?string $path = null): ?string
    {
        try {
            $path ??= $this->basePath.DIRECTORY_SEPARATOR.'composer.lock';

            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $hash = hash_file('sha256', $path);

            return $hash === false ? null : $hash;
        } catch (Throwable) {
            return null;
        }
    }
}
