<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use LedgeHQ\Ledge\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PING against the default Redis connection, but only when the app actually
 * routes something through Redis (cache, queue, session, or broadcasting).
 * An app that does not use Redis omits the check entirely.
 */
class RedisCheck implements CheckInterface
{
    public function __construct(
        private readonly Repository $config,
        private readonly RedisFactory $redis,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'redis';
    }

    public function run(): ?CheckResult
    {
        $usedBy = $this->usedBy();

        if ($usedBy === []) {
            return null;
        }

        $failureStatus = $this->settings->redisFailureStatus;
        $meta = [
            'client' => $this->config->get('database.redis.client'),
            'usedBy' => $usedBy,
            'responseTime' => null,
            'thresholds' => ['failureStatus' => $failureStatus],
        ];

        $start = microtime(true);

        try {
            $pong = $this->redis->connection()->command('ping');
            $meta['responseTime'] = round((microtime(true) - $start) * 1000);

            if ($pong === false || $pong === null) {
                return new CheckResult($this->getName(), $failureStatus, $meta, 'Redis did not answer PING');
            }

            return CheckResult::healthy($this->getName(), $meta);
        } catch (Throwable $e) {
            $this->logger->error('Ledge redis check failed: '.$e->getMessage());

            return new CheckResult($this->getName(), $failureStatus, $meta, 'Redis connection failed');
        }
    }

    /**
     * @return list<string>
     */
    private function usedBy(): array
    {
        $usedBy = [];

        $cacheStore = (string) $this->config->get('cache.default', '');
        if ($this->config->get("cache.stores.{$cacheStore}.driver") === 'redis') {
            $usedBy[] = 'cache';
        }

        $queueConnection = (string) $this->config->get('queue.default', '');
        if ($this->config->get("queue.connections.{$queueConnection}.driver") === 'redis') {
            $usedBy[] = 'queue';
        }

        if ($this->config->get('session.driver') === 'redis') {
            $usedBy[] = 'session';
        }

        $broadcast = (string) $this->config->get('broadcasting.default', '');
        if ($this->config->get("broadcasting.connections.{$broadcast}.driver") === 'redis') {
            $usedBy[] = 'broadcasting';
        }

        return $usedBy;
    }
}
