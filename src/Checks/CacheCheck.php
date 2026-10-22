<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use LedgeHQ\Ledge\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Write / read / delete roundtrip on the default cache store.
 */
class CacheCheck implements CheckInterface
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'cache';
    }

    public function run(): ?CheckResult
    {
        $store = (string) $this->config->get('cache.default', '');
        $failureStatus = $this->settings->cacheFailureStatus;

        $meta = [
            'store' => $store,
            'driver' => $this->config->get("cache.stores.{$store}.driver"),
            'roundtripMs' => null,
            'thresholds' => ['failureStatus' => $failureStatus],
        ];

        $key = 'ledge:cache-check:'.bin2hex(random_bytes(4));
        $value = bin2hex(random_bytes(8));
        $start = microtime(true);

        try {
            $repository = $this->cache->store();
            $repository->put($key, $value, 60);
            $read = $repository->get($key);
            $repository->forget($key);
            $meta['roundtripMs'] = round((microtime(true) - $start) * 1000);

            if ($read !== $value) {
                return $this->failed($meta, 'Cache read back a different value than was written');
            }

            return CheckResult::healthy($this->getName(), $meta);
        } catch (Throwable $e) {
            $this->logger->error('Ledge cache check failed: '.$e->getMessage());

            return $this->failed($meta, 'Cache store is unavailable');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failed(array $meta, string $output): CheckResult
    {
        $status = $this->settings->cacheFailureStatus;

        return new CheckResult($this->getName(), $status, $meta, $output);
    }
}
