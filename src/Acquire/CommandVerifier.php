<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use Closure;
use DateTimeImmutable;
use SodiumException;
use Throwable;

/**
 * Verifies the signed acquire command: {command: {...}, signature: "<base64>"}.
 *
 * The Ed25519 signature covers the canonical JSON of the command WITHOUT
 * callback_token — recursively sorted keys, JSON_UNESCAPED_SLASHES |
 * JSON_UNESCAPED_UNICODE — so both sides serialize identically.
 */
class CommandVerifier
{
    private const RUN_ID_PATTERN = '/^[A-Za-z0-9\-]{1,64}$/';

    private const REQUIRED_FIELDS = [
        'run_id',
        'expires_at',
        'upload_url',
        'bundle_pubkey',
        'profile',
        'callback_url',
        'callback_token',
        'key_id',
    ];

    /**
     * @param  Closure(string): string  $resolveKey  returns Ed25519 public key bytes for a key_id
     * @param  list<string>  $supportedProfiles
     * @param  (Closure(): int)|null  $now
     */
    public function __construct(
        private readonly Closure $resolveKey,
        private readonly HostAllowlist $allowlist,
        private readonly array $supportedProfiles,
        private readonly ?Closure $now = null,
    ) {}

    public function verify(string $rawBody): AcquireCommand
    {
        $body = json_decode($rawBody, true);

        if (! is_array($body) || ! is_array($body['command'] ?? null) || ! is_string($body['signature'] ?? null)) {
            throw new AcquireException('invalid_envelope', 400);
        }

        $signature = base64_decode($body['signature'], true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new AcquireException('invalid_envelope', 400);
        }

        $command = $this->parseCommand($body['command']);
        $publicKey = ($this->resolveKey)($command->keyId);

        try {
            $valid = sodium_crypto_sign_verify_detached($signature, self::canonicalize($body['command']), $publicKey);
        } catch (SodiumException) {
            $valid = false;
        }

        if (! $valid) {
            throw new AcquireException('invalid_signature', 401);
        }

        if ($command->expiresAt <= $this->currentTime()) {
            throw new AcquireException('expired', 403);
        }

        if (! in_array($command->profile, $this->supportedProfiles, true)) {
            throw new AcquireException('invalid_profile', 400, "unsupported profile \"{$command->profile}\"");
        }

        foreach (['upload_url' => $command->uploadUrl, 'callback_url' => $command->callbackUrl] as $field => $url) {
            if (! $this->allowlist->allows($url)) {
                throw new AcquireException('host_not_allowed', 403, "{$field} host is not in the allowlist");
            }
        }

        return $command;
    }

    /**
     * @param  array<string, mixed>  $command
     */
    public static function canonicalize(array $command): string
    {
        unset($command['callback_token']);

        return (string) json_encode(self::sortKeysRecursively($command), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function sortKeysRecursively(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeysRecursively($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function parseCommand(array $data): AcquireCommand
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! isset($data[$field])) {
                throw new AcquireException('invalid_payload', 400, "missing field \"{$field}\"");
            }

            if (! is_string($data[$field]) || $data[$field] === '') {
                throw new AcquireException('invalid_payload', 400, "field \"{$field}\" must be a non-empty string");
            }
        }

        if (! preg_match(self::RUN_ID_PATTERN, $data['run_id'])) {
            throw new AcquireException('invalid_payload', 400, 'run_id must match '.self::RUN_ID_PATTERN);
        }

        try {
            $expiresAt = (new DateTimeImmutable($data['expires_at']))->getTimestamp();
        } catch (Throwable) {
            throw new AcquireException('invalid_payload', 400, 'expires_at must be an ISO8601 datetime');
        }

        $command = new AcquireCommand(
            $data['run_id'],
            $expiresAt,
            $data['upload_url'],
            $data['bundle_pubkey'],
            $data['profile'],
            $data['callback_url'],
            $data['callback_token'],
            $data['key_id'],
        );

        if ($command->getBundlePubkeyBytes() === null) {
            throw new AcquireException('invalid_payload', 400, 'bundle_pubkey must be a base64-encoded X25519 public key');
        }

        return $command;
    }

    private function currentTime(): int
    {
        return $this->now !== null ? ($this->now)() : time();
    }
}
