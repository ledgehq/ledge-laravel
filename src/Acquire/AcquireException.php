<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use RuntimeException;

class AcquireException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $httpStatus = 400,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($detail !== null ? "{$reason}: {$detail}" : $reason);
    }
}
