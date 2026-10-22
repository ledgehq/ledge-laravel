<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Foundation\Application;
use LedgeHQ\Ledge\Settings;
use LedgeHQ\Ledge\Support\PackagesStore;
use Throwable;

/**
 * Outdated Composer packages, from the snapshot `ledge:packages:refresh`
 * stores. The health poll itself never talks to Packagist. Until the first
 * refresh the check is healthy with `refreshedAt: null`.
 *
 * `frameworkVersion` mirrors the Craft plugin's `craftVersion` check so the
 * Ledge dashboard can show the platform version in the same place. Its
 * `installed` value comes from the running application, so it is present
 * before the first refresh; `latest` and `updateAvailable` fill in after.
 */
class PackagesCheck implements CheckInterface
{
    public const FRAMEWORK = 'laravel/framework';

    public function __construct(
        private readonly Application $app,
        private readonly PackagesStore $store,
        private readonly Settings $settings,
    ) {}

    public function getName(): string
    {
        return 'packages';
    }

    public function run(): ?CheckResult
    {
        $updateStatus = $this->settings->updateAvailableStatus;
        $snapshot = $this->store->get();

        $meta = [
            'refreshedAt' => null,
            'total' => null,
            'outdatedCount' => null,
            'outdated' => [],
            'notOnPackagist' => null,
            'frameworkVersion' => ['installed' => $this->appVersion(), 'latest' => null, 'updateAvailable' => false],
            'thresholds' => ['updateAvailableStatus' => $updateStatus],
        ];

        if ($snapshot === null) {
            return CheckResult::healthy($this->getName(), $meta, 'Run `php artisan ledge:packages:refresh` to populate this check');
        }

        $outdated = [];

        foreach ($snapshot['packages'] as $package) {
            if ($package['name'] === self::FRAMEWORK) {
                $meta['frameworkVersion'] = [
                    'installed' => $meta['frameworkVersion']['installed'] ?? $package['installed'],
                    'latest' => $package['latest'],
                    'updateAvailable' => $package['updateAvailable'],
                ];
            }

            if ($package['updateAvailable']) {
                $outdated[] = ['name' => $package['name'], 'installed' => $package['installed'], 'latest' => $package['latest']];
            }
        }

        $meta['refreshedAt'] = $snapshot['refreshedAt'];
        $meta['total'] = count($snapshot['packages']);
        $meta['outdatedCount'] = count($outdated);
        $meta['outdated'] = $outdated;
        $meta['notOnPackagist'] = $snapshot['errors'];

        if ($outdated !== [] && $updateStatus !== CheckResult::STATUS_HEALTHY) {
            return new CheckResult($this->getName(), $updateStatus, $meta, count($outdated).' package(s) have updates available');
        }

        return CheckResult::healthy($this->getName(), $meta);
    }

    private function appVersion(): ?string
    {
        try {
            return ltrim($this->app->version(), 'v');
        } catch (Throwable) {
            return null;
        }
    }
}
