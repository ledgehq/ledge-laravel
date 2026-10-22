<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

class BundleResult
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly string $sha256,
        public readonly int $uncompressedSize,
    ) {}
}
