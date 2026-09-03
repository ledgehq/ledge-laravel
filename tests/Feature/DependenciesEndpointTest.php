<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class DependenciesEndpointTest extends TestCase
{
    public function test_requires_the_key(): void
    {
        $this->getJson('/_ledge/dependencies')->assertStatus(401);
    }

    public function test_returns_the_inventory_and_the_same_hash_as_health(): void
    {
        $dependencies = $this->getJson('/_ledge/dependencies', ['X-Ledge-Key' => self::KEY])
            ->assertOk()
            ->assertJsonStructure(['composer' => [['name', 'version']], 'generatedAt', 'hash']);

        $names = array_column($dependencies->json('composer'), 'name');
        self::assertContains('laravel/framework', $names);
        self::assertNotContains('ledgehq/ledge-laravel', $names);

        $this->health()->assertJsonPath('dependenciesHash', $dependencies->json('hash'));
    }

    #[DefineEnvironment('dependenciesOff')]
    public function test_disabled_endpoint_is_a_404_and_hashes_are_omitted(): void
    {
        $this->getJson('/_ledge/dependencies', ['X-Ledge-Key' => self::KEY])->assertStatus(404);
        $this->health()->assertOk()->assertJsonMissingPath('dependenciesHash')->assertJsonMissingPath('composerLockHash');
    }

    protected function dependenciesOff(Application $app): void
    {
        $app['config']->set('ledge.dependencies_enabled', false);
    }
}
