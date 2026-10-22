<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * The last Packagist comparison, written by `ledge:packages:refresh` and
 * read by the `packages` check so a health poll never makes outbound
 * requests. Kept until the next refresh, not TTL'd.
 *
 * @phpstan-type PackageRow array{name: string, installed: string, latest: ?string, updateAvailable: bool}
 * @phpstan-type Snapshot array{refreshedAt: string, packages: list<PackageRow>, errors: int}
 */
class PackagesStore
{
    public const CACHE_KEY = 'ledge.packages';

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @return Snapshot|null
     */
    public function get(): ?array
    {
        $snapshot = $this->cache->get(self::CACHE_KEY);

        return is_array($snapshot) && isset($snapshot['refreshedAt'], $snapshot['packages']) ? $snapshot : null;
    }

    /**
     * @param  list<PackageRow>  $packages
     */
    public function put(array $packages, int $errors, ?int $refreshedAt = null): void
    {
        $this->cache->forever(self::CACHE_KEY, [
            'refreshedAt' => gmdate('c', $refreshedAt ?? time()),
            'packages' => $packages,
            'errors' => $errors,
        ]);
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
