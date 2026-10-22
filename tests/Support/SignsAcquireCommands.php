<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Support;

use GuzzleHttp\Psr7\Response;
use LedgeHQ\Ledge\Acquire\CommandVerifier;

/**
 * Plays the Ledge service: owns an Ed25519 signing key, publishes it as a
 * keyset document, and signs acquire commands with it.
 */
trait SignsAcquireCommands
{
    protected const KEY_ID = 'test-key';

    protected const UPLOAD_URL = 'https://storage.ledgehq.test/bundles/run-1';

    protected const CALLBACK_URL = 'https://app.ledgehq.test/api/acquire/callback';

    protected string $signingSecretKey;

    protected string $signingPublicKey;

    protected string $bundleKeypair;

    protected function generateKeys(): void
    {
        $signing = sodium_crypto_sign_keypair();
        $this->signingSecretKey = sodium_crypto_sign_secretkey($signing);
        $this->signingPublicKey = sodium_crypto_sign_publickey($signing);
        $this->bundleKeypair = sodium_crypto_box_keypair();
    }

    protected function keysetResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'keys' => [['key_id' => self::KEY_ID, 'alg' => 'ed25519', 'public_key' => base64_encode($this->signingPublicKey)]],
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function command(array $overrides = []): array
    {
        return array_merge([
            'run_id' => 'run-1',
            'expires_at' => gmdate(DATE_ATOM, time() + 600),
            'upload_url' => self::UPLOAD_URL,
            'bundle_pubkey' => base64_encode(sodium_crypto_box_publickey($this->bundleKeypair)),
            'profile' => 'backup',
            'callback_url' => self::CALLBACK_URL,
            'callback_token' => 'callback-token',
            'key_id' => self::KEY_ID,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array{command: array<string, mixed>, signature: string}
     */
    protected function envelope(array $command, ?string $secretKey = null): array
    {
        return [
            'command' => $command,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                CommandVerifier::canonicalize($command),
                $secretKey ?? $this->signingSecretKey,
            )),
        ];
    }
}
