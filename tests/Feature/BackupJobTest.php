<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Sleep;
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\CallbackPayloads;
use LedgeHQ\Ledge\Acquire\Database\DatabaseDumper;
use LedgeHQ\Ledge\Acquire\Database\DatabaseInspector;
use LedgeHQ\Ledge\AcquireService;
use LedgeHQ\Ledge\Jobs\BackupJob;
use LedgeHQ\Ledge\Settings;
use LedgeHQ\Ledge\Tests\Support\DecryptsBundles;
use LedgeHQ\Ledge\Tests\Support\SignsAcquireCommands;
use LedgeHQ\Ledge\Tests\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

final class BackupJobTest extends TestCase
{
    use DecryptsBundles;
    use SignsAcquireCommands;

    private const SIGNATURE = '9f3c2b7a1d4e8f60a5b3c9d2e7f1a4b8c6d0e3f5a7b9c1d2e4f6a8b0c2d4e6f8';

    private const SIGNED_UPLOAD_URL = self::UPLOAD_URL.'?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Expires=3600&X-Amz-Signature='.self::SIGNATURE;

    private const DUMP = "CREATE TABLE users (id integer);\nINSERT INTO users VALUES (1);\n";

    /** @var list<array{request: Request}> */
    private array $history = [];

    private MockHandler $http;

    private ?string $uploadedBody = null;

