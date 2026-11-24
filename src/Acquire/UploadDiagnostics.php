<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

/**
 * What the uploader knows about an upload so far: every attempt made,
 * including a final successful one.
 */
class UploadDiagnostics
{
    /**
     * @param  UploadAttempt[]  $attempts
     */
    public function __construct(
        public readonly string $host,
        public readonly ?int $size,
        public readonly int $maxAttempts,
        public readonly int $durationMs,
        public readonly array $attempts,
    ) {}

    public function attemptCount(): int
    {
        return count($this->attempts);
    }

    public function lastAttempt(): ?UploadAttempt
    {
        return $this->attempts === [] ? null : $this->attempts[count($this->attempts) - 1];
    }

    /**
     * Summary line of the last attempt if it failed, else null.
     */
    public function lastFailureSummary(int $maxLength = UploadSummary::MAX_LENGTH): ?string
    {
        $last = $this->lastAttempt();

        if ($last === null || $last->succeeded()) {
            return null;
        }

        return UploadSummary::line($last, $this->maxAttempts, $this->size, $maxLength);
    }
}
