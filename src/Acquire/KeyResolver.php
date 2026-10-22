<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Resolves Ledge signing keys from the well-known keyset endpoint.
 *
 * The base URL is trusted config only — it must never be derived from an
 * incoming command, or an attacker could point discovery at their own keyset.
 *
 * Learned key_id → public_key pins never expire: a key_id that was ever seen
 * maps to its original key material forever, and a later fetch that disagrees
 * is ignored for that entry — even after the keyset cache expires. Rotation
 * always arrives as a new key_id. The TTL'd keyset entry only records the
 * last fetched document; it never overrides a pin.
 */
class KeyResolver
{
    public const KEYSET_PATH = '/.well-known/ledge-keys';

    public const PINS_CACHE_KEY = 'ledge.acquire.keypins';

    public const KEYSET_CACHE_KEY = 'ledge.acquire.keyset';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly CacheRepository $cache,
        private readonly string $baseUrl,
        private readonly int $keysetTtl = 86400,
    ) {}

    public function resolve(string $keyId): string
    {
        $pins = $this->getPins();

        if (! isset($pins[$keyId])) {
            $pins = $this->refresh($pins);
        }

        if (! isset($pins[$keyId])) {
            throw new AcquireException('unknown_key_id', 401, "key \"{$keyId}\" is not in the Ledge keyset");
        }

        $bytes = base64_decode($pins[$keyId], true);

        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new AcquireException('unknown_key_id', 401, "key \"{$keyId}\" is not a valid Ed25519 public key");
        }

        return $bytes;
    }

    /**
     * @return array<string, string>
     */
    private function getPins(): array
    {
        $pins = $this->cache->get(self::PINS_CACHE_KEY);

        return is_array($pins) ? $pins : [];
    }

    /**
     * @param  array<string, string>  $pins
     * @return array<string, string>
     */
    private function refresh(array $pins): array
    {
        $fetched = $this->fetchKeyset();

        foreach ($fetched as $keyId => $publicKey) {
            if (isset($pins[$keyId]) && $pins[$keyId] !== $publicKey) {
                continue;
            }

            $pins[$keyId] = $publicKey;
        }

        $this->cache->forever(self::PINS_CACHE_KEY, $pins);
        $this->cache->put(self::KEYSET_CACHE_KEY, $fetched, $this->keysetTtl);

        return $pins;
    }

    /**
     * @return array<string, string>
     */
    private function fetchKeyset(): array
    {
        try {
            $response = $this->client->request('GET', rtrim($this->baseUrl, '/').self::KEYSET_PATH, [
                'timeout' => 5,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (Throwable $e) {
            throw new AcquireException('keyset_unavailable', 503, $e->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            throw new AcquireException('keyset_unavailable', 503, "keyset endpoint responded with HTTP {$response->getStatusCode()}");
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data) || ! is_array($data['keys'] ?? null)) {
            throw new AcquireException('keyset_unavailable', 503, 'malformed keyset response');
        }

        $fetched = [];

        foreach ($data['keys'] as $key) {
            if (! is_array($key) || ! is_string($key['key_id'] ?? null) || ! is_string($key['public_key'] ?? null)) {
                continue;
            }

            if (($key['alg'] ?? null) !== 'ed25519') {
                continue;
            }

            $fetched[$key['key_id']] = $key['public_key'];
        }

        return $fetched;
    }
}
