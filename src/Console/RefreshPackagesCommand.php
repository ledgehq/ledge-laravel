<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Console;

use Illuminate\Console\Command;
use LedgeHQ\Ledge\DependenciesService;
use LedgeHQ\Ledge\Support\PackagesStore;
use LedgeHQ\Ledge\Support\PackageVersions;
use LedgeHQ\Ledge\Support\PackagistClient;

/**
 * Compares every installed Composer package with Packagist and stores the
 * result for the `packages` health check. Scheduled daily by the service
 * provider when `packages_auto_refresh` is on; safe to run by hand.
 */
class RefreshPackagesCommand extends Command
{
    protected $signature = 'ledge:packages:refresh';

    protected $description = 'Refresh the outdated-package snapshot the Ledge packages check reports';

    public function handle(DependenciesService $dependencies, PackagistClient $packagist, PackagesStore $store): int
    {
        $installed = $dependencies->getPackages();
        $versions = $packagist->versions(array_column($installed, 'name'));

        $rows = [];
        $errors = 0;
        $outdated = 0;

        foreach ($installed as ['name' => $name, 'version' => $version]) {
            $known = $versions[$name] ?? null;

            if ($known === null) {
                $errors++;
            }

            $latest = $known === null ? null : PackageVersions::latestStable($known);
            $updateAvailable = PackageVersions::isOutdated($version, $latest);
            $outdated += $updateAvailable ? 1 : 0;

            $rows[] = [
                'name' => $name,
                'installed' => $version,
                'latest' => $latest,
                'updateAvailable' => $updateAvailable,
            ];
        }

        $store->put($rows, $errors);

        $this->info(sprintf('%d packages checked, %d outdated, %d not found on Packagist.', count($rows), $outdated, $errors));

        return self::SUCCESS;
    }
}
