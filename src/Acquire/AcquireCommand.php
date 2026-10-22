<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

class AcquireCommand
{
    public function __construct(
        public readonly string $runId,
        public readonly int $expiresAt,
        public readonly string $uploadUrl,
        public readonly string $bundlePubkey,
        public readonly string $profile,
        public readonly string $callbackUrl,
        public readonly string $callbackToken,
        public readonly string $keyId,
    ) {}

    public function getBundlePubkeyBytes(): ?string
    {
        $bytes = base64_decode($this->bundlePubkey, true);

        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            return null;
        }

        return $bytes;
    }
}
