<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use LedgeHQ\Ledge\Acquire\BackupResultBuilder;
use LedgeHQ\Ledge\Acquire\BundleResult;
use LedgeHQ\Ledge\Acquire\CallbackClient;
use LedgeHQ\Ledge\Acquire\CallbackPayloads;
use LedgeHQ\Ledge\Acquire\UploadAttempt;
use LedgeHQ\Ledge\Acquire\UploadDiagnostics;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CallbackPayloadsTest extends TestCase
{
    private const TIMINGS = ['dump_duration_ms' => 1200, 'encrypt_duration_ms' => 300, 'upload_duration_ms' => 9100];

    public function test_event_names_match_what_ledge_accepts(): void
    {
        $events = (new ReflectionClass(CallbackClient::class))->getConstants();

        $this->assertContains('acquire.upload_retried', $events);
        foreach ($events as $event) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $event);
        }
    }

    public function test_upload_retried_carries_only_the_attempt_that_failed(): void
    {
        $first = self::failed(1, 503, retryInMs: 2000);
        $second = self::failed(2, 503, retryInMs: 6140);

        $payload = CallbackPayloads::uploadRetried(self::diagnostics([$first, $second]));

        $this->assertSame([
            'reason' => 'attempt 2/5: HTTP 503 SlowDown, request-id TX2, 412 ms, sent 0/26214400 bytes, retry-after 1',
            'max_attempts' => 5,
            'retry_in_ms' => 6140,
            'upload_attempts' => 2,
            'upload_attempt' => $second->toArray(),
        ], $payload);
    }

    public function test_upload_failed_after_retries(): void
    {
        $last = self::failed(3, 503);
        $upload = self::diagnostics([self::failed(1, 503, retryInMs: 2000), self::failed(2, 503, retryInMs: 6000), $last], durationMs: 9100);

        $payload = CallbackPayloads::failed('upload_failed', 'upload target responded with HTTP 503 after 3 attempts', self::TIMINGS, 134217728, $upload);

        $this->assertSame([
            'reason' => 'upload_failed: attempt 3/5: HTTP 503 SlowDown, request-id TX3, 412 ms, sent 0/26214400 bytes, retry-after 1',
            'reason_code' => 'upload_failed',
            'detail' => 'upload target responded with HTTP 503 after 3 attempts',
            'upload_attempts' => 3,
            'upload_attempt' => $last->toArray(),
            'dump_duration_ms' => 1200,
            'encrypt_duration_ms' => 300,
            'upload_duration_ms' => 9100,
            'peak_memory_bytes' => 134217728,
        ], $payload);
    }

    public function test_other_upload_codes_keep_the_bare_reason(): void
    {
        foreach ([413 => 'bundle_too_large', 409 => 'run_already_finished'] as $status => $code) {
            $last = self::failed(2, $status);
            $upload = self::diagnostics([self::failed(1, 503, retryInMs: 2000), $last]);

            $payload = CallbackPayloads::failed($code, 'upload target said no after 2 attempts', self::TIMINGS, 1, $upload);

            $this->assertSame($code, $payload['reason']);
            $this->assertSame($code, $payload['reason_code']);
            $this->assertSame('upload target said no after 2 attempts', $payload['detail']);
            $this->assertSame(2, $payload['upload_attempts']);
            $this->assertSame($last->toArray(), $payload['upload_attempt']);
        }
    }

    public function test_upload_reason_is_under255_even_with_hostile_values(): void
    {
        $hostile = str_repeat("x\n", 400);
        $attempt = new UploadAttempt(5, '2026-11-24T03:00:07Z', 503, str_repeat('E', 300), $hostile, PHP_INT_MAX, $hostile, $hostile, null, $hostile, PHP_INT_MAX, PHP_INT_MAX, null, null);
        $upload = new UploadDiagnostics('storage.ledgehq.app', PHP_INT_MAX, 5, 1, [$attempt]);

        $reason = CallbackPayloads::failed('upload_failed', $hostile, [], 1, $upload)['reason'];
        $this->assertLessThan(255, strlen($reason));
        $this->assertStringStartsWith('upload_failed: attempt 5/5: ', $reason);

        $this->assertLessThan(255, strlen(CallbackPayloads::uploadRetried($upload)['reason']));
    }

    public function test_upload_failure_before_any_attempt_uses_the_detail(): void
    {
        $upload = new UploadDiagnostics('storage.ledgehq.app', null, 5, 0, []);

        $payload = CallbackPayloads::failed('upload_failed', 'unable to open bundle for upload', ['upload_duration_ms' => 0], 1, $upload);

        $this->assertSame('upload_failed: unable to open bundle for upload', $payload['reason']);
        $this->assertSame(0, $payload['upload_attempts']);
        $this->assertArrayNotHasKey('upload_attempt', $payload);
    }

    public function test_other_steps_keep_the_bare_code(): void
    {
        $payload = CallbackPayloads::failed('dump_failed', 'mysqldump exited 2', ['dump_duration_ms' => null, 'encrypt_duration_ms' => null, 'upload_duration_ms' => null], 4096);

        $this->assertSame([
            'reason' => 'dump_failed',
            'reason_code' => 'dump_failed',
            'detail' => 'mysqldump exited 2',
            'peak_memory_bytes' => 4096,
        ], $payload);

        $payload = CallbackPayloads::failed('encrypt_failed', null, ['dump_duration_ms' => 800, 'encrypt_duration_ms' => null], 4096);
        $this->assertSame(['reason', 'reason_code', 'detail', 'dump_duration_ms', 'peak_memory_bytes'], array_keys($payload));
        $this->assertSame(800, $payload['dump_duration_ms']);
    }

    public function test_completed_is_the_profile_keys_plus_four_telemetry_keys(): void
    {
        $profile = (new BackupResultBuilder)->payload(new BundleResult('/tmp/b.enc', 4096, 'deadbeef', 20000), 1234, 87);
        $retried = self::diagnostics([self::failed(1, 503, retryInMs: 2000), self::succeeded(2)], durationMs: 3000);

        $payload = CallbackPayloads::completed($profile, ['encrypt_duration_ms' => 210, 'upload_duration_ms' => 3000], 67108864, $retried);

        $this->assertSame([
            'size' => 4096,
            'sha256' => 'deadbeef',
            'compressed_size' => 4096,
            'uncompressed_size' => 20000,
            'dump_duration_ms' => 1234,
            'table_count' => 87,
            'encrypt_duration_ms' => 210,
            'upload_duration_ms' => 3000,
            'upload_attempts' => 2,
            'peak_memory_bytes' => 67108864,
        ], $payload);

        $payload = CallbackPayloads::completed(['size' => 4096, 'sha256' => 'deadbeef'], ['encrypt_duration_ms' => 210, 'upload_duration_ms' => 900], 1, self::diagnostics([self::succeeded(1)]));
        $this->assertSame(['size', 'sha256', 'encrypt_duration_ms', 'upload_duration_ms', 'upload_attempts', 'peak_memory_bytes'], array_keys($payload));
        $this->assertSame(1, $payload['upload_attempts']);
    }

    public function test_a_typical_s3_failure_fits_without_shedding(): void
    {
        $attempt = self::failed(5, 503, body: str_repeat('<Error><Code>SlowDown</Code><Message>Please reduce your request rate.</Message></Error>', 4));
        $upload = self::afterFourFailures($attempt);

        foreach ($this->events($upload, 'upload target responded with HTTP 503 after 5 attempts') as $name => $payload) {
            $this->assertLessThanOrEqual(CallbackPayloads::STORED_DETAIL_BUDGET, CallbackPayloads::storedSize($payload), $name);
            $this->assertSame($attempt->toArray(), $payload['upload_attempt'], $name);
        }
    }

    public function test_worst_case_attempt_is_shed_to_fit_the_budget(): void
    {
        $attempt = self::worstCase('x');
        $upload = self::afterFourFailures($attempt);
        $this->assertGreaterThan(CallbackPayloads::STORED_DETAIL_BUDGET, CallbackPayloads::storedSize(['upload_attempt' => $attempt->toArray()]));

        foreach ($this->events($upload, str_repeat('detail ', 300)) as $name => $payload) {
            $this->assertWithinBudgetAndKeepsTheEssentials($name, $payload);

            // ASCII worst case: shedding body, request_ids and the long strings is enough.
            $this->assertArrayHasKey('upload_attempt', $payload, $name);
            $this->assertNull($payload['upload_attempt']['body'], $name);
            $this->assertSame('TX-'.str_repeat('x', 125), $payload['upload_attempt']['request_id'], $name);
            $this->assertSame(5, $payload['upload_attempt']['attempt'], $name);
        }
    }

    public function test_worst_case_non_ascii_still_fits(): void
    {
        // Every non-ASCII character becomes a six-byte \u escape in Ledge's JSON.
        $upload = self::afterFourFailures(self::worstCase('é'));

        foreach ($this->events($upload, str_repeat('é', 2000)) as $name => $payload) {
            $this->assertWithinBudgetAndKeepsTheEssentials($name, $payload);
        }
    }

    public function test_fit_leaves_small_payloads_alone(): void
    {
        $detail = ['reason' => str_repeat('r', 5000), 'reason_code' => 'upload_failed', 'detail' => 'short'];

        $this->assertSame($detail, CallbackPayloads::fit($detail));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertWithinBudgetAndKeepsTheEssentials(string $name, array $payload): void
    {
        $this->assertLessThanOrEqual(CallbackPayloads::STORED_DETAIL_BUDGET, CallbackPayloads::storedSize($payload), $name);
        $this->assertNotSame('', $payload['reason'], $name);
        $this->assertSame(5, $payload['upload_attempts'], $name);

        if ($name !== 'upload_retried') {
            $this->assertSame($name, $payload['reason_code'], $name);
            $this->assertSame(self::TIMINGS['upload_duration_ms'], $payload['upload_duration_ms'], $name);
            $this->assertSame(self::TIMINGS['dump_duration_ms'], $payload['dump_duration_ms'], $name);
            $this->assertSame(self::TIMINGS['encrypt_duration_ms'], $payload['encrypt_duration_ms'], $name);
            $this->assertSame(PHP_INT_MAX, $payload['peak_memory_bytes'], $name);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function events(UploadDiagnostics $upload, string $detail): array
    {
        return [
            'upload_retried' => CallbackPayloads::uploadRetried($upload),
            'upload_failed' => CallbackPayloads::failed('upload_failed', $detail, self::TIMINGS, PHP_INT_MAX, $upload),
            'bundle_too_large' => CallbackPayloads::failed('bundle_too_large', $detail, self::TIMINGS, PHP_INT_MAX, $upload),
            'run_already_finished' => CallbackPayloads::failed('run_already_finished', $detail, self::TIMINGS, PHP_INT_MAX, $upload),
        ];
    }

    /**
     * Every field the uploader caps, at its cap, built from $char.
     */
    private static function worstCase(string $char): UploadAttempt
    {
        $fill = static fn (int $bytes): string => str_repeat($char, intdiv($bytes, strlen($char)));
        $requestIds = [];

        foreach (['x-amz-request-id', 'x-amz-id-2', 'x-tigris-request-id', 'x-request-id-with-a-long-vendor-name', 'fly-request-id'] as $name) {
            $requestIds[$name] = $fill(UploadAttempt::MAX_REQUEST_ID_BYTES);
        }

        return new UploadAttempt(
            5,
            '2026-11-24T03:00:07Z',
            503,
            'ConnectException',
            $fill(UploadAttempt::MAX_ERROR_BYTES),
            2147483647,
            str_repeat('E', UploadAttempt::MAX_ERROR_CODE_BYTES),
            $char === 'x' ? 'TX-'.str_repeat('x', UploadAttempt::MAX_REQUEST_ID_BYTES - 3) : $fill(UploadAttempt::MAX_REQUEST_ID_BYTES),
            $requestIds,
            $fill(UploadAttempt::MAX_RETRY_AFTER_BYTES),
            PHP_INT_MAX,
            PHP_INT_MAX,
            'fe80:0000:0000:0000:0204:61ff:fe9d:f156%en0',
            $fill(UploadAttempt::MAX_BODY_BYTES),
            60000,
        );
    }

    private static function failed(int $number, int $status, ?int $retryInMs = null, string $body = '<Error><Code>SlowDown</Code></Error>'): UploadAttempt
    {
        return new UploadAttempt(
            $number,
            '2026-11-24T03:00:07Z',
            $status,
            null,
            null,
            null,
            'SlowDown',
            "TX{$number}",
            ['x-amz-request-id' => "TX{$number}", 'x-amz-id-2' => 'host-id-'.str_repeat('a', 60)],
            '1',
            412,
            0,
            '52.216.1.2',
            $body,
            $retryInMs,
        );
    }

    private static function afterFourFailures(UploadAttempt $last): UploadDiagnostics
    {
        return self::diagnostics([self::failed(1, 503, 2000), self::failed(2, 503, 6000), self::failed(3, 503, 18000), self::failed(4, 503, 54000), $last]);
    }

    private static function succeeded(int $number): UploadAttempt
    {
        return new UploadAttempt($number, '2026-11-24T03:00:09Z', 200, null, null, null, null, null, null, null, 900, 26214400, '52.216.1.2', null);
    }

    /**
     * @param  UploadAttempt[]  $attempts
     */
    private static function diagnostics(array $attempts, int $durationMs = 412): UploadDiagnostics
    {
        return new UploadDiagnostics('storage.ledgehq.app', 26214400, 5, $durationMs, $attempts);
    }
}
