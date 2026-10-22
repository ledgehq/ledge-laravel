<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LedgeHQ\Ledge\Acquire\CallbackClient;
use PHPUnit\Framework\TestCase;

final class CallbackClientTest extends TestCase
{
    private const URL = 'https://app.ledgehq.app/api/acquire/callback';

    private const TOKEN = 'callback-token';

    /** @var list<array{request: Request}> */
    private array $history = [];

    public function test_sends_authorized_json_callback(): void
    {
        $this->client([new Response(200)])->send(CallbackClient::EVENT_PROGRESS, 'dump');

        self::assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::URL, (string) $request->getUri());
        self::assertSame('Bearer '.self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame(['event' => 'acquire.progress', 'step' => 'dump', 'detail' => null], json_decode((string) $request->getBody(), true));
    }

    public function test_sends_detail_payload(): void
    {
        $this->client([new Response(200)])->send(CallbackClient::EVENT_COMPLETED, 'upload', ['size' => 42, 'sha256' => 'abc']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertSame(['size' => 42, 'sha256' => 'abc'], $body['detail']);
    }

    public function test_retries_once_on_server_error(): void
    {
        $this->client([new Response(500), new Response(200)])->send(CallbackClient::EVENT_STARTED, 'preflight');

        self::assertCount(2, $this->history);
    }

    public function test_retries_once_on_connection_failure(): void
    {
        $this->client([new ConnectException('refused', new Request('POST', self::URL)), new Response(200)])
            ->send(CallbackClient::EVENT_STARTED, 'preflight');

        self::assertCount(2, $this->history);
    }

    public function test_does_not_retry_on_client_error(): void
    {
        $this->client([new Response(403)])->send(CallbackClient::EVENT_STARTED, 'preflight');

        self::assertCount(1, $this->history);
    }

    public function test_does_not_retry_on_finished_run_conflict(): void
    {
        $this->client([new Response(409)])->send(CallbackClient::EVENT_PROGRESS, 'dump');

        self::assertCount(1, $this->history);
    }

    public function test_never_throws_when_all_attempts_fail(): void
    {
        $this->client([
            new ConnectException('refused', new Request('POST', self::URL)),
            new ConnectException('refused', new Request('POST', self::URL)),
        ])->send(CallbackClient::EVENT_FAILED, 'upload', ['reason' => 'upload_failed']);

        self::assertCount(2, $this->history);
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function client(array $responses): CallbackClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new CallbackClient(new Client(['handler' => $stack]), self::URL, self::TOKEN);
    }
}
