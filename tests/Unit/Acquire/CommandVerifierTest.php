<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\CommandVerifier;
use LedgeHQ\Ledge\Acquire\HostAllowlist;
use PHPUnit\Framework\TestCase;

final class CommandVerifierTest extends TestCase
{
    private const ALLOWED_HOSTS = ['storage.ledgehq.app', 'app.ledgehq.app'];

    private const KEY_ID = 'a1b2c3d4';

    private const NOW = 1000;

    private string $publicKey;

    private string $secretKey;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($keypair);
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    }

    public function test_valid_command_verifies(): void
    {
        $command = $this->verifier()->verify($this->body($this->command()));

        self::assertSame('run-123', $command->runId);
        self::assertSame(self::NOW + 600, $command->expiresAt);
        self::assertSame('https://storage.ledgehq.app/bundles/run-123', $command->uploadUrl);
        self::assertSame('backup', $command->profile);
        self::assertSame('https://app.ledgehq.app/api/acquire/callback', $command->callbackUrl);
        self::assertSame('callback-token', $command->callbackToken);
        self::assertSame(self::KEY_ID, $command->keyId);
        self::assertNotNull($command->getBundlePubkeyBytes());
    }

    public function test_key_order_does_not_matter(): void
    {
        $command = array_reverse($this->command(), true);

        self::assertSame('run-123', $this->verifier()->verify($this->body($command))->runId);
    }

    public function test_callback_token_is_not_covered_by_signature(): void
    {
        $command = $this->command();
        $signature = $this->sign($command);
        $command['callback_token'] = 'rotated-token';

        self::assertSame('rotated-token', $this->verifier()->verify($this->body($command, $signature))->callbackToken);
    }

    public function test_signature_from_wrong_key_is_rejected(): void
    {
        $command = $this->command();
        $signature = base64_encode(sodium_crypto_sign_detached(
            CommandVerifier::canonicalize($command),
            sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()),
        ));

        $this->assertRejected($this->body($command, $signature), 'invalid_signature', 401);
    }

    public function test_tampered_command_is_rejected(): void
    {
        $command = $this->command();
        $signature = $this->sign($command);
        $command['upload_url'] = 'https://storage.ledgehq.app/bundles/run-666';

        $this->assertRejected($this->body($command, $signature), 'invalid_signature', 401);
    }

    public function test_unknown_key_id_is_rejected(): void
    {
        $this->assertRejected($this->body($this->command(['key_id' => 'unknown-key'])), 'unknown_key_id', 401);
    }

    public function test_malformed_envelope_is_rejected(): void
    {
        $this->assertRejected('not json', 'invalid_envelope', 400);
        $this->assertRejected('{"command": {}}', 'invalid_envelope', 400);
        $this->assertRejected('{"command": "flat", "signature": "x"}', 'invalid_envelope', 400);
        $this->assertRejected(
            (string) json_encode(['command' => $this->command(), 'signature' => base64_encode('short')]),
            'invalid_envelope',
            400,
        );
    }

    public function test_expired_command_is_rejected(): void
    {
        $this->assertRejected($this->body($this->command(['expires_at' => $this->iso(self::NOW)])), 'expired', 403);
    }

    public function test_invalid_expires_at_is_rejected(): void
    {
        $this->assertRejected($this->body($this->command(['expires_at' => 'not-a-date'])), 'invalid_payload', 400);
    }

    public function test_missing_field_is_rejected(): void
    {
        $command = $this->command();
        unset($command['key_id']);

        $this->assertRejected($this->body($command), 'invalid_payload', 400);
    }

    public function test_invalid_run_id_is_rejected(): void
    {
        $this->assertRejected($this->body($this->command(['run_id' => 'no spaces allowed!'])), 'invalid_payload', 400);
    }

    public function test_invalid_bundle_pubkey_is_rejected(): void
    {
        $this->assertRejected($this->body($this->command(['bundle_pubkey' => base64_encode('too short')])), 'invalid_payload', 400);
    }

    public function test_only_registered_profiles_are_accepted(): void
    {
        $this->assertRejected($this->body($this->command(['profile' => 'full'])), 'invalid_profile', 400);

        $command = $this->verifier(['backup', 'full'])->verify($this->body($this->command(['profile' => 'full'])));

        self::assertSame('full', $command->profile);
    }

    public function test_disallowed_upload_host_is_rejected(): void
    {
        $this->assertRejected($this->body($this->command(['upload_url' => 'https://evil.example.com/bundles/run-123'])), 'host_not_allowed', 403);
    }

    public function test_disallowed_callback_host_is_rejected(): void
    {
        $this->assertRejected($this->body($this->command(['callback_url' => 'https://evil.example.com/callback'])), 'host_not_allowed', 403);
    }

    public function test_insecure_upload_scheme_is_rejected(): void
    {
        $this->assertRejected($this->body($this->command(['upload_url' => 'http://storage.ledgehq.app/bundles/run-123'])), 'host_not_allowed', 403);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function command(array $overrides = []): array
    {
        return array_merge([
            'run_id' => 'run-123',
            'expires_at' => $this->iso(self::NOW + 600),
            'upload_url' => 'https://storage.ledgehq.app/bundles/run-123',
            'bundle_pubkey' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
            'profile' => 'backup',
            'callback_url' => 'https://app.ledgehq.app/api/acquire/callback',
            'callback_token' => 'callback-token',
            'key_id' => self::KEY_ID,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function sign(array $command): string
    {
        return base64_encode(sodium_crypto_sign_detached(CommandVerifier::canonicalize($command), $this->secretKey));
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function body(array $command, ?string $signature = null): string
    {
        return (string) json_encode([
            'command' => $command,
            'signature' => $signature ?? $this->sign($command),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function iso(int $timestamp): string
    {
        return date(DATE_ATOM, $timestamp);
    }

    /**
     * @param  list<string>  $profiles
     */
    private function verifier(array $profiles = ['backup']): CommandVerifier
    {
        return new CommandVerifier(
            function (string $keyId): string {
                if ($keyId !== self::KEY_ID) {
                    throw new AcquireException('unknown_key_id', 401);
                }

                return $this->publicKey;
            },
            new HostAllowlist(self::ALLOWED_HOSTS),
            $profiles,
            fn (): int => self::NOW,
        );
    }

    private function assertRejected(string $envelope, string $reason, int $httpStatus): void
    {
        try {
            $this->verifier()->verify($envelope);
            self::fail("Expected AcquireException with reason \"{$reason}\"");
        } catch (AcquireException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($httpStatus, $e->httpStatus);
        }
    }
}
