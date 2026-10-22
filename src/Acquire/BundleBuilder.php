<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use PharData;
use Throwable;

/**
 * Produces the encrypted bundle file:
 * [crypto_box_seal(symmetric key) 80B][secretstream header 24B][secretstream chunks].
 *
 * The symmetric key is sealed to the command's bundle_pubkey so only the runner
 * holding the matching secret key can decrypt; the archive itself is streamed
 * through secretstream so memory use is constant regardless of bundle size.
 */
class BundleBuilder
{
    public const CHUNK_SIZE = 1048576;

    /**
     * @param  array<string, string>  $entries  archive path (relative) => absolute
     *                                          source file path; e.g. `dump.sql` alone (backup). Insertion order is
     *                                          preserved.
     */
    public function build(
        array $entries,
        string $bundlePubkeyBytes,
        int $maxBytes,
        TempWorkspace $workspace,
    ): BundleResult {
        [$archivePath, $uncompressedSize] = $this->createArchive($entries, $workspace);
        $archiveSize = filesize($archivePath);

        if ($archiveSize === false) {
            throw new AcquireException('archive_failed', 500, 'unable to stat archive');
        }

        if ($archiveSize > $maxBytes) {
            throw new AcquireException('bundle_too_large', 500, sprintf('%d bytes exceeds cap of %d', $archiveSize, $maxBytes));
        }

        $encryptedPath = $this->encrypt($archivePath, $bundlePubkeyBytes, $workspace);
        $size = filesize($encryptedPath);
        $sha256 = hash_file('sha256', $encryptedPath);

        if ($size === false || $sha256 === false) {
            throw new AcquireException('encrypt_failed', 500, 'unable to stat encrypted bundle');
        }

        return new BundleResult($encryptedPath, $size, $sha256, $uncompressedSize);
    }

    /**
     * @param  array<string, string>  $entries
     * @return array{0: string, 1: int} gzip path and uncompressed tar size
     */
    private function createArchive(array $entries, TempWorkspace $workspace): array
    {
        $tarPath = $workspace->path('bundle.tar');

        try {
            $tar = new PharData($tarPath);
            foreach ($entries as $archiveName => $sourcePath) {
                $tar->addFile($sourcePath, $archiveName);
            }
            unset($tar);
        } catch (Throwable $e) {
            throw new AcquireException('archive_failed', 500, $e->getMessage());
        }

        $uncompressedSize = filesize($tarPath);

        if ($uncompressedSize === false) {
            throw new AcquireException('archive_failed', 500, 'unable to stat archive');
        }

        $gzPath = $workspace->path('bundle.tar.gz');
        $in = @fopen($tarPath, 'rb');
        $out = @gzopen($gzPath, 'wb6');

        if ($in === false || $out === false) {
            throw new AcquireException('archive_failed', 500, 'unable to open archive streams');
        }

        while (! feof($in)) {
            $chunk = fread($in, self::CHUNK_SIZE);
            if ($chunk === false) {
                fclose($in);
                gzclose($out);
                throw new AcquireException('archive_failed', 500, 'failed reading tar stream');
            }
            if ($chunk !== '' && gzwrite($out, $chunk) === false) {
                fclose($in);
                gzclose($out);
                throw new AcquireException('archive_failed', 500, 'failed writing gzip stream');
            }
        }

        fclose($in);
        gzclose($out);
        @unlink($tarPath);

        return [$gzPath, $uncompressedSize];
    }

    private function encrypt(string $archivePath, string $bundlePubkeyBytes, TempWorkspace $workspace): string
    {
        $encryptedPath = $workspace->path('bundle.tar.gz.enc');
        $in = @fopen($archivePath, 'rb');
        $out = @fopen($encryptedPath, 'wb');

        if ($in === false || $out === false) {
            throw new AcquireException('encrypt_failed', 500, 'unable to open encryption streams');
        }

        try {
            $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
            $this->writeAll($out, sodium_crypto_box_seal($key, $bundlePubkeyBytes));

            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            sodium_memzero($key);
            $this->writeAll($out, $header);

            $chunk = fread($in, self::CHUNK_SIZE);
            if ($chunk === false) {
                throw new AcquireException('encrypt_failed', 500, 'failed reading archive stream');
            }

            while (true) {
                $next = feof($in) ? '' : fread($in, self::CHUNK_SIZE);
                if ($next === false) {
                    throw new AcquireException('encrypt_failed', 500, 'failed reading archive stream');
                }

                $tag = $next === ''
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $this->writeAll($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));

                if ($next === '') {
                    break;
                }

                $chunk = $next;
            }
        } catch (AcquireException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AcquireException('encrypt_failed', 500, $e->getMessage());
        } finally {
            fclose($in);
            fclose($out);
        }

        @unlink($archivePath);

        return $encryptedPath;
    }

    /**
     * @param  resource  $handle
     */
    private function writeAll($handle, string $data): void
    {
        if ($data === '') {
            return;
        }

        $written = fwrite($handle, $data);

        if ($written !== strlen($data)) {
            throw new AcquireException('encrypt_failed', 500, 'failed writing encrypted stream');
        }
    }
}
