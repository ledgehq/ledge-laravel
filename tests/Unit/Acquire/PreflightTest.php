<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\Preflight;
use PHPUnit\Framework\TestCase;

final class PreflightTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/ledge-preflight-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tmpDir);
    }

    public function test_passes_when_everything_is_available(): void
    {
        $this->expectNotToPerformAssertions();

        $this->preflight()->run();
    }

    public function test_fails_when_dump_command_is_not_resolvable(): void
    {
        $this->assertFailsWith('no_dump_command', $this->preflight(dumpAvailable: fn (): bool => false));
    }

    public function test_fails_when_tmp_dir_is_missing(): void
    {
        $this->assertFailsWith('tmp_not_writable', $this->preflight(tmpDir: $this->tmpDir.'/does-not-exist'));
    }

    public function test_fails_when_disk_headroom_is_insufficient(): void
    {
        $this->assertFailsWith('insufficient_disk', $this->preflight(freeBytes: fn (string $dir): int => 100, estimatedDumpBytes: 1000));
    }

    public function test_fails_when_free_space_cannot_be_determined(): void
    {
        $this->assertFailsWith('insufficient_disk', $this->preflight(freeBytes: fn (string $dir): bool => false));
    }

    public function test_fails_when_sodium_is_unavailable(): void
    {
        $this->assertFailsWith('sodium_unavailable', $this->preflight(sodiumAvailable: false));
    }

    public function test_requires_twice_the_estimated_dump_size(): void
    {
        $this->assertFailsWith('insufficient_disk', $this->preflight(freeBytes: fn (string $dir): int => 1999, estimatedDumpBytes: 1000));

        $this->preflight(freeBytes: fn (string $dir): int => 2000, estimatedDumpBytes: 1000)->run();
    }

    public function test_fails_when_dump_size_cannot_be_estimated(): void
    {
        $this->assertFailsWith('insufficient_disk', $this->preflight(estimatedDumpBytes: null));
    }

    private function preflight(
        ?callable $dumpAvailable = null,
        ?callable $freeBytes = null,
        ?int $estimatedDumpBytes = 1000,
        ?string $tmpDir = null,
        bool $sodiumAvailable = true,
    ): Preflight {
        return new Preflight(
            $dumpAvailable !== null ? $dumpAvailable(...) : fn (): bool => true,
            $freeBytes !== null ? $freeBytes(...) : fn (string $dir): int => PHP_INT_MAX,
            $estimatedDumpBytes,
            $tmpDir ?? $this->tmpDir,
            $sodiumAvailable,
        );
    }

    private function assertFailsWith(string $reason, Preflight $preflight): void
    {
        try {
            $preflight->run();
            self::fail("Expected AcquireException with reason \"{$reason}\"");
        } catch (AcquireException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
