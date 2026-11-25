<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\BundleUploader;
use LedgeHQ\Ledge\Acquire\CallbackPayloads;
use LedgeHQ\Ledge\Acquire\UploadAttempt;
use LedgeHQ\Ledge\Acquire\UploadDiagnostics;
use LedgeHQ\Ledge\Acquire\UploadSummary;
use LedgeHQ\Ledge\Tests\Support\BuildsGuzzleFailures;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

final class BundleUploaderTest extends TestCase
{
    use BuildsGuzzleFailures;

    private const SIGNATURE = '9f3c2b7a1d4e8f60a5b3c9d2e7f1a4b8c6d0e3f5a7b9c1d2e4f6a8b0c2d4e6f8';

    private const URL = 'https://storage.ledgehq.app/api/bundles/run-123?expires=1795000000&signature='.self::SIGNATURE;

    private const QUERY = 'expires=1795000000&signature='.self::SIGNATURE;

    private const CALLBACK_TOKEN = 'cb-token-7f3a9e1c2b4d6f80a1b2';

    private const CONTENT = 'encrypted bundle bytes';

    private const SLOW_DOWN = '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SlowDown</Code><Message>Please reduce your request rate.</Message><RequestId>tx-abc</RequestId></Error>';

    /** @var array<array{request: RequestInterface, options: array}> */
    private array $history = [];

    /** @var string[] */
    private array $bodies = [];

    /** @var int[] */
    private array $sleeps = [];

    private float $now;

    private float $jitter = 1.0;

    private string $bundlePath;

    protected function setUp(): void
    {
        $this->bundlePath = sys_get_temp_dir().'/ledge-upload-'.bin2hex(random_bytes(4));
        file_put_contents($this->bundlePath, self::CONTENT);
        $this->now = (float) strtotime('2026-11-24T03:00:07Z');
    }

    protected function tearDown(): void
    {
        @unlink($this->bundlePath);
    }

    public function test_streams_bundle_via_put(): void
    {
        $diagnostics = $this->uploader([new Response(201, [], '{"ok":true,"bytes":22}')])->upload($this->bundlePath, self::URL);

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $options = $this->history[0]['options'];

        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame(self::URL, (string) $request->getUri());
        $this->assertSame('application/octet-stream', $request->getHeaderLine('Content-Type'));
        $this->assertSame('22', $request->getHeaderLine('Content-Length'));
        $this->assertSame([self::CONTENT], $this->bodies);
        $this->assertSame(600, $options['timeout']);
        $this->assertFalse($options['http_errors']);
        $this->assertFalse($options['allow_redirects']);

        $this->assertSame(1, $diagnostics->attemptCount());
        $this->assertSame([], $this->failedLog($diagnostics));
        $this->assertSame('storage.ledgehq.app', $diagnostics->host);
        $this->assertSame(22, $diagnostics->size);
        $this->assertSame(5, $diagnostics->maxAttempts);
        $this->assertSame([], $this->sleeps);
    }

    public function test_any_twoxx_is_success_and_body_is_ignored(): void
    {
        $uploader = $this->uploader([
            new Response(200),
            new Response(201, [], '{"ok":true}'),
            new Response(204, [], 'not even json'),
        ]);

        $uploader->upload($this->bundlePath, self::URL);
        $uploader->upload($this->bundlePath, self::URL);
        $uploader->upload($this->bundlePath, self::URL);

        $this->assertCount(3, $this->history);
    }

    public function test_transient_failure_then_success_reports_one_failed_attempt(): void
    {
        $diagnostics = $this->uploader([
            new Response(503, ['x-amz-request-id' => 'TX503', 'Retry-After' => '1'], self::SLOW_DOWN),
            new Response(200),
        ])->upload($this->bundlePath, self::URL);

        $this->assertCount(2, $this->history);
        $this->assertSame([self::CONTENT, self::CONTENT], $this->bodies);
        $this->assertSame('22', $this->history[1]['request']->getHeaderLine('Content-Length'));
        $this->assertSame([2000], $this->sleeps);

        $this->assertSame(2, $diagnostics->attemptCount());
        $this->assertSame(2000, $diagnostics->durationMs);

        $log = $this->failedLog($diagnostics);
        $this->assertCount(1, $log);
        $this->assertSame([
            'attempt' => 1,
            'started_at' => '2026-11-24T03:00:07Z',
            'status' => 503,
            'error' => null,
            'curl_errno' => null,
            'error_code' => 'SlowDown',
            'request_id' => 'TX503',
            'request_ids' => ['x-amz-request-id' => 'TX503'],
            'retry_after' => '1',
            'duration_ms' => 0,
            'bytes_sent' => null,
            'remote_ip' => null,
            'body' => self::SLOW_DOWN,
            'retry_in_ms' => 2000,
        ], $log[0]);
    }

