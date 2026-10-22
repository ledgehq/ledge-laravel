<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use LedgeHQ\Ledge\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Failed jobs come from the `failed_jobs` table within `queue_failed_window`.
 * Pending and stale counts are exact on the `database` driver (the jobs
 * table is inspected directly); other drivers report `Queue::size()` for
 * pending and cannot see staleness, so `stale` is null and never trips.
 */
class QueueCheck implements CheckInterface
{
    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $db,
        private readonly QueueFactory $queue,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'queue';
    }

    public function run(): ?CheckResult
    {
        try {
            $connectionName = (string) $this->config->get('queue.default', 'sync');
            $driver = (string) $this->config->get("queue.connections.{$connectionName}.driver", 'sync');
            $staleAfter = $this->settings->queueStaleAfter;

            [$pending, $stale] = $driver === 'database'
                ? $this->inspectDatabaseQueue($connectionName, $staleAfter)
                : [$this->queueSize($connectionName), null];

            $failed = $this->countFailed($this->settings->queueFailedWindow);

            $meta = [
                'driver' => $driver,
                'pending' => $pending,
                'failed' => $failed,
                'stale' => $stale,
                'note' => $stale === null ? "Stale jobs are not observable on the {$driver} driver; see the horizon check when Horizon is installed" : null,
                'thresholds' => [
                    'failed' => Thresholds::describe($this->settings->queueFailedDegradedAt, $this->settings->queueFailedUnhealthyAt) + ['windowSeconds' => $this->settings->queueFailedWindow],
                    'stale' => Thresholds::describe($this->settings->queueStaleDegradedAt, $this->settings->queueStaleUnhealthyAt) + ['ageSeconds' => $staleAfter],
                ],
            ];

            $failedStatus = $failed === null
                ? CheckResult::STATUS_HEALTHY
                : Thresholds::status($failed, $this->settings->queueFailedDegradedAt, $this->settings->queueFailedUnhealthyAt);
            $staleStatus = $stale === null
                ? CheckResult::STATUS_HEALTHY
                : Thresholds::status($stale, $this->settings->queueStaleDegradedAt, $this->settings->queueStaleUnhealthyAt);

            $messages = [];

            if ($failedStatus !== CheckResult::STATUS_HEALTHY) {
                $messages[] = "{$failed} failed job(s)";
            }

            if ($staleStatus !== CheckResult::STATUS_HEALTHY) {
                $minutes = (int) ($staleAfter / 60);
                $messages[] = "{$stale} job(s) waiting longer than {$minutes} minutes";
            }

            return new CheckResult(
                $this->getName(),
                Thresholds::worst($failedStatus, $staleStatus),
                $meta,
                $messages === [] ? null : implode('; ', $messages),
            );
        } catch (Throwable $e) {
            $this->logger->error('Ledge queue check failed: '.$e->getMessage());

            return CheckResult::unhealthy($this->getName(), [], 'Queue check failed');
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function inspectDatabaseQueue(string $connectionName, int $staleAfter): array
    {
        $table = (string) $this->config->get("queue.connections.{$connectionName}.table", 'jobs');
        $dbConnection = $this->config->get("queue.connections.{$connectionName}.connection");
        $retryAfter = (int) $this->config->get("queue.connections.{$connectionName}.retry_after", 90);
        $now = time();

        $query = $this->db->connection(is_string($dbConnection) ? $dbConnection : null)->table($table);

        $pending = (clone $query)->count();

        $stale = (clone $query)
            ->where(function ($q) use ($now, $staleAfter, $retryAfter): void {
                $q->where(fn ($w) => $w->whereNull('reserved_at')->where('available_at', '<=', $now - $staleAfter))
                    ->orWhere(fn ($w) => $w->whereNotNull('reserved_at')->where('reserved_at', '<=', $now - $retryAfter));
            })
            ->count();

        return [$pending, $stale];
    }

    private function queueSize(string $connectionName): ?int
    {
        try {
            return $this->queue->connection($connectionName)->size();
        } catch (Throwable) {
            return null;
        }
    }

    private function countFailed(int $windowSeconds): ?int
    {
        $driver = $this->config->get('queue.failed.driver');

        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return null;
        }

        $dbConnection = $this->config->get('queue.failed.database');
        $table = (string) $this->config->get('queue.failed.table', 'failed_jobs');

        try {
            $connection = $this->db->connection(is_string($dbConnection) ? $dbConnection : null);

            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                return null;
            }

            return $connection->table($table)
                ->where('failed_at', '>=', date('Y-m-d H:i:s', time() - $windowSeconds))
                ->count();
        } catch (Throwable) {
            return null;
        }
    }
}
