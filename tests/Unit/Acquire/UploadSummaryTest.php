<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use LedgeHQ\Ledge\Acquire\UploadAttempt;
use LedgeHQ\Ledge\Acquire\UploadSummary;
use PHPUnit\Framework\TestCase;

final class UploadSummaryTest extends TestCase
{
    public function test_s3_error_line(): void
    {
        $attempt = self::attempt(2, status: 503, errorCode: 'SlowDown', requestId: 'abc123', retryAfter: '1', durationMs: 412, bytesSent: 0);

        $this->assertSame(
            'attempt 2/5: HTTP 503 SlowDown, request-id abc123, 412 ms, sent 0/26214400 bytes, retry-after 1',
            UploadSummary::line($attempt, 5, 26214400),
        );
    }

    public function test_connection_error_line(): void
    {
        $attempt = self::attempt(1, errorType: 'ConnectException', curlErrno: 28, durationMs: 600012, bytesSent: 1048576);

        $this->assertSame(
            'attempt 1/5: ConnectException curl 28, 600012 ms, sent 1048576/26214400 bytes',
            UploadSummary::line($attempt, 5, 26214400),
        );
    }

    public function test_status_and_error_together(): void
    {
        $attempt = self::attempt(3, status: 503, errorCode: 'SlowDown', errorType: 'ConnectException', curlErrno: 28, durationMs: 900);

        $this->assertSame('attempt 3/5: HTTP 503 SlowDown, ConnectException curl 28, 900 ms', UploadSummary::line($attempt, 5, null));
    }

    public function test_optional_parts_are_left_out(): void
    {
        $this->assertSame('attempt 1/5: HTTP 500, 12 ms', UploadSummary::line(self::attempt(1, status: 500, durationMs: 12), 5, 22));
        $this->assertSame('attempt 1/5: no response, 0 ms', UploadSummary::line(self::attempt(1), 5, 22));
        $this->assertSame('attempt 1/5: RuntimeException, 3 ms, sent 7 bytes', UploadSummary::line(self::attempt(1, errorType: 'RuntimeException', durationMs: 3, bytesSent: 7), 5, null));
    }

    public function test_hostile_values_stay_under_the_limit_and_printable(): void
    {
        $hostile = "line\nbreak\x00\x1b[31m".str_repeat('é', 200);
        $attempt = self::attempt(
            99,
            status: 503,
            errorCode: $hostile,
            errorType: $hostile,
            curlErrno: 2147483647,
            requestId: $hostile,
            retryAfter: $hostile,
            durationMs: PHP_INT_MAX,
            bytesSent: PHP_INT_MAX,
        );

        foreach ([UploadSummary::MAX_LENGTH, UploadSummary::MAX_LENGTH - strlen('run_already_finished: ')] as $max) {
            $line = UploadSummary::line($attempt, 99, PHP_INT_MAX, $max);

            $this->assertLessThanOrEqual($max, strlen($line));
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $line);
            $this->assertStringStartsWith('attempt 99/99: HTTP 503 ', $line);
        }
    }

    public function test_cap(): void
    {
        $this->assertSame('short', UploadSummary::cap('short', 10));
        $this->assertSame('abcdefg...', UploadSummary::cap(str_repeat('abcdefghij', 3), 10));
    }

    private static function attempt(
        int $number,
        ?int $status = null,
        ?string $errorCode = null,
        ?string $errorType = null,
        ?int $curlErrno = null,
        ?string $requestId = null,
        ?string $retryAfter = null,
        int $durationMs = 0,
        ?int $bytesSent = null,
    ): UploadAttempt {
        return new UploadAttempt(
            $number,
            '2026-11-24T03:00:07Z',
            $status,
            $errorType,
            $errorType !== null ? "{$errorType}: message" : null,
            $curlErrno,
            $errorCode,
            $requestId,
            $requestId !== null ? ['x-amz-request-id' => $requestId] : null,
            $retryAfter,
            $durationMs,
            $bytesSent,
            null,
            null,
        );
    }
}
