<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Jobs;

use LedgeHQ\Ledge\Acquire\BackupResultBuilder;
use LedgeHQ\Ledge\Acquire\BundleResult;
use LedgeHQ\Ledge\Acquire\Database\DatabaseInspector;
use LedgeHQ\Ledge\Acquire\TempWorkspace;

/**
 * The `backup` profile: the database and nothing else. No env manifest, no
 * metadata sidecar — `dump.sql` is the whole archive, so the bundle carries
 * zero secret-bearing sidecars. The completed callback reports sizes, dump
 * duration, and table count as operational telemetry.
 */
class BackupJob extends AbstractAcquireJob
{
    protected function buildEntries(TempWorkspace $workspace, string $dumpPath): array
    {
        return ['dump.sql' => $dumpPath];
    }

    protected function completedDetail(BundleResult $result, int $dumpDurationMs, DatabaseInspector $inspector): array
    {
        return (new BackupResultBuilder)->payload($result, $dumpDurationMs, $inspector->countTables());
    }
}
