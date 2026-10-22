<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Support;

use LedgeHQ\Ledge\Acquire\BundleBuilder;
use PharData;

/**
 * Mirrors the decrypt logic the Ledge runner implements: unseal the symmetric
 * key, stream-decrypt, gunzip, untar.
 */
trait DecryptsBundles
{
    /**
     * @param  string  $keypair  the crypto_box keypair whose public half the bundle was sealed to
     * @return array<string, string> archive-relative name => contents, sorted by name
     */
    protected function decryptBundle(string $encrypted, string $keypair, string $extractDir): array
    {
        $offset = 0;
        $read = function (int $length) use ($encrypted, &$offset): string {
            $chunk = substr($encrypted, $offset, $length);
            $offset += strlen($chunk);

            return $chunk;
        };

        $key = sodium_crypto_box_seal_open($read(SODIUM_CRYPTO_BOX_SEALBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES), $keypair);
        self::assertNotFalse($key, 'sealed key must open with the bundle keypair');

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($read(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
        $archive = '';

        do {
            [$plain, $tag] = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state,
                $read(BundleBuilder::CHUNK_SIZE + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES),
            );
            $archive .= $plain;
        } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);

        self::assertSame(strlen($encrypted), $offset, 'no trailing bytes after the final chunk');

        @mkdir($extractDir, 0700, true);
        $tgzPath = $extractDir.'.tar.gz';
        file_put_contents($tgzPath, $archive);
        (new PharData($tgzPath))->extractTo($extractDir, null, true);
        @unlink($tgzPath);

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($extractDir) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
