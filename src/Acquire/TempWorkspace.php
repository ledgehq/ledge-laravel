<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class TempWorkspace
{
    private readonly string $dir;

    /** @var list<string> */
    private array $trackedFiles = [];

    public function __construct(string $baseDir, string $runId)
    {
        $this->dir = rtrim($baseDir, '/').'/'.$runId;
    }

    public function create(): void
    {
        $previousUmask = umask(0077);

        try {
            if (! is_dir($this->dir) && ! @mkdir($this->dir, 0700, true)) {
                throw new AcquireException('tmp_not_writable', 500, $this->dir);
            }
        } finally {
            umask($previousUmask);
        }

        // The dump and archive are written into this dir by external callers
        // (the dump binary, gzopen/fopen), so restrict traversal here: 0700
        // denies other local UIDs access to the plaintext DB dump inside,
        // regardless of the individual files' modes.
        @chmod($this->dir, 0700);
    }

    public function getDir(): string
    {
        return $this->dir;
    }

    public function path(string $filename): string
    {
        return $this->dir.'/'.$filename;
    }

    public function trackFile(string $path): void
    {
        $this->trackedFiles[] = $path;
    }

    public function cleanup(): void
    {
        foreach ($this->trackedFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        if (! is_dir($this->dir)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }

            @rmdir($this->dir);
        } catch (Throwable) {
        }
    }
}
