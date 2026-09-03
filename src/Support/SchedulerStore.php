<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Support;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

class SchedulerStore
{
    public const KEY = 'ledge:scheduler:last_run';

    public function __construct(private readonly Repository $cache) {}

    public function record(?int $timestamp = null): void
    {
        try {
            $this->cache->forever(self::KEY, $timestamp ?? time());
        } catch (Throwable) {
        }
    }

    public function lastRunAt(): ?int
    {
        $value = $this->cache->get(self::KEY);

        return is_int($value) ? $value : null;
    }
}
