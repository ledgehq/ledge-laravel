<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire\Database;

use Illuminate\Database\Connection;
use Throwable;

/**
 * Size and table-count facts about the connection being dumped: the estimate
 * drives preflight's disk-headroom check and the table count is backup
 * telemetry. Both are best-effort and return null when unavailable.
 */
class DatabaseInspector
{
    public function __construct(private readonly Connection $connection) {}

    public function estimateDumpBytes(): ?int
    {
        try {
            $sizes = array_filter(
                array_column($this->connection->getSchemaBuilder()->getTables(), 'size'),
                is_numeric(...),
            );

            if ($sizes !== []) {
                return (int) array_sum($sizes);
            }

            if ($this->connection->getDriverName() === 'sqlite') {
                $path = (string) $this->connection->getConfig('database');
                $size = is_file($path) ? filesize($path) : false;

                return $size === false ? null : $size;
            }
        } catch (Throwable) {
        }

        return null;
    }

    public function countTables(): ?int
    {
        try {
            return count($this->connection->getSchemaBuilder()->getTables());
        } catch (Throwable) {
            return null;
        }
    }
}
