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
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\KeyResolver;
use PHPUnit\Framework\TestCase;

final class KeyResolverTest extends TestCase
{
    private const BASE_URL = 'https://my.ledgehq.app';

    /** @var list<array{request: Request}> */
    private array $history = [];

    private Repository $cache;

    private string $publicKeyA;

    private string $publicKeyB;

    protected function setUp(): void
    {
        $this->cache = new Repository(new ArrayStore);
        $this->publicKeyA = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $this->publicKeyB = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    }

    public function test_fetches_keyset_on_cache_miss(): void
    {
        $resolver = $this->resolver([$this->keysetResponse(['key-a' => $this->publicKeyA])]);

        self::assertSame($this->publicKeyA, $resolver->resolve('key-a'));
        self::assertCount(1, $this->history);
        self::assertSame(self::BASE_URL.KeyResolver::KEYSET_PATH, (string) $this->history[0]['request']->getUri());
    }

    public function test_uses_cached_keyset_without_refetching(): void
    {
        $resolver = $this->resolver([$this->keysetResponse(['key-a' => $this->publicKeyA])]);

        $resolver->resolve('key-a');
        $resolver->resolve('key-a');

        self::assertCount(1, $this->history);
    }

    public function test_refetches_once_for_unknown_key_id_then_fails(): void
    {
        $resolver = $this->resolver([
            $this->keysetResponse(['key-a' => $this->publicKeyA]),
            $this->keysetResponse(['key-a' => $this->publicKeyA]),
        ]);

        $resolver->resolve('key-a');

        try {
            $resolver->resolve('key-nope');
            self::fail('Expected AcquireException with reason "unknown_key_id"');
        } catch (AcquireException $e) {
            self::assertSame('unknown_key_id', $e->reason);
            self::assertSame(401, $e->httpStatus);
        }

        self::assertCount(2, $this->history);
    }

    public function test_resolves_new_key_id_after_refetch(): void
    {
        $resolver = $this->resolver([
            $this->keysetResponse(['key-a' => $this->publicKeyA]),
            $this->keysetResponse(['key-a' => $this->publicKeyA, 'key-b' => $this->publicKeyB]),
        ]);

        self::assertSame($this->publicKeyA, $resolver->resolve('key-a'));
        self::assertSame($this->publicKeyB, $resolver->resolve('key-b'));
    }

    public function test_pins_survive_keyset_cache_expiry(): void
    {
        $resolver = $this->resolver([
            $this->keysetResponse(['key-a' => $this->publicKeyA]),
            $this->keysetResponse(['key-a' => $this->publicKeyB, 'key-b' => $this->publicKeyB]),
        ]);

        self::assertSame($this->publicKeyA, $resolver->resolve('key-a'));

        $this->cache->forget(KeyResolver::KEYSET_CACHE_KEY);

        self::assertSame($this->publicKeyA, $resolver->resolve('key-a'));
        self::assertCount(1, $this->history);

        self::assertSame($this->publicKeyB, $resolver->resolve('key-b'));
        self::assertSame($this->publicKeyA, $resolver->resolve('key-a'));
        self::assertCount(2, $this->history);
    }

    public function test_never_silently_replaces_a_pinned_key(): void
    {
        $resolver = $this->resolver([
            $this->keysetResponse(['key-a' => $this->publicKeyA]),
            $this->keysetResponse(['key-a' => $this->publicKeyB, 'key-b' => $this->publicKeyB]),
        ]);

        self::assertSame($this->publicKeyA, $resolver->resolve('key-a'));
        self::assertSame($this->publicKeyB, $resolver->resolve('key-b'));
        self::assertSame($this->publicKeyA, $resolver->resolve('key-a'));
    }

    public function test_non_ed25519_keys_are_ignored(): void
    {
        $response = new Response(200, [], (string) json_encode([
            'keys' => [['key_id' => 'key-rsa', 'alg' => 'rs256', 'public_key' => base64_encode($this->publicKeyA)]],
        ]));

        $this->assertFailsWith('unknown_key_id', $this->resolver([$response]), 'key-rsa');
    }

    public function test_keyset_endpoint_error_is_reported(): void
    {
        $this->assertFailsWith('keyset_unavailable', $this->resolver([new Response(500)]), 'key-a');
    }

    public function test_keyset_connection_failure_is_reported(): void
    {
        $failure = new ConnectException('refused', new Request('GET', self::BASE_URL.KeyResolver::KEYSET_PATH));

        $this->assertFailsWith('keyset_unavailable', $this->resolver([$failure]), 'key-a');
    }

    public function test_malformed_keyset_is_reported(): void
    {
        $this->assertFailsWith('keyset_unavailable', $this->resolver([new Response(200, [], 'not json')]), 'key-a');
    }

    public function test_invalid_public_key_material_is_rejected(): void
    {
        $response = new Response(200, [], (string) json_encode([
            'keys' => [['key_id' => 'key-a', 'alg' => 'ed25519', 'public_key' => base64_encode('too short')]],
        ]));

        $this->assertFailsWith('unknown_key_id', $this->resolver([$response]), 'key-a');
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function keysetResponse(array $keys): Response
    {
        return new Response(200, [], (string) json_encode([
            'keys' => array_map(
                fn (string $keyId, string $publicKey): array => [
                    'key_id' => $keyId,
                    'alg' => 'ed25519',
                    'public_key' => base64_encode($publicKey),
                ],
                array_keys($keys),
                $keys,
            ),
        ]));
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function resolver(array $responses): KeyResolver
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new KeyResolver(new Client(['handler' => $stack]), $this->cache, self::BASE_URL);
    }

    private function assertFailsWith(string $reason, KeyResolver $resolver, string $keyId): void
    {
        try {
            $resolver->resolve($keyId);
            self::fail("Expected AcquireException with reason \"{$reason}\"");
        } catch (AcquireException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
