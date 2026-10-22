<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Feature;

use LedgeHQ\Ledge\Tests\TestCase;

/**
 * The default configuration: acquire is off, so the routes do not exist and
 * the health payload advertises no capabilities.
 */
final class AcquireDisabledTest extends TestCase
{
    public function test_routes_are_not_registered_while_disabled(): void
    {
        $this->postJson('/_ledge/acquire', ['command' => [], 'signature' => ''], ['X-Ledge-Key' => self::KEY])->assertNotFound();
        $this->getJson('/_ledge/acquire/run-1', ['X-Ledge-Key' => self::KEY])->assertNotFound();
    }

    public function test_health_advertises_no_capabilities(): void
    {
        $this->health()
            ->assertJsonPath('acquireEnabled', false)
            ->assertJsonPath('backupProfileSupported', false)
            ->assertJsonPath('capabilities', [])
            ->assertJsonPath('updateTest.strategy', 'testsuite');
    }
}
