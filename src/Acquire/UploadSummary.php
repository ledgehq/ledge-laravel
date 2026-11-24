<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

/**
 * One-line summary of a failed upload attempt, used as the callback `reason`
 * (the only field Ledge stores on most events, in a 255-character column):
 *
 *   attempt 2/5: HTTP 503 SlowDown, request-id abc123, 412 ms, sent 0/26214400 bytes, retry-after 1
 *   attempt 1/5: ConnectException curl 28, 600012 ms, sent 1048576/26214400 bytes
 *
 * Parts whose value is unknown are left out. Values that come from the
 * response are reduced to printable ASCII and capped, and the line is cut
 * to $maxLength as a last resort.
 */
class UploadSummary
{
    // Ledge stores reasons in a 255-character column; stay strictly under it.
    public const MAX_LENGTH = 254;

    private const MAX_CODE = 32;

    private const MAX_TYPE = 32;

    private const MAX_REQUEST_ID = 48;

    private const MAX_RETRY_AFTER = 16;

    public static function line(UploadAttempt $attempt, int $maxAttempts, ?int $size, int $maxLength = self::MAX_LENGTH): string
    {
        $parts = [];

        if ($attempt->status !== null) {
            $parts[] = trim("HTTP {$attempt->status} ".self::clean($attempt->errorCode, self::MAX_CODE));
        }

        if ($attempt->errorType !== null) {
            $parts[] = trim(self::clean($attempt->errorType, self::MAX_TYPE).($attempt->curlErrno !== null ? " curl {$attempt->curlErrno}" : ''));
        }

        if ($parts === []) {
            $parts[] = 'no response';
        }

        if ($attempt->requestId !== null) {
            $parts[] = 'request-id '.self::clean($attempt->requestId, self::MAX_REQUEST_ID);
        }

        $parts[] = "{$attempt->durationMs} ms";

        if ($attempt->bytesSent !== null) {
            $parts[] = $size !== null ? "sent {$attempt->bytesSent}/{$size} bytes" : "sent {$attempt->bytesSent} bytes";
        }

        if ($attempt->retryAfter !== null) {
            $parts[] = 'retry-after '.self::clean($attempt->retryAfter, self::MAX_RETRY_AFTER);
        }

        return self::cap("attempt {$attempt->attempt}/{$maxAttempts}: ".implode(', ', $parts), $maxLength);
    }

    /**
     * Cuts $text to $maxLength bytes, marking the cut with "...". Only used on
     * ASCII text, so a byte cut never splits a character.
     */
    public static function cap(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, max(0, $maxLength - 3)).'...';
    }

    private static function clean(?string $value, int $max): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) preg_replace('/[^\x21-\x7E]/', '?', $value);

        return strlen($value) > $max ? substr($value, 0, $max - 3).'...' : $value;
    }
}
