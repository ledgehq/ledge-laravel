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
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\BundleUploader;
use PHPUnit\Framework\TestCase;

final class BundleUploaderTest extends TestCase
{
    private const URL = 'https://ledge.test/api/bundles/run-123?expires=1&signature=abc';

    /** @var list<array{request: Request}> */
    private array $history = [];

    private string $bundlePath;

    protected function setUp(): void
    {
        $this->bundlePath = sys_get_temp_dir().'/ledge-upload-'.bin2hex(random_bytes(4));
        file_put_contents($this->bundlePath, 'encrypted bundle bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->bundlePath);
    }

    public function test_streams_bundle_via_put(): void
    {
        $this->uploader([new Response(201, [], '{"ok":true,"bytes":22}')])->upload($this->bundlePath, self::URL);

        self::assertCount(1, $this->history);
        $request = $this->history[0]['request'];

        self::assertSame('PUT', $request->getMethod());
        self::assertSame(self::URL, (string) $request->getUri());
        self::assertSame('application/octet-stream', $request->getHeaderLine('Content-Type'));
        self::assertSame('22', $request->getHeaderLine('Content-Length'));
    }

    public function test_any_2xx_is_success_and_body_is_ignored(): void
    {
        $uploader = $this->uploader([new Response(200), new Response(201, [], '{"ok":true}'), new Response(204, [], 'not even json')]);

        $uploader->upload($this->bundlePath, self::URL);
        $uploader->upload($this->bundlePath, self::URL);
        $uploader->upload($this->bundlePath, self::URL);

        self::assertCount(3, $this->history);
    }

    public function test_payload_too_large_maps_to_bundle_too_large(): void
    {
        $this->assertFailsWith('bundle_too_large', [new Response(413, [], '{"error":"bundle_too_large"}')]);
    }

    public function test_conflict_maps_to_run_already_finished(): void
    {
        $this->assertFailsWith('run_already_finished', [new Response(409)]);
        self::assertCount(1, $this->history);
    }

    public function test_server_error_maps_to_upload_failed(): void
    {
        $this->assertFailsWith('upload_failed', [new Response(500)]);
    }

    public function test_connection_failure_maps_to_upload_failed(): void
    {
        $this->assertFailsWith('upload_failed', [new ConnectException('refused', new Request('PUT', self::URL))]);
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function uploader(array $responses): BundleUploader
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new BundleUploader(new Client(['handler' => $stack]));
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function assertFailsWith(string $reason, array $responses): void
    {
        try {
            $this->uploader($responses)->upload($this->bundlePath, self::URL);
            self::fail("Expected AcquireException with reason \"{$reason}\"");
        } catch (AcquireException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
