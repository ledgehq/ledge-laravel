<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit;

use LedgeHQ\Ledge\DependenciesService;
use PHPUnit\Framework\TestCase;

final class DependenciesServiceTest extends TestCase
{
    /**
     * Pinned to the same value as the Craft plugin's test so `dependenciesHash`
     * means the same thing on every platform Ledge monitors.
     */
    private const FIXTURE_HASH = 'c78b87ee19f29b7b2e52d69853c219eb447b112487a34a74beef5fa57dd9d864';

    private DependenciesService $service;

    protected function setUp(): void
    {
        $this->service = new DependenciesService(sys_get_temp_dir().'/ledge-does-not-exist');
    }

    public function test_normalization_drops_root_and_versionless_lowercases_strips_v_and_sorts(): void
    {
        $packages = $this->service->normalizePackages([
            'verbb/formie' => 'v2.1.0',
            'CraftCMS/CMS' => '4.5.0',
            'acme/site' => 'dev-main',
            'some/metapackage' => null,
        ], 'acme/site');

        self::assertSame([
            ['name' => 'craftcms/cms', 'version' => '4.5.0'],
            ['name' => 'verbb/formie', 'version' => '2.1.0'],
        ], $packages);
    }

    public function test_hash_is_pinned_for_a_fixed_inventory(): void
    {
        $packages = $this->service->normalizePackages([
            'verbb/formie' => 'v2.1.0',
            'CraftCMS/CMS' => '4.5.0',
            'acme/site' => '1.0.0',
        ], 'acme/site');

        self::assertSame(self::FIXTURE_HASH, $this->service->getHash($packages));
    }

    public function test_composer_lock_hash_is_sha256_of_the_raw_bytes(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ledge-lock-');
        file_put_contents($path, "{\n    \"content-hash\": \"abc\"\n}\n");

        try {
            self::assertSame(hash('sha256', "{\n    \"content-hash\": \"abc\"\n}\n"), $this->service->getComposerLockHash($path));
        } finally {
            unlink($path);
        }
    }

    public function test_composer_lock_hash_is_null_when_the_file_is_missing(): void
    {
        self::assertNull($this->service->getComposerLockHash());
    }
}
