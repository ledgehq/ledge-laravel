<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use LedgeHQ\Ledge\Acquire\TempWorkspace;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TempWorkspaceTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir().'/ledge-workspace-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @rmdir($this->baseDir);
    }

    public function test_cleanup_removes_workspace_on_success(): void
    {
        $workspace = new TempWorkspace($this->baseDir, 'run-1');
        $workspace->create();
        file_put_contents($workspace->path('dump.sql'), 'data');
        mkdir($workspace->path('nested'));
        file_put_contents($workspace->path('nested/file.txt'), 'data');

        $workspace->cleanup();

        self::assertDirectoryDoesNotExist($workspace->getDir());
    }

    public function test_workspace_is_private_to_the_current_user(): void
    {
        $workspace = new TempWorkspace($this->baseDir, 'run-perms');
        $workspace->create();

        self::assertSame('700', substr(sprintf('%o', (int) fileperms($workspace->getDir())), -3));

        $workspace->cleanup();
    }

    public function test_cleanup_runs_after_failure(): void
    {
        $workspace = new TempWorkspace($this->baseDir, 'run-2');
        $workspace->create();
        file_put_contents($workspace->path('bundle.tar.gz'), 'partial');

        try {
            try {
                throw new RuntimeException('dump blew up mid-run');
            } finally {
                $workspace->cleanup();
            }
        } catch (RuntimeException) {
        }

        self::assertDirectoryDoesNotExist($workspace->getDir());
    }

    public function test_cleanup_removes_tracked_external_files(): void
    {
        $external = $this->baseDir.'-external.sql';
        file_put_contents($external, 'dump');

        $workspace = new TempWorkspace($this->baseDir, 'run-3');
        $workspace->create();
        $workspace->trackFile($external);

        $workspace->cleanup();

        self::assertFileDoesNotExist($external);
        self::assertDirectoryDoesNotExist($workspace->getDir());
    }

    public function test_cleanup_is_safe_when_workspace_was_never_created(): void
    {
        $workspace = new TempWorkspace($this->baseDir, 'run-4');

        $workspace->cleanup();

        self::assertDirectoryDoesNotExist($workspace->getDir());
    }
}
