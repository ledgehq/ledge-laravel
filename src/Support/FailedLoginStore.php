<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Support;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Hourly buckets of failed login attempts kept in the cache; the check sums
 * the buckets that overlap its window. Lossy on a cache flush, which is
 * acceptable for a trend signal.
 */
class FailedLoginStore
{
    public const PREFIX = 'ledge:failed_logins:';

    private const BUCKET_SECONDS = 3600;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $retentionSeconds,
    ) {}

    public function record(?int $timestamp = null): void
    {
        $key = self::PREFIX.$this->bucket($timestamp ?? time());

        try {
            $this->cache->add($key, 0, $this->retentionSeconds + self::BUCKET_SECONDS);
            $this->cache->increment($key);
        } catch (Throwable) {
        }
    }

    public function count(int $windowSeconds, ?int $now = null): int
    {
        $now ??= time();
        $keys = [];

        for ($bucket = $this->bucket($now - $windowSeconds); $bucket <= $this->bucket($now); $bucket++) {
            $keys[] = self::PREFIX.$bucket;
        }

        $total = 0;

        foreach ($this->cache->many($keys) as $value) {
            $total += is_numeric($value) ? (int) $value : 0;
        }

        return $total;
    }

    private function bucket(int $timestamp): int
    {
        return intdiv($timestamp, self::BUCKET_SECONDS);
    }
}
