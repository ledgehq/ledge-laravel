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
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use LedgeHQ\Ledge\AcquireService;
use LedgeHQ\Ledge\Jobs\BackupJob;
use LedgeHQ\Ledge\Settings;
use LedgeHQ\Ledge\Tests\Support\SignsAcquireCommands;
use LedgeHQ\Ledge\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class AcquireEndpointTest extends TestCase
{
    use SignsAcquireCommands;

    /** @var list<array{request: Request}> */
    private array $history = [];

    private MockHandler $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generateKeys();
        $this->history = [];
        $this->http = new MockHandler;
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->history));
        $this->app->instance(ClientInterface::class, new Client(['handler' => $stack]));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var Application $app */
        $app['config']->set('cache.default', 'array');
        $app['config']->set('ledge.acquire_enabled', true);
        $app['config']->set('ledge.acquire_allowed_hosts', ['*.ledgehq.test']);
        $app['config']->set('ledge.ledge_base_url', 'https://my.ledgehq.test');
    }

    protected function queryKeyAllowed(Application $app): void
    {
        $app['config']->set('ledge.allow_query_key', true);
    }

    protected function bogusQueueConnection(Application $app): void
    {
        $app['config']->set('ledge.acquire_queue_connection', 'does-not-exist');
    }

    public function test_rejects_requests_without_the_key(): void
    {
        $this->postJson('/_ledge/acquire', $this->envelope($this->command()))
            ->assertStatus(401)
            ->assertExactJson(['accepted' => false, 'reason' => 'invalid_key']);

        self::assertCount(0, $this->history);
    }

    #[DefineEnvironment('queryKeyAllowed')]
    public function test_never_accepts_the_key_as_a_query_parameter(): void
    {
        $this->getJson('/_ledge/health?key='.self::KEY)->assertOk();

        $this->postJson('/_ledge/acquire?key='.self::KEY, $this->envelope($this->command()))->assertStatus(401);
        $this->getJson('/_ledge/acquire/run-1?key='.self::KEY)->assertStatus(401);
    }

    public function test_accepts_a_signed_command_and_queues_the_backup(): void
    {
        Queue::fake();
        $this->http->append($this->keysetResponse(), new Response(200));

        $this->acquire($this->envelope($this->command()))
            ->assertStatus(202)
            ->assertExactJson(['accepted' => true, 'run_id' => 'run-1']);

        Queue::assertPushed(BackupJob::class, function (BackupJob $job): bool {
            return $job->runId === 'run-1'
                && $job->uploadUrl === self::UPLOAD_URL
                && $job->callbackToken === 'callback-token'
                && $job->profile === 'backup'
                && $job->timeout === 3600;
        });

        self::assertSame('GET', $this->history[0]['request']->getMethod());
        self::assertSame('https://my.ledgehq.test/.well-known/ledge-keys', (string) $this->history[0]['request']->getUri());

        $accepted = $this->history[1]['request'];
        self::assertSame(self::CALLBACK_URL, (string) $accepted->getUri());
        self::assertSame('Bearer callback-token', $accepted->getHeaderLine('Authorization'));
        self::assertSame('acquire.accepted', json_decode((string) $accepted->getBody(), true)['event']);

        $this->getJson('/_ledge/acquire/run-1', ['X-Ledge-Key' => self::KEY])
            ->assertOk()
            ->assertJsonPath('status', AcquireService::STATUS_PENDING)
            ->assertJsonPath('step', 'queued')
            ->assertJsonPath('profile', 'backup');
    }

    public function test_replayed_command_is_rejected(): void
    {
        Queue::fake();
        $this->http->append($this->keysetResponse(), new Response(200));
        $envelope = $this->envelope($this->command());

        $this->acquire($envelope)->assertStatus(202);
        $this->acquire($envelope)->assertStatus(409)->assertJsonPath('reason', 'replayed');

        Queue::assertPushedTimes(BackupJob::class, 1);
    }

    public function test_bad_signature_and_unsupported_profile_are_rejected(): void
    {
        Queue::fake();
        $this->http->append($this->keysetResponse());

        $forged = $this->envelope($this->command(), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        $this->acquire($forged)->assertStatus(401)->assertJsonPath('reason', 'invalid_signature');

        $this->acquire($this->envelope($this->command(['profile' => 'full'])))->assertStatus(400)->assertJsonPath('reason', 'invalid_profile');

        Queue::assertNothingPushed();
    }

    public function test_keyset_outage_is_reported_as_unavailable(): void
    {
        $this->http->append(new Response(503));

        $this->acquire($this->envelope($this->command()))->assertStatus(503)->assertJsonPath('reason', 'keyset_unavailable');
    }

    #[DefineEnvironment('bogusQueueConnection')]
    public function test_queue_failure_releases_the_run(): void
    {
        $this->http->append($this->keysetResponse(), $this->keysetResponse(), new Response(200));

        $this->acquire($this->envelope($this->command()))->assertStatus(500)->assertJsonPath('reason', 'queue_unavailable');
        $this->getJson('/_ledge/acquire/run-1', ['X-Ledge-Key' => self::KEY])->assertNotFound();

        // The run_id was released, so the same command can be retried once the queue is back.
        $this->app['config']->set('ledge.acquire_queue_connection', null);
        $this->app->forgetInstance(Settings::class);
        $this->app->forgetInstance(AcquireService::class);
        Queue::fake();

        $this->acquire($this->envelope($this->command()))->assertStatus(202);
    }

    public function test_unknown_run_status_is_404(): void
    {
        $this->getJson('/_ledge/acquire/nope', ['X-Ledge-Key' => self::KEY])
            ->assertNotFound()
            ->assertExactJson(['reason' => 'unknown_run_id']);
    }

    public function test_health_advertises_the_backup_capability(): void
    {
        $this->health()
            ->assertJsonPath('acquireEnabled', true)
            ->assertJsonPath('backupProfileSupported', true)
            ->assertJsonPath('capabilities', ['backup'])
            ->assertJsonPath('updateTest.strategy', 'testsuite');
    }

    public function test_platform_layers_can_register_profiles(): void
    {
        $this->app->make(AcquireService::class)->registerProfile('full', BackupJob::class);

        $this->health()->assertJsonPath('capabilities', ['backup', 'full']);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function acquire(array $envelope): TestResponse
    {
        return $this->postJson('/_ledge/acquire', $envelope, ['X-Ledge-Key' => self::KEY]);
    }
}