    private string $tmpPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generateKeys();
        $this->history = [];
        $this->uploadedBody = null;
        $this->http = new MockHandler;
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->history));
        $this->app->instance(ClientInterface::class, new Client(['handler' => $stack]));

        $this->app->instance(DatabaseInspector::class, new class($this->app['db']->connection()) extends DatabaseInspector
        {
            public function estimateDumpBytes(): ?int
            {
                return 1024;
            }

            public function countTables(): ?int
            {
                return 3;
            }
        });
    }

    protected function tearDown(): void
    {
        @rmdir($this->tmpPath);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->tmpPath = sys_get_temp_dir().'/ledge-job-'.bin2hex(random_bytes(4));

        /** @var Application $app */
        $app['config']->set('cache.default', 'array');
        $app['config']->set('ledge.acquire_enabled', true);
        $app['config']->set('ledge.acquire_tmp_path', $this->tmpPath);
    }

    public function test_backup_run_dumps_encrypts_uploads_and_reports(): void
    {
        $this->bindDumper(fn (string $path) => file_put_contents($path, self::DUMP));
        $this->http->append(
            new Response(200), // started
            new Response(200), // progress: preflight
            new Response(200), // progress: dump
            new Response(200), // progress: manifest
            new Response(200), // progress: encrypt
            function (RequestInterface $request): Response {
                $this->uploadedBody = (string) $request->getBody();

                return new Response(201, [], '{"ok":true}');
            },
            new Response(200), // completed
        );

        $this->runJob();

        $events = array_map(fn (array $entry): string => (string) json_decode((string) $entry['request']->getBody(), true)['event'], array_filter(
            $this->history,
            fn (array $entry): bool => $entry['request']->getMethod() === 'POST',
        ));
        self::assertSame(['acquire.started', 'acquire.progress', 'acquire.progress', 'acquire.progress', 'acquire.progress', 'acquire.completed'], array_values($events));

        $upload = $this->history[5]['request'];
        self::assertSame('PUT', $upload->getMethod());
        self::assertSame(self::UPLOAD_URL, (string) $upload->getUri());
        self::assertNotNull($this->uploadedBody);

        $files = $this->decryptBundle($this->uploadedBody, $this->bundleKeypair, $this->tmpPath.'/extract-'.bin2hex(random_bytes(2)));
        self::assertSame(['dump.sql' => self::DUMP], $files);

        $completed = json_decode((string) $this->history[6]['request']->getBody(), true);
        self::assertSame('upload', $completed['step']);
        self::assertSame(strlen($this->uploadedBody), $completed['detail']['size']);
        self::assertSame(hash('sha256', $this->uploadedBody), $completed['detail']['sha256']);
        self::assertSame(3, $completed['detail']['table_count']);
        self::assertGreaterThan(0, $completed['detail']['uncompressed_size']);
        self::assertIsInt($completed['detail']['dump_duration_ms']);
        self::assertSame(
            ['size', 'sha256', 'compressed_size', 'uncompressed_size', 'dump_duration_ms', 'table_count', 'encrypt_duration_ms', 'upload_duration_ms', 'upload_attempts', 'peak_memory_bytes'],
            array_keys($completed['detail']),
        );
        self::assertIsInt($completed['detail']['encrypt_duration_ms']);
        self::assertIsInt($completed['detail']['upload_duration_ms']);
        self::assertSame(1, $completed['detail']['upload_attempts']);
        self::assertGreaterThan(0, $completed['detail']['peak_memory_bytes']);
        self::assertSame(600, $this->history[5]['options']['timeout']);

        $status = $this->app->make(AcquireService::class)->getStatus('run-1');
        self::assertSame(AcquireService::STATUS_COMPLETED, $status['status'] ?? null);
        self::assertSame(strlen($this->uploadedBody), $status['size'] ?? null);

        self::assertDirectoryDoesNotExist($this->tmpPath.'/run-1');
        $this->cleanupExtracts();
    }

    public function test_a_transient_upload_failure_is_retried_and_reported(): void
    {
        Sleep::fake();
        $logger = $this->bindLogger();
        $uploads = [];
        $this->bindDumper(fn (string $path) => file_put_contents($path, self::DUMP));
        $this->http->append(
            new Response(200), // started
            new Response(200), new Response(200), new Response(200), new Response(200), // progress
            function (RequestInterface $request) use (&$uploads): Response {
                $uploads[] = $request->getBody()->getContents();

                return new Response(503, ['X-Amz-Request-Id' => 'TX503', 'Retry-After' => '1'], $this->slowDown());
            },
            new Response(200), // upload_retried
            function (RequestInterface $request) use (&$uploads): Response {
                $uploads[] = $request->getBody()->getContents();

                return new Response(201);
            },
            new Response(200), // completed
        );

        $this->runJob(self::SIGNED_UPLOAD_URL, acceptedAt: time());

        self::assertCount(2, $uploads);
        self::assertSame($uploads[0], $uploads[1]);
        self::assertNotSame('', $uploads[0]);
        self::assertSame([600, 600], array_column(array_column($this->puts(), 'options'), 'timeout'));

        $callbacks = $this->callbacks();
        self::assertSame(['acquire.started', 'acquire.progress', 'acquire.progress', 'acquire.progress', 'acquire.progress', 'acquire.upload_retried', 'acquire.completed'], array_column($callbacks, 'event'));

        $retry = $callbacks[5];
        self::assertSame('upload', $retry['step']);
        self::assertSame(['reason', 'max_attempts', 'retry_in_ms', 'upload_attempts', 'upload_attempt'], array_keys($retry['detail']));
        self::assertMatchesRegularExpression('/^attempt 1\/5: HTTP 503 SlowDown, request-id TX503, \d+ ms, retry-after 1$/', $retry['detail']['reason']);
        self::assertSame(5, $retry['detail']['max_attempts']);
        self::assertSame(1, $retry['detail']['upload_attempts']);
        self::assertSame(1, $retry['detail']['upload_attempt']['attempt']);
        self::assertSame(503, $retry['detail']['upload_attempt']['status']);
        self::assertSame('SlowDown', $retry['detail']['upload_attempt']['error_code']);
        self::assertSame('TX503', $retry['detail']['upload_attempt']['request_id']);
        self::assertNotNull($retry['detail']['upload_attempt']['body']);
        $waitMs = $retry['detail']['retry_in_ms'];
        self::assertGreaterThanOrEqual(1500, $waitMs);
        self::assertLessThanOrEqual(2500, $waitMs);
        self::assertSame($waitMs, $retry['detail']['upload_attempt']['retry_in_ms']);
        Sleep::assertSequence([Sleep::for($waitMs)->milliseconds()]);

        $completed = $callbacks[6]['detail'];
        self::assertSame(2, $completed['upload_attempts']);
        self::assertArrayNotHasKey('upload_attempt', $completed);
        self::assertArrayNotHasKey('upload_attempt_log', $completed);
        foreach (['encrypt_duration_ms', 'upload_duration_ms', 'peak_memory_bytes'] as $key) {
            self::assertIsInt($completed[$key], $key);
        }

        self::assertSame(["Ledge acquisition run-1 upload {$retry['detail']['reason']}; retrying in {$waitMs}ms"], $logger->warnings);
        $this->assertNoSecrets($callbacks);

        $status = $this->app->make(AcquireService::class)->getStatus('run-1');
        self::assertSame(AcquireService::STATUS_COMPLETED, $status['status'] ?? null);
    }

    public function test_exhausted_retries_fail_once_with_the_attempt_log(): void
    {
        Sleep::fake();
        $this->bindDumper(fn (string $path) => file_put_contents($path, self::DUMP));
        $this->http->append(new Response(200), new Response(200), new Response(200), new Response(200), new Response(200));
        for ($i = 1; $i <= 5; $i++) {
            $this->http->append(new Response(503, ['X-Amz-Request-Id' => "TX{$i}"], $this->slowDown()));
            $this->http->append(new Response(200)); // upload_retried, then failed
        }

        try {
            $this->runJob(self::SIGNED_UPLOAD_URL, acceptedAt: time());
            self::fail('Expected the job to rethrow');
        } catch (AcquireException $e) {
            self::assertSame('upload_failed', $e->reason);
        }

        self::assertCount(5, $this->puts());
        Sleep::assertSleptTimes(4);

        $callbacks = $this->callbacks();
        $events = array_column($callbacks, 'event');
        self::assertSame(4, count(array_keys($events, 'acquire.upload_retried', true)));
        self::assertSame(['acquire.failed'], array_values(array_filter($events, fn (string $event): bool => str_ends_with($event, '.failed'))));
        self::assertSame('acquire.failed', end($events));

        $failed = end($callbacks);
        self::assertSame('upload', $failed['step']);
        $detail = $failed['detail'];
        self::assertSame(
            ['reason', 'reason_code', 'detail', 'upload_attempts', 'upload_attempt', 'dump_duration_ms', 'encrypt_duration_ms', 'upload_duration_ms', 'peak_memory_bytes'],
            array_keys($detail),
        );
        self::assertMatchesRegularExpression('/^upload_failed: attempt 5\/5: HTTP 503 SlowDown, request-id TX5, \d+ ms$/', $detail['reason']);
        self::assertLessThan(255, strlen($detail['reason']));
        self::assertSame('upload_failed', $detail['reason_code']);
        self::assertSame('upload target responded with HTTP 503 after 5 attempts', $detail['detail']);
        self::assertSame(5, $detail['upload_attempts']);
        self::assertSame(5, $detail['upload_attempt']['attempt']);
        self::assertSame('TX5', $detail['upload_attempt']['request_id']);
        self::assertNull($detail['upload_attempt']['retry_in_ms']);
        self::assertLessThanOrEqual(CallbackPayloads::STORED_DETAIL_BUDGET, CallbackPayloads::storedSize($detail));
        foreach (['dump_duration_ms', 'encrypt_duration_ms', 'upload_duration_ms', 'peak_memory_bytes'] as $key) {
            self::assertIsInt($detail[$key], $key);
        }
        $this->assertNoSecrets($callbacks);

        $status = $this->app->make(AcquireService::class)->getStatus('run-1');
        self::assertSame(AcquireService::STATUS_FAILED, $status['status'] ?? null);
        self::assertSame('upload_failed', $status['detail'] ?? null);
        self::assertDirectoryDoesNotExist($this->tmpPath.'/run-1');
    }

    public function test_a_403_fails_without_retrying(): void
    {
        Sleep::fake();
        $this->bindDumper(fn (string $path) => file_put_contents($path, self::DUMP));
        $this->http->append(
            new Response(200), new Response(200), new Response(200), new Response(200), new Response(200),
            new Response(403, ['X-Amz-Request-Id' => 'TX403'], '<Error><Code>AccessDenied</Code><SignatureProvided>'.self::SIGNATURE.'</SignatureProvided><Token>callback-token</Token></Error>'),
            new Response(200), // failed
        );

        try {
            $this->runJob(self::SIGNED_UPLOAD_URL, acceptedAt: time());
            self::fail('Expected the job to rethrow');
        } catch (AcquireException $e) {
            self::assertSame('upload_failed', $e->reason);
        }

        self::assertCount(1, $this->puts());
        Sleep::assertNeverSlept();

        $callbacks = $this->callbacks();
        self::assertNotContains('acquire.upload_retried', array_column($callbacks, 'event'));
        $failed = end($callbacks);
        self::assertSame('acquire.failed', $failed['event']);
        self::assertMatchesRegularExpression('/^upload_failed: attempt 1\/5: HTTP 403 AccessDenied, request-id TX403, \d+ ms$/', $failed['detail']['reason']);
        self::assertSame('upload target responded with HTTP 403', $failed['detail']['detail']);
        self::assertSame(1, $failed['detail']['upload_attempts']);
        self::assertStringContainsString('[redacted]', $failed['detail']['upload_attempt']['body']);
        $this->assertNoSecrets($callbacks);
    }

    public function test_a_413_keeps_the_bare_bundle_too_large_reason(): void
    {
        Sleep::fake();
        $this->bindDumper(fn (string $path) => file_put_contents($path, self::DUMP));
        $this->http->append(
            new Response(200), new Response(200), new Response(200), new Response(200), new Response(200),
            new Response(413, ['X-Amz-Request-Id' => 'TX413'], '<Error><Code>EntityTooLarge</Code></Error>'),
            new Response(200), // failed
        );

        try {
            $this->runJob(self::SIGNED_UPLOAD_URL, acceptedAt: time());
            self::fail('Expected the job to rethrow');
        } catch (AcquireException $e) {
            self::assertSame('bundle_too_large', $e->reason);
        }

        self::assertCount(1, $this->puts());
        Sleep::assertNeverSlept();

        $callbacks = $this->callbacks();
        self::assertNotContains('acquire.upload_retried', array_column($callbacks, 'event'));
        $failed = end($callbacks);
        self::assertSame('acquire.failed', $failed['event']);
        self::assertSame('upload', $failed['step']);
        self::assertSame('bundle_too_large', $failed['detail']['reason']);
        self::assertSame('bundle_too_large', $failed['detail']['reason_code']);
        self::assertSame('upload target rejected the bundle size', $failed['detail']['detail']);
        self::assertSame(1, $failed['detail']['upload_attempts']);
        self::assertSame(413, $failed['detail']['upload_attempt']['status']);
        self::assertSame('EntityTooLarge', $failed['detail']['upload_attempt']['error_code']);
        self::assertIsInt($failed['detail']['upload_duration_ms']);
        $this->assertNoSecrets($callbacks);

        $status = $this->app->make(AcquireService::class)->getStatus('run-1');
        self::assertSame('bundle_too_large', $status['detail'] ?? null);
    }

    public function test_no_retry_starts_past_the_upload_url_deadline(): void
    {
        Sleep::fake();
        $this->bindDumper(fn (string $path) => file_put_contents($path, self::DUMP));
        $this->http->append(
            new Response(200), new Response(200), new Response(200), new Response(200), new Response(200),
            new Response(503),
            new Response(200), // failed
        );

        try {
            // Accepted 54m59s ago: one second of the window is left, less than any backoff.
            $this->runJob(self::SIGNED_UPLOAD_URL, acceptedAt: time() - BackupJob::UPLOAD_DEADLINE_SECONDS + 1);
            self::fail('Expected the job to rethrow');
        } catch (AcquireException $e) {
            self::assertSame('upload target responded with HTTP 503 (upload URL deadline reached)', $e->detail);
        }

        self::assertCount(1, $this->puts());
        self::assertSame(30, $this->puts()[0]['options']['timeout']);
        Sleep::assertNeverSlept();
        self::assertNotContains('acquire.upload_retried', array_column($this->callbacks(), 'event'));
    }

    public function test_a_job_serialized_before_accepted_at_existed_still_runs(): void
    {
        $job = $this->job(self::UPLOAD_URL, 1234567890);
        $serialized = serialize($job);
        $legacy = (string) preg_replace('/s:10:"acceptedAt";i:\d+;/', '', $serialized, 1, $count);
        self::assertSame(1, $count);
        $legacy = (string) preg_replace_callback('/^(O:\d+:"[^"]+":)(\d+)/', fn (array $m): string => $m[1].((int) $m[2] - 1), $legacy);

        $restored = unserialize($legacy);
        self::assertInstanceOf(BackupJob::class, $restored);
        self::assertSame(0, $restored->acceptedAt);
        self::assertSame('run-1', $restored->runId);

        $this->bindDumper(fn (string $path) => file_put_contents($path, self::DUMP));
        $this->http->append(new Response(200), new Response(200), new Response(200), new Response(200), new Response(200), new Response(201), new Response(200));
        $this->app->call([$restored, 'handle']);

        self::assertSame(600, $this->puts()[0]['options']['timeout']);
        $events = array_column($this->callbacks(), 'event');
        self::assertSame('acquire.completed', end($events));
    }

    public function test_end_to_end_through_the_endpoint_on_the_sync_queue(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('ledge.acquire_allowed_hosts', ['*.ledgehq.test']);
        $this->app['config']->set('ledge.ledge_base_url', 'https://my.ledgehq.test');
        $this->app->forgetInstance(Settings::class);
        $this->app->forgetInstance(AcquireService::class);

        $this->bindDumper(fn (string $path) => file_put_contents($path, self::DUMP));
        $this->http->append(
            $this->keysetResponse(),
            new Response(200), // accepted
            new Response(200), new Response(200), new Response(200), new Response(200), new Response(200),
            new Response(201), // upload
            new Response(200), // completed
        );

        $this->postJson('/_ledge/acquire', $this->envelope($this->command()), ['X-Ledge-Key' => self::KEY])
            ->assertStatus(202)
            ->assertJsonPath('run_id', 'run-1');

        $this->getJson('/_ledge/acquire/run-1', ['X-Ledge-Key' => self::KEY])
            ->assertOk()
            ->assertJsonPath('status', AcquireService::STATUS_COMPLETED)
            ->assertJsonPath('step', 'upload');

        $events = array_values(array_map(
            fn (array $entry): string => (string) json_decode((string) $entry['request']->getBody(), true)['event'],
            array_filter($this->history, fn (array $entry): bool => $entry['request']->getMethod() === 'POST'),
        ));
        // On the sync queue the job runs inside dispatch(), so `accepted` goes
        // out after `completed`. Ledge accepts late events; order is only
        // guaranteed on a real queue.
        self::assertContains('acquire.accepted', $events);
        self::assertContains('acquire.completed', $events);
        self::assertNotContains('acquire.failed', $events);
        self::assertDirectoryDoesNotExist($this->tmpPath.'/run-1');
    }

    public function test_dump_failure_is_reported_and_the_workspace_is_cleaned(): void
    {
        $this->bindDumper(function (): void {
            throw new AcquireException('dump_failed', 500, 'mysqldump exited 2');
        });
        $this->http->append(new Response(200), new Response(200), new Response(200));

        try {
            $this->runJob();
            self::fail('Expected the job to rethrow');
        } catch (AcquireException $e) {
            self::assertSame('dump_failed', $e->reason);
        }

        $failed = json_decode((string) end($this->history)['request']->getBody(), true);
        self::assertSame('acquire.failed', $failed['event']);
        self::assertSame('dump', $failed['step']);
        self::assertSame(['reason', 'reason_code', 'detail', 'peak_memory_bytes'], array_keys($failed['detail']));
        self::assertSame('dump_failed', $failed['detail']['reason']);
        self::assertSame('dump_failed', $failed['detail']['reason_code']);
        self::assertSame('mysqldump exited 2', $failed['detail']['detail']);
        self::assertIsInt($failed['detail']['peak_memory_bytes']);

        $status = $this->app->make(AcquireService::class)->getStatus('run-1');
        self::assertSame(AcquireService::STATUS_FAILED, $status['status'] ?? null);
        self::assertSame('dump_failed', $status['detail'] ?? null);

        self::assertDirectoryDoesNotExist($this->tmpPath.'/run-1');
    }

    public function test_an_encrypt_failure_reports_the_dump_timing_with_a_bare_code(): void
    {
        $this->bindDumper(fn (string $path) => file_put_contents($path, self::DUMP));
        $this->http->append(new Response(200), new Response(200), new Response(200), new Response(200), new Response(200));

        try {
            $this->app->call([new BackupJob('run-1', self::UPLOAD_URL, 'not-a-key', self::CALLBACK_URL, 'callback-token', 'backup'), 'handle']);
            self::fail('Expected the job to rethrow');
        } catch (AcquireException $e) {
            self::assertSame('encrypt_failed', $e->reason);
        }

        $callbacks = $this->callbacks();
        $failed = end($callbacks);
        self::assertSame('encrypt', $failed['step']);
        self::assertSame(['reason', 'reason_code', 'detail', 'dump_duration_ms', 'peak_memory_bytes'], array_keys($failed['detail']));
        self::assertSame('encrypt_failed', $failed['detail']['reason']);
        self::assertSame('encrypt_failed', $failed['detail']['reason_code']);
        self::assertIsInt($failed['detail']['dump_duration_ms']);
        self::assertSame([], $this->puts());
    }

    public function test_missing_dump_binary_fails_preflight(): void
    {
        $this->bindDumper(fn () => null, available: false);
        $this->http->append(new Response(200), new Response(200));

        try {
            $this->runJob();
            self::fail('Expected the job to rethrow');
        } catch (AcquireException $e) {
            self::assertSame('no_dump_command', $e->reason);
        }

        $failed = json_decode((string) end($this->history)['request']->getBody(), true);
        self::assertSame('preflight', $failed['step']);
    }

    private function runJob(string $uploadUrl = self::UPLOAD_URL, int $acceptedAt = 0): void
    {
        $this->app->call([$this->job($uploadUrl, $acceptedAt), 'handle']);
    }

    private function job(string $uploadUrl, int $acceptedAt = 0): BackupJob
    {
        return new BackupJob('run-1', $uploadUrl, base64_encode(sodium_crypto_box_publickey($this->bundleKeypair)), self::CALLBACK_URL, 'callback-token', 'backup', $acceptedAt);
    }

    /**
     * The upload PUTs in the HTTP history, with their request options.
     *
     * @return list<array{request: RequestInterface, options: array<string, mixed>}>
     */
    private function puts(): array
    {
        return array_values(array_filter($this->history, fn (array $entry): bool => $entry['request']->getMethod() === 'PUT'));
    }

    /**
     * No callback body may carry the signed URL's query string or the callback
     * token, and every detail must fit what Ledge stores.
     *
     * @param  list<array<string, mixed>>  $callbacks
     */
    private function assertNoSecrets(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $json = (string) json_encode($callback, JSON_UNESCAPED_SLASHES);

            self::assertStringNotContainsString(self::SIGNATURE, $json);
            self::assertStringNotContainsString(substr(self::SIGNATURE, 0, 12), $json);
            self::assertStringNotContainsString('X-Amz-Signature', $json);
            self::assertStringNotContainsString('callback-token', $json);

            if (is_array($callback['detail'] ?? null)) {
                self::assertLessThanOrEqual(CallbackPayloads::STORED_DETAIL_BUDGET, CallbackPayloads::storedSize($callback['detail']));
            }
        }
    }

    private function slowDown(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Error><Code>SlowDown</Code><Message>Please reduce your request rate.</Message>'
            .'<Resource>'.self::SIGNED_UPLOAD_URL.'</Resource><Token>callback-token</Token></Error>';
    }

    /**
     * Decoded bodies of the callback POSTs, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function callbacks(): array
    {
        $posts = array_filter($this->history, fn (array $entry): bool => $entry['request']->getMethod() === 'POST');

        return array_values(array_map(fn (array $entry): array => json_decode((string) $entry['request']->getBody(), true), $posts));
    }

    private function bindLogger(): object
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $this->app->instance(LoggerInterface::class, $logger);

        return $logger;
    }

    private function bindDumper(callable $dump, bool $available = true): void
    {
        $this->app->instance(DatabaseDumper::class, new class($dump, $available) implements DatabaseDumper
        {
            /** @var callable */
            private $dump;

            public function __construct(callable $dump, private readonly bool $available)
            {
                $this->dump = $dump;
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function dump(string $path): void
            {
                ($this->dump)($path);
            }
        });
    }

    private function cleanupExtracts(): void
    {
        foreach (glob($this->tmpPath.'/extract-*') ?: [] as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}
