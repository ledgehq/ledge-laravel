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
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\Database\DatabaseDumper;
use LedgeHQ\Ledge\Acquire\Database\DatabaseInspector;
use LedgeHQ\Ledge\AcquireService;
use LedgeHQ\Ledge\Jobs\BackupJob;
use LedgeHQ\Ledge\Settings;
use LedgeHQ\Ledge\Tests\Support\DecryptsBundles;
use LedgeHQ\Ledge\Tests\Support\SignsAcquireCommands;
use LedgeHQ\Ledge\Tests\TestCase;
use Psr\Http\Message\RequestInterface;

final class BackupJobTest extends TestCase
{
    use DecryptsBundles;
    use SignsAcquireCommands;

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

        $status = $this->app->make(AcquireService::class)->getStatus('run-1');
        self::assertSame(AcquireService::STATUS_COMPLETED, $status['status'] ?? null);
        self::assertSame(strlen($this->uploadedBody), $status['size'] ?? null);

        self::assertDirectoryDoesNotExist($this->tmpPath.'/run-1');
        $this->cleanupExtracts();
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
        self::assertSame(['reason' => 'dump_failed', 'detail' => 'mysqldump exited 2'], $failed['detail']);

        $status = $this->app->make(AcquireService::class)->getStatus('run-1');
        self::assertSame(AcquireService::STATUS_FAILED, $status['status'] ?? null);
        self::assertSame('dump_failed', $status['detail'] ?? null);

        self::assertDirectoryDoesNotExist($this->tmpPath.'/run-1');
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

    private function runJob(): void
    {
        $job = new BackupJob('run-1', self::UPLOAD_URL, base64_encode(sodium_crypto_box_publickey($this->bundleKeypair)), self::CALLBACK_URL, 'callback-token', 'backup');

        $this->app->call([$job, 'handle']);
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