    public function test_gives_up_after_max_attempts(): void
    {
        $e = $this->assertFailsWith('upload_failed', array_fill(0, 5, new Response(503)));

        $this->assertSame('upload target responded with HTTP 503 after 5 attempts', $e->detail);
        $this->assertCount(5, $this->history);
        $this->assertSame([2000, 6000, 18000, 54000], $this->sleeps);

        $upload = $this->diagnostics($e);
        $this->assertSame(5, $upload->attemptCount());
        $this->assertSame([2000, 6000, 18000, 54000, null], array_column($this->failedLog($upload), 'retry_in_ms'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($this->failedLog($upload), 'attempt'));
    }

    public function test_max_attempts_is_configurable(): void
    {
        $e = $this->assertFailsWith('upload_failed', [new Response(500), new Response(500)], maxAttempts: 2);

        $this->assertSame('upload target responded with HTTP 500 after 2 attempts', $e->detail);
        $this->assertCount(2, $this->history);
    }

    public function test_terminal_statuses_are_not_retried(): void
    {
        $cases = [
            413 => ['bundle_too_large', 'upload target rejected the bundle size'],
            409 => ['run_already_finished', 'upload target reports the run already finished'],
            403 => ['upload_failed', 'upload target responded with HTTP 403'],
            400 => ['upload_failed', 'upload target responded with HTTP 400'],
            404 => ['upload_failed', 'upload target responded with HTTP 404'],
            301 => ['upload_failed', 'upload target responded with HTTP 301'],
            501 => ['upload_failed', 'upload target responded with HTTP 501'],
            505 => ['upload_failed', 'upload target responded with HTTP 505'],
            507 => ['upload_failed', 'upload target responded with HTTP 507'],
        ];

        foreach ($cases as $status => [$reason, $detail]) {
            $e = $this->assertFailsWith($reason, [new Response($status), new Response(201)]);

            $this->assertSame($detail, $e->detail, "HTTP {$status}");
            $this->assertCount(1, $this->history, "HTTP {$status}");
            $this->assertSame([], $this->sleeps, "HTTP {$status}");
            $this->assertSame($status, $this->failedLog($this->diagnostics($e))[0]['status']);
        }
    }

    public function test_terminal_status_after_a_retry_says_how_many_attempts(): void
    {
        $e = $this->assertFailsWith('bundle_too_large', [new Response(502), new Response(413)]);

        $this->assertSame('upload target rejected the bundle size after 2 attempts', $e->detail);
    }

    public function test_retryable_statuses_are_retried(): void
    {
        $this->assertSame([408, 429, 500, 502, 503, 504], BundleUploader::RETRYABLE_STATUSES);

        foreach (BundleUploader::RETRYABLE_STATUSES as $status) {
            $diagnostics = $this->uploader([new Response($status), new Response(200)])->upload($this->bundlePath, self::URL);

            $this->assertCount(2, $this->history, "HTTP {$status}");
            $this->assertSame([$status], array_column($this->failedLog($diagnostics), 'status'));
        }
    }

    public function test_transport_failure_is_retried(): void
    {
        $requests = 0;
        $diagnostics = $this->statsUploader([
            [null, self::curlFailure(28, 'cURL error 28: Operation timed out', new Request('PUT', self::URL))],
            [null, new Response(200)],
        ], $requests)->upload($this->bundlePath, self::URL);

        $this->assertSame(2, $requests);

        // Guzzle 8 names a timeout before any response NetworkTimeoutException.
        $type = self::isGuzzle8() ? 'NetworkTimeoutException' : 'ConnectException';
        $first = $this->failedLog($diagnostics)[0];
        $this->assertNull($first['status']);
        $this->assertSame("{$type}: cURL error 28: Operation timed out", $first['error']);
        $this->assertSame(28, $first['curl_errno']);
        $this->assertNull($first['request_ids']);
        $this->assertNull($first['body']);
        $this->assertSame("attempt 1/5: {$type} curl 28, 0 ms", UploadSummary::line($diagnostics->attempts[0], 5, 22));
    }

    public function test_transport_failure_exhaustion_keeps_the_message(): void
    {
        $e = $this->assertFailsWith('upload_failed', array_fill(0, 5, new ConnectException('refused', new Request('PUT', self::URL))));

        $this->assertSame('ConnectException: refused after 5 attempts', $e->detail);
        $this->assertCount(5, $this->history);
        $this->assertNull($this->failedLog($this->diagnostics($e))[4]['curl_errno']);
    }

    public function test_error_response_carried_by_an_exception_is_judged_by_its_status(): void
    {
        $requests = 0;
        $tooLarge = new Response(413);
        $uploader = $this->statsUploader([
            [$tooLarge, self::curlFailure(55, 'send failure', new Request('PUT', self::URL), $tooLarge), 55],
            [null, new Response(201)],
        ], $requests);

        try {
            $uploader->upload($this->bundlePath, self::URL);
            $this->fail('Expected AcquireException');
        } catch (AcquireException $e) {
            $this->assertSame('bundle_too_large', $e->reason);
            $this->assertSame(1, $requests);

            $attempt = $this->failedLog($this->diagnostics($e))[0];
            $this->assertSame(413, $attempt['status']);
            $type = self::isGuzzle8() ? 'ResponseTransferException' : 'RequestException';
            $this->assertSame("{$type}: send failure", $attempt['error']);
            $this->assertSame(55, $attempt['curl_errno']);
        }
    }

    public function test_timeout_after_terminal_headers_is_judged_by_the_stats_response(): void
    {
        $requests = 0;
        $conflict = new Response(409);
        $uploader = $this->statsUploader([[$conflict, $this->timeout($conflict)], [null, new Response(201)]], $requests);

        try {
            $uploader->upload($this->bundlePath, self::URL);
            $this->fail('Expected AcquireException');
        } catch (AcquireException $e) {
            $this->assertSame('run_already_finished', $e->reason);
            $this->assertSame(1, $requests);

            $attempt = $this->failedLog($this->diagnostics($e))[0];
            $this->assertSame(409, $attempt['status']);
            $this->assertStringStartsWith(self::isGuzzle8() ? 'ResponseTimeoutException: ' : 'ConnectException: ', $attempt['error']);
            $this->assertSame(28, $attempt['curl_errno']);
        }
    }

    public function test_timeout_after_retryable_headers_keeps_the_stats_response(): void
    {
        $requests = 0;
        $slowDown = new Response(503, ['X-Amz-Request-Id' => 'TX503'], self::SLOW_DOWN);
        $diagnostics = $this->statsUploader([
            [$slowDown, $this->timeout($slowDown)],
            [null, new Response(201)],
        ], $requests)->upload($this->bundlePath, self::URL);

        $this->assertSame(2, $requests);
        $this->assertSame([2000], $this->sleeps);

        $first = $this->failedLog($diagnostics)[0];
        $this->assertSame(503, $first['status']);
        $this->assertSame('TX503', $first['request_id']);
        $this->assertSame('SlowDown', $first['error_code']);
        $this->assertSame(28, $first['curl_errno']);
        $this->assertSame(2000, $first['retry_in_ms']);
    }

    public function test_non_guzzle_error_is_not_retried(): void
    {
        $e = $this->assertFailsWith('upload_failed', [new RuntimeException('boom'), new Response(200)]);

        $this->assertSame('RuntimeException: boom', $e->detail);
        $this->assertCount(1, $this->history);
    }

    public function test_unreadable_bundle_fails_without_request(): void
    {
        $this->bundlePath = sys_get_temp_dir().'/ledge-upload-missing-'.bin2hex(random_bytes(4));

        $e = $this->assertFailsWith('upload_failed', [new Response(201)]);

        $this->assertSame('unable to open bundle for upload', $e->detail);
        $this->assertCount(0, $this->history);
        $this->assertSame(0, $this->diagnostics($e)->attemptCount());
    }

    public function test_retry_after_seconds_is_honoured_when_longer(): void
    {
        $this->uploader([new Response(429, ['Retry-After' => '10']), new Response(200)])->upload($this->bundlePath, self::URL);

        $this->assertSame([10000], $this->sleeps);
    }

    public function test_retry_after_http_date_is_honoured(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', (int) $this->now + 30);

        $this->uploader([new Response(503, ['Retry-After' => $date]), new Response(200)])->upload($this->bundlePath, self::URL);

        $this->assertSame([30000], $this->sleeps);
    }

    public function test_retry_after_is_capped(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', (int) $this->now + 7200);

        $this->uploader([
            new Response(503, ['Retry-After' => '3600']),
            new Response(503, ['Retry-After' => '99999999999']),
            new Response(503, ['Retry-After' => $date]),
            new Response(200),
        ])->upload($this->bundlePath, self::URL);

        $this->assertSame([60000, 60000, 60000], $this->sleeps);
    }

    public function test_shorter_or_invalid_retry_after_keeps_the_backoff(): void
    {
        $past = gmdate('D, d M Y H:i:s \G\M\T', (int) $this->now - 30);

        $this->uploader([
            new Response(503, ['Retry-After' => '1']),
            new Response(503, ['Retry-After' => 'soon']),
            new Response(503, ['Retry-After' => $past]),
            new Response(200),
        ])->upload($this->bundlePath, self::URL);

        $this->assertSame([2000, 6000, 18000], $this->sleeps);
    }

    public function test_backoff_applies_jitter(): void
    {
        $this->jitter = 1.25;
        $this->assertFailsWith('upload_failed', array_fill(0, 5, new Response(503)));
        $this->assertSame([2500, 7500, 22500, 67500], $this->sleeps);

        $this->jitter = 0.75;
        $this->assertFailsWith('upload_failed', array_fill(0, 5, new Response(503)));
        $this->assertSame([1500, 4500, 13500, 40500], $this->sleeps);

        $this->jitter = 5.0;
        $this->assertFailsWith('upload_failed', array_fill(0, 2, new Response(503)), maxAttempts: 2);
        $this->assertSame([2500], $this->sleeps);
    }

    public function test_default_jitter_stays_in_range(): void
    {
        $sleeps = [];
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 5, new Response(503))));
        $uploader = new BundleUploader(new Client(['handler' => $stack]), sleeper: function (int $ms) use (&$sleeps): void {
            $sleeps[] = $ms;
        });

        try {
            $uploader->upload($this->bundlePath, self::URL);
            $this->fail('Expected AcquireException');
        } catch (AcquireException) {
        }

        $this->assertCount(4, $sleeps);
        foreach ([2000, 6000, 18000, 54000] as $i => $base) {
            $this->assertGreaterThanOrEqual($base * 0.75, $sleeps[$i]);
            $this->assertLessThanOrEqual($base * 1.25, $sleeps[$i]);
        }
    }

    public function test_elapsed_budget_stops_retries(): void
    {
        $e = $this->assertFailsWith('upload_failed', [
            $this->slowResponse(550, new Response(504)),
            $this->slowResponse(100, new Response(504)),
            new Response(200),
        ]);

        $this->assertCount(2, $this->history);
        $this->assertSame([2000], $this->sleeps);
        $this->assertSame('upload target responded with HTTP 504 after 2 attempts (retry time budget of 600s exhausted)', $e->detail);

        $upload = $this->diagnostics($e);
        $this->assertSame(2, $upload->attemptCount());
        $this->assertSame([550000, 100000], array_column($this->failedLog($upload), 'duration_ms'));
        $this->assertNull($this->failedLog($upload)[1]['retry_in_ms']);
        $this->assertSame(652000, $upload->durationMs);
    }

    public function test_budget_stops_a_slow_timeout_before_any_retry(): void
    {
        $this->assertFailsWith('upload_failed', [
            $this->slowResponse(600, new ConnectException('cURL error 28: timed out', new Request('PUT', self::URL))),
            new Response(200),
        ]);

        $this->assertCount(1, $this->history);
        $this->assertSame([], $this->sleeps);
    }

    public function test_budget_is_checked_again_after_the_hook_and_wait(): void
    {
        $hookCalls = 0;
        $e = $this->assertFailsWith('upload_failed', [$this->slowResponse(595, new Response(503)), new Response(201)], hook: function () use (&$hookCalls): void {
            $hookCalls++;
            $this->now += 6;
        });

        $this->assertSame('upload target responded with HTTP 503 (retry time budget of 600s exhausted)', $e->detail);
        $this->assertSame(1, $hookCalls);
        $this->assertCount(1, $this->history);
        $this->assertSame([2000], $this->sleeps);

        $upload = $this->diagnostics($e);
        $this->assertSame(1, $upload->attemptCount());
        $this->assertNull($this->failedLog($upload)[0]['retry_in_ms']);
        $this->assertSame(603000, $upload->durationMs);
    }

    public function test_no_retry_starts_at_or_after_the_deadline(): void
    {
        $e = $this->assertFailsWith('upload_failed', [$this->slowResponse(98, new Response(503)), new Response(201)], deadline: $this->now + 100);

        $this->assertSame('upload target responded with HTTP 503 (upload URL deadline reached)', $e->detail);
        $this->assertCount(1, $this->history);
        $this->assertSame([], $this->sleeps);
        $this->assertNull($this->failedLog($this->diagnostics($e))[0]['retry_in_ms']);
    }

    public function test_deadline_is_checked_again_after_the_hook_and_wait(): void
    {
        $e = $this->assertFailsWith('upload_failed', [new Response(503), new Response(201)], deadline: $this->now + 5, hook: function (): void {
            $this->now += 4;
        });

        $this->assertSame('upload target responded with HTTP 503 (upload URL deadline reached)', $e->detail);
        $this->assertCount(1, $this->history);
        $this->assertSame([2000], $this->sleeps);
        $this->assertNull($this->failedLog($this->diagnostics($e))[0]['retry_in_ms']);
    }

    public function test_deadline_clips_each_attempts_timeout(): void
    {
        $this->uploader([new Response(503), new Response(201)])->upload($this->bundlePath, self::URL, null, $this->now + 200);
        $this->assertSame([200, 198], array_map(fn (array $entry): int => $entry['options']['timeout'], $this->history));

        $this->uploader([new Response(201)])->upload($this->bundlePath, self::URL, null, $this->now + 10);
        $this->assertSame(BundleUploader::MIN_ATTEMPT_TIMEOUT, $this->history[0]['options']['timeout']);

        $this->uploader([new Response(201)])->upload($this->bundlePath, self::URL, null, $this->now + 3600);
        $this->assertSame(600, $this->history[0]['options']['timeout']);
    }

    public function test_without_a_deadline_retries_run_as_before(): void
    {
        $this->now += 10 * 3600;

        $this->uploader([new Response(503), new Response(503), new Response(201)])->upload($this->bundlePath, self::URL);

        $this->assertSame([600, 600, 600], array_map(fn (array $entry): int => $entry['options']['timeout'], $this->history));
        $this->assertSame([2000, 6000], $this->sleeps);
    }

    public function test_retry_hook_is_called_once_per_retried_attempt(): void
    {
        $calls = [];

        $this->uploader([new Response(503), new Response(500), new Response(201)])->upload(
            $this->bundlePath,
            self::URL,
            function (UploadAttempt $attempt, UploadDiagnostics $soFar) use (&$calls): void {
                $calls[] = [$attempt->attempt, $attempt->status, $attempt->retryInMs, $soFar->maxAttempts, $soFar->attemptCount(), count($this->failedLog($soFar)), count($this->sleeps)];
            },
        );

        $this->assertSame([
            [1, 503, 2000, 5, 1, 1, 0],
            [2, 500, 6000, 5, 2, 2, 1],
        ], $calls);
    }

    public function test_retry_hook_is_not_called_without_a_retry(): void
    {
        $calls = 0;
        $hook = function () use (&$calls): void {
            $calls++;
        };

        $this->uploader([new Response(201)])->upload($this->bundlePath, self::URL, $hook);

        try {
            $this->uploader([new Response(403)])->upload($this->bundlePath, self::URL, $hook);
        } catch (AcquireException) {
        }

        try {
            $this->uploader([new Response(503)], maxAttempts: 1)->upload($this->bundlePath, self::URL, $hook);
        } catch (AcquireException) {
        }

        $this->assertSame(0, $calls);
    }

    public function test_throwing_retry_hook_does_not_break_the_upload_or_use_an_attempt(): void
    {
        $diagnostics = $this->uploader([new Response(503), new Response(201)])->upload(
            $this->bundlePath,
            self::URL,
            function (): void {
                throw new RuntimeException('callback exploded');
            },
        );

        $this->assertSame(2, $diagnostics->attemptCount());
        $this->assertCount(2, $this->history);
    }

    public function test_only_request_id_headers_are_reported(): void
    {
        $e = $this->assertFailsWith('upload_failed', [new Response(403, [
            'Server' => 'AmazonS3',
            'Set-Cookie' => 'session=secret',
            'X-Request-Id' => 'generic-1',
            'X-Amz-Id-2' => 'host-id',
            'CF-Ray' => 'ray-1',
            'Fly-Request-Id' => 'fly-1',
            'X-Tigris-Request-Id' => 'tigris-1',
            'X-Amz-Request-Id' => 'TX403',
            'X-Other-Request-Id' => str_repeat('r', 200),
        ])]);

        $attempt = $this->failedLog($this->diagnostics($e))[0];
        $this->assertSame('TX403', $attempt['request_id']);
        $this->assertSame([
            'x-amz-request-id' => 'TX403',
            'x-request-id' => 'generic-1',
            'x-amz-id-2' => 'host-id',
            'cf-ray' => 'ray-1',
            'fly-request-id' => 'fly-1',
        ], $attempt['request_ids']);

        $e = $this->assertFailsWith('upload_failed', [new Response(403, ['Server' => 'x', 'X-Tigris-Request-Id' => 'tigris-1', 'X-Long-Request-Id' => str_repeat('r', 200)])]);
        $attempt = $this->failedLog($this->diagnostics($e))[0];
        $this->assertSame('tigris-1', $attempt['request_id']);
        $this->assertSame(UploadAttempt::MAX_REQUEST_ID_BYTES, strlen($attempt['request_ids']['x-long-request-id']));

        $e = $this->assertFailsWith('upload_failed', [new Response(403, ['Server' => 'x'])]);
        $this->assertNull($this->failedLog($this->diagnostics($e))[0]['request_id']);
        $this->assertNull($this->failedLog($this->diagnostics($e))[0]['request_ids']);
    }

    public function test_body_excerpt_is_truncated_and_valid_utf8(): void
    {
        $body = str_repeat('a', 299).'é'.str_repeat('b', 5000)."\xff";

        $e = $this->assertFailsWith('upload_failed', [new Response(400, [], $body)]);
        $excerpt = $this->failedLog($this->diagnostics($e))[0]['body'];

        $this->assertSame(UploadAttempt::MAX_BODY_BYTES, strlen($excerpt));
        $this->assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
        $this->assertStringStartsWith(str_repeat('a', 299), $excerpt);
        $this->assertStringNotContainsString('b', $excerpt);
    }

    public function test_error_code_is_parsed_defensively(): void
    {
        $cases = [
            '<Error><Code>ServiceUnavailable</Code></Error>' => 'ServiceUnavailable',
            '<Error><Code> SlowDown </Code></Error>' => 'SlowDown',
            '<Error><Code>'.str_repeat('A', 65).'</Code></Error>' => null,
            '<Error><Code>Bad Code<script></Code></Error>' => null,
            '{"error":"bundle_too_large"}' => null,
            str_repeat(' ', 5000).'<Code>TooFar</Code>' => null,
        ];

        foreach ($cases as $body => $expected) {
            $e = $this->assertFailsWith('upload_failed', [new Response(400, [], $body)]);
            $this->assertSame($expected, $this->failedLog($this->diagnostics($e))[0]['error_code'], $body);
        }
    }

    public function test_transfer_stats_give_bytes_sent_and_remote_ip(): void
    {
        $handler = function (RequestInterface $request, array $options) {
            $response = new Response(503);
            ($options['on_stats'])(new TransferStats($request, $response, 0.8, null, [
                'url' => self::URL,
                'primary_ip' => '52.216.1.2',
                'size_upload' => 0.0,
            ]));

            return Create::promiseFor($response);
        };

        $uploader = new BundleUploader(new Client(['handler' => HandlerStack::create($handler)]), 600, 1, ...$this->fakes());

        try {
            $uploader->upload($this->bundlePath, self::URL);
            $this->fail('Expected AcquireException');
        } catch (AcquireException $e) {
            $attempt = $this->failedLog($this->diagnostics($e))[0];
            $this->assertSame(0, $attempt['bytes_sent']);
            $this->assertSame('52.216.1.2', $attempt['remote_ip']);
            $this->assertSame('attempt 1/1: HTTP 503, 0 ms, sent 0/22 bytes', $this->diagnostics($e)->lastFailureSummary());
        }
    }

    public function test_diagnostics_are_json_encodable(): void
    {
        $e = $this->assertFailsWith('upload_failed', [
            new Response(500, ['X-Request-Id' => "caf\xe9"], "<Error>\xc3\x28 invalid \xff</Error>"),
            new ConnectException("bad bytes \xfe in message", new Request('PUT', self::URL)),
            new Response(502),
            new Response(503),
            new Response(504),
        ]);

        $json = json_encode($this->failedLog($this->diagnostics($e)), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"attempt":5', $json);
        $this->assertLessThan(4096, strlen($json));
        json_encode(['detail' => $e->detail], JSON_THROW_ON_ERROR);
    }

    public function test_signed_query_and_callback_token_never_leak(): void
    {
        $echo = '<Error><Code>SignatureDoesNotMatch</Code><SignatureProvided>'.self::SIGNATURE.'</SignatureProvided>'
            .'<CanonicalRequest>PUT /api/bundles/run-123 '.self::QUERY.'</CanonicalRequest><Url>'.self::URL.'</Url>'
            .'<Token>'.self::CALLBACK_TOKEN.'</Token></Error>';
        $straddling = str_repeat('.', 300 - 20).self::SIGNATURE;
        $request = new Request('PUT', self::URL);

        $e = $this->assertFailsWith('upload_failed', [
            new Response(503, ['X-Amz-Request-Id' => self::SIGNATURE, 'X-Request-Id' => self::CALLBACK_TOKEN, 'Retry-After' => self::QUERY], $echo),
            new Response(503, [], $straddling),
            new ConnectException('cURL error 7: Failed to connect for '.self::URL, $request),
            new ConnectException('cURL error 28: timed out for '.self::URL.' token '.self::CALLBACK_TOKEN, $request),
            new Response(503, ['X-Amz-Request-Id' => self::CALLBACK_TOKEN]),
        ], alsoRedact: [self::CALLBACK_TOKEN]);

        $upload = $this->diagnostics($e);
        $haystacks = [
            json_encode($this->failedLog($upload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode(CallbackPayloads::failed($e->reason, $e->detail, [], 1, $upload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            json_encode(CallbackPayloads::uploadRetried($upload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            (string) $upload->lastFailureSummary(),
            (string) $e->detail,
            $e->getMessage(),
        ];

        foreach ($upload->attempts as $attempt) {
            $haystacks[] = UploadSummary::line($attempt, 5, 22);
        }

        foreach ($haystacks as $haystack) {
            $this->assertStringNotContainsString(self::SIGNATURE, $haystack);
            $this->assertStringNotContainsString(substr(self::SIGNATURE, 0, 12), $haystack);
            $this->assertStringNotContainsString('signature=', $haystack);
            $this->assertStringNotContainsString(self::CALLBACK_TOKEN, $haystack);
            $this->assertStringNotContainsString(substr(self::CALLBACK_TOKEN, 0, 12), $haystack);
        }

        $log = $this->failedLog($upload);
        $this->assertStringContainsString('<Url>https://storage.ledgehq.app/api/bundles/run-123</Url>', $log[0]['body']);
        $this->assertSame('[redacted]', $log[0]['request_id']);
        $this->assertSame('ConnectException: cURL error 28: timed out for https://storage.ledgehq.app/api/bundles/run-123 token [redacted]', $log[3]['error']);
    }

    public function test_record_shape(): void
    {
        $e = $this->assertFailsWith('upload_failed', [new Response(403)]);

        $this->assertSame(
            ['attempt', 'started_at', 'status', 'error', 'curl_errno', 'error_code', 'request_id', 'request_ids', 'retry_after', 'duration_ms', 'bytes_sent', 'remote_ip', 'body', 'retry_in_ms'],
            array_keys($this->failedLog($this->diagnostics($e))[0]),
        );
    }

    private function uploader(array $responses, int $maxAttempts = BundleUploader::DEFAULT_MAX_ATTEMPTS): BundleUploader
    {
        $this->history = [];
        $this->bodies = [];
        $this->sleeps = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $stack->push(function (callable $handler): Closure {
            return function (RequestInterface $request, array $options) use ($handler) {
                // getContents() does not rewind, so a reused, consumed stream would show up as ''.
                $this->bodies[] = $request->getBody()->getContents();

                return $handler($request, $options);
            };
        });

        return new BundleUploader(new Client(['handler' => $stack]), 600, $maxAttempts, ...$this->fakes());
    }

    /**
     * Mimics curl's handler: on_stats sees the response whose headers arrived
     * and curl's errno (28 for a failure unless given), then the request may
     * still fail without the response.
     *
     * @param  array<array{0: Response|null, 1: Response|Throwable, 2?: int}>  $outcomes
     */
    private function statsUploader(array $outcomes, int &$requests): BundleUploader
    {
        $this->sleeps = [];

        $handler = function (RequestInterface $request, array $options) use (&$outcomes, &$requests) {
            $requests++;
            $outcome = array_shift($outcomes);
            [$statsResponse, $result] = $outcome;
            $statsResponse ??= $result instanceof Response ? $result : null;
            $errno = $result instanceof Throwable ? ($outcome[2] ?? 28) : 0;
            ($options['on_stats'])(new TransferStats($request, $statsResponse, 0.1, $errno, []));

            return $result instanceof Throwable ? Create::rejectionFor($result) : Create::promiseFor($result);
        };

        return new BundleUploader(new Client(['handler' => HandlerStack::create($handler)]), 600, 5, ...$this->fakes());
    }

    private function timeout(?Response $headersArrived = null): Throwable
    {
        return self::curlFailure(
            28,
            'cURL error 28: Operation timed out after 600000 milliseconds for '.self::URL,
            new Request('PUT', self::URL),
            $headersArrived,
        );
    }

    /**
     * @return Closure[]
     */
    private function fakes(): array
    {
        return [
            function (int $ms): void {
                $this->sleeps[] = $ms;
                $this->now += $ms / 1000;
            },
            fn (): float => $this->now,
            fn (): float => $this->jitter,
        ];
    }

    private function slowResponse(int $seconds, mixed $outcome): Closure
    {
        return function () use ($seconds, $outcome) {
            $this->now += $seconds;

            return $outcome instanceof Throwable ? Create::rejectionFor($outcome) : $outcome;
        };
    }

    /**
     * The failed attempts as they would be reported, one object each.
     *
     * @return list<array<string, mixed>>
     */
    private function failedLog(UploadDiagnostics $diagnostics): array
    {
        $log = [];

        foreach ($diagnostics->attempts as $attempt) {
            if (! $attempt->succeeded()) {
                $log[] = $attempt->toArray();
            }
        }

        return $log;
    }

    private function diagnostics(AcquireException $e): UploadDiagnostics
    {
        $this->assertInstanceOf(UploadDiagnostics::class, $e->context['upload'] ?? null);

        return $e->context['upload'];
    }

    private function assertFailsWith(
        string $reason,
        array $responses,
        int $maxAttempts = BundleUploader::DEFAULT_MAX_ATTEMPTS,
        ?float $deadline = null,
        ?Closure $hook = null,
        array $alsoRedact = [],
    ): AcquireException {
        try {
            $this->uploader($responses, $maxAttempts)->upload($this->bundlePath, self::URL, $hook, $deadline, $alsoRedact);
        } catch (AcquireException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame(500, $e->httpStatus);
            $this->diagnostics($e);

            return $e;
        }

        $this->fail("Expected AcquireException with reason \"{$reason}\"");
    }
}
