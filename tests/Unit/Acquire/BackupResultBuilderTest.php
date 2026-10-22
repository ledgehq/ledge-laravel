<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use LedgeHQ\Ledge\Acquire\BackupResultBuilder;
use LedgeHQ\Ledge\Acquire\BundleResult;
use PHPUnit\Framework\TestCase;

final class BackupResultBuilderTest extends TestCase
{
    public function test_payload_reports_richer_backup_metrics(): void
    {
        $payload = (new BackupResultBuilder)->payload(new BundleResult('/tmp/bundle.enc', 4096, 'deadbeef', 20000), 1234, 87);

        self::assertSame([
            'size' => 4096,
            'sha256' => 'deadbeef',
            'compressed_size' => 4096,
            'uncompressed_size' => 20000,
            'dump_duration_ms' => 1234,
            'table_count' => 87,
        ], $payload);
    }

    public function test_payload_tolerates_unknown_table_count(): void
    {
        $payload = (new BackupResultBuilder)->payload(new BundleResult('/tmp/bundle.enc', 10, 'abc', 20), 5, null);

        self::assertNull($payload['table_count']);
    }
}
