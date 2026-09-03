<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Settings;

/**
 * Writability of the local directories Laravel needs at runtime. Directories
 * that are optional for some app shapes (sessions on an API-only app) are
 * only checked when they exist.
 */
class StorageCheck implements CheckInterface
{
    private const REQUIRED = ['storage/logs', 'storage/framework/cache', 'storage/framework/views', 'bootstrap/cache'];

    private const OPTIONAL = ['storage/framework/sessions', 'storage/app'];

    public function __construct(
        private readonly Application $app,
        private readonly Settings $settings,
    ) {}

    public function getName(): string
    {
        return 'storage';
    }

    public function run(): ?CheckResult
    {
        $unwritable = [];
        $checked = [];

        foreach (self::REQUIRED as $relative) {
            $checked[] = $relative;

            if (! is_dir($this->app->basePath($relative)) || ! is_writable($this->app->basePath($relative))) {
                $unwritable[] = $relative;
            }
        }

        foreach (self::OPTIONAL as $relative) {
            $path = $this->app->basePath($relative);

            if (! is_dir($path)) {
                continue;
            }

            $checked[] = $relative;

            if (! is_writable($path)) {
                $unwritable[] = $relative;
            }
        }

        $status = $this->settings->storageUnwritableStatus;
        $meta = [
            'unwritable' => $unwritable,
            'checked' => $checked,
            'thresholds' => ['storageUnwritableStatus' => $status],
        ];

        if ($unwritable !== [] && $status !== CheckResult::STATUS_HEALTHY) {
            return new CheckResult($this->getName(), $status, $meta, count($unwritable).' directory(ies) not writable');
        }

        return CheckResult::healthy($this->getName(), $meta);
    }
}
