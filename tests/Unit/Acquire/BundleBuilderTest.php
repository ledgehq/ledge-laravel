<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\BundleBuilder;
use LedgeHQ\Ledge\Acquire\TempWorkspace;
use LedgeHQ\Ledge\Tests\Support\DecryptsBundles;
use PHPUnit\Framework\TestCase;

final class BundleBuilderTest extends TestCase
{
    use DecryptsBundles;

    private string $baseDir;

    private TempWorkspace $workspace;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir().'/ledge-bundle-'.bin2hex(random_bytes(4));
        $this->workspace = new TempWorkspace($this->baseDir, 'run-test');
        $this->workspace->create();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
        @rmdir($this->baseDir);
    }

    public function test_bundle_roundtrip(): void
    {
        $keypair = sodium_crypto_box_keypair();
        $dumpContent = str_repeat("INSERT INTO users VALUES ('hello world');\n", 500);
        $dumpPath = $this->workspace->path('dump.sql');
        file_put_contents($dumpPath, $dumpContent);

        $sidecarPath = $this->workspace->path('manifest.json');
        file_put_contents($sidecarPath, '{"a":1}');

        $result = (new BundleBuilder)->build(
            ['dump.sql' => $dumpPath, 'manifest.json' => $sidecarPath],
            sodium_crypto_box_publickey($keypair),
            PHP_INT_MAX,
            $this->workspace,
        );

        self::assertFileExists($result->path);
        self::assertSame(filesize($result->path), $result->size);
        self::assertSame(hash_file('sha256', $result->path), $result->sha256);
        self::assertGreaterThan(0, $result->uncompressedSize);

        $files = $this->decryptBundle((string) file_get_contents($result->path), $keypair, $this->workspace->path('extract'));

        self::assertSame(['dump.sql' => $dumpContent, 'manifest.json' => '{"a":1}'], $files);
    }

    public function test_database_only_bundle_contains_just_the_dump(): void
    {
        $keypair = sodium_crypto_box_keypair();
        $dumpPath = $this->workspace->path('dump.sql');
        file_put_contents($dumpPath, 'DUMP');

        $result = (new BundleBuilder)->build(['dump.sql' => $dumpPath], sodium_crypto_box_publickey($keypair), PHP_INT_MAX, $this->workspace);

        $files = $this->decryptBundle((string) file_get_contents($result->path), $keypair, $this->workspace->path('extract'));

        self::assertSame(['dump.sql' => 'DUMP'], $files);
    }

    public function test_oversized_bundle_fails_before_encryption(): void
    {
        $keypair = sodium_crypto_box_keypair();
        $dumpPath = $this->workspace->path('dump.sql');
        file_put_contents($dumpPath, random_bytes(100000));

        try {
            (new BundleBuilder)->build(['dump.sql' => $dumpPath], sodium_crypto_box_publickey($keypair), 10, $this->workspace);
            self::fail('Expected AcquireException with reason "bundle_too_large"');
        } catch (AcquireException $e) {
            self::assertSame('bundle_too_large', $e->reason);
        }

        self::assertFileDoesNotExist($this->workspace->path('bundle.tar.gz.enc'));
    }
}
