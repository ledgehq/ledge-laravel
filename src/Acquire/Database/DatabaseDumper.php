<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire\Database;

use LedgeHQ\Ledge\Acquire\AcquireException;

/**
 * Produces the plaintext SQL dump a backup bundle is built from. The base
 * package binds SpatieDatabaseDumper; a platform layer (Craft 6) may bind its
 * own pipeline instead.
 */
interface DatabaseDumper
{
    /**
     * Whether a dump can be attempted at all (the dump binary resolves, the
     * driver is supported). Checked by preflight before any work starts.
     */
    public function isAvailable(): bool;

    /**
     * Writes the dump to $path.
     *
     * @throws AcquireException with reason `dump_failed`
     */
    public function dump(string $path): void;
}
