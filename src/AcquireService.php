<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge;

use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use LedgeHQ\Ledge\Acquire\AcquireCommand;
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\CallbackClient;
use LedgeHQ\Ledge\Acquire\CommandVerifier;
use LedgeHQ\Ledge\Acquire\HostAllowlist;
use LedgeHQ\Ledge\Acquire\KeyResolver;
use LedgeHQ\Ledge\Jobs\AbstractAcquireJob;
use Throwable;

/**
 * Accepts signed acquire commands, keeps run state in the cache, and knows
 * which profiles this install can produce. Profiles are a registry so a
 * platform layer can add its own (Craft 6 adds `full`); the base package
 * ships `backup` only.
 */
class AcquireService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    // Run status is kept in the cache long enough for Ledge's watchdog to poll
    // after a run finishes; the replay-guard key only lives for the command's
    // validity window (a replay is impossible once the signature expires).
    private const STATUS_TTL = 86400;

    /** @var array<string, class-string<AbstractAcquireJob>> */
    private array $profiles = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly CacheRepository $cache,
        private readonly ClientInterface $http,
        private readonly BusDispatcher $bus,
        private readonly string $defaultTmpPath,
    ) {}

    /**
     * @param  class-string<AbstractAcquireJob>  $job
     */
    public function registerProfile(string $profile, string $job): void
    {
        $this->profiles[$profile] = $job;
    }

    public function forgetProfile(string $profile): void
    {
        unset($this->profiles[$profile]);
    }

    /**
     * @return list<string>
     */
    public function supportedProfiles(): array
    {
        return array_keys($this->profiles);
    }

    public function supportsProfile(string $profile): bool
    {
        return isset($this->profiles[$profile]);
    }

    public function isEnabled(): bool
    {
        return $this->settings->acquireEnabled;
    }

    public function tmpPath(): string
    {
        return $this->settings->acquireTmpPath ?? $this->defaultTmpPath;
    }

    public function accept(string $rawBody): AcquireCommand
    {
        $resolver = new KeyResolver($this->http, $this->cache, $this->settings->ledgeBaseUrl);
        $allowlist = new HostAllowlist($this->settings->acquireAllowedHosts, $this->settings->acquireAllowInsecure);

        $command = (new CommandVerifier($resolver->resolve(...), $allowlist, $this->supportedProfiles()))->verify($rawBody);

        $this->reserveRun($command);

        try {
            $this->dispatch($command);
        } catch (Throwable $e) {
            $this->cache->forget(self::seenKey($command->runId));
            $this->cache->forget(self::statusKey($command->runId));
            throw new AcquireException('queue_unavailable', 500, $e->getMessage());
        }

        $this->createCallbackClient($command->callbackUrl, $command->callbackToken)
            ->send(CallbackClient::EVENT_ACCEPTED, 'queued');

        return $command;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStatus(string $runId): ?array
    {
        $record = $this->cache->get(self::statusKey($runId));

        return is_array($record) ? $record : null;
    }

    public function transition(
        string $runId,
        string $status,
        ?string $step = null,
        ?string $detail = null,
        ?int $sizeBytes = null,
        ?string $sha256 = null,
    ): void {
        $record = $this->cache->get(self::statusKey($runId));

        if (! is_array($record)) {
            // Status entry evicted (e.g. cache flush mid-run); rebuild it so the
            // watchdog still sees live state rather than a 404.
            $record = [
                'run_id' => $runId,
                'profile' => null,
                'size' => null,
                'sha256' => null,
                'dateCreated' => self::now(),
            ];
        }

        $record['status'] = $status;
        $record['step'] = $step;
        $record['detail'] = $detail;
        $record['dateUpdated'] = self::now();

        if ($sizeBytes !== null) {
            $record['size'] = $sizeBytes;
        }

        if ($sha256 !== null) {
            $record['sha256'] = $sha256;
        }

        $this->writeStatus($runId, $record);
    }

    public function createCallbackClient(string $callbackUrl, string $token): CallbackClient
    {
        return new CallbackClient($this->http, $callbackUrl, $token);
    }

    private function reserveRun(AcquireCommand $command): void
    {
        // Atomic add: fails if this run_id was already seen within its validity
        // window → replay. TTL = seconds until the command expires, since a
        // replay is impossible once the signature's expires_at has passed.
        $ttl = max(1, $command->expiresAt - time());

        if (! $this->cache->add(self::seenKey($command->runId), true, $ttl)) {
            throw new AcquireException('replayed', 409);
        }

        $now = self::now();
        $this->writeStatus($command->runId, [
            'run_id' => $command->runId,
            'status' => self::STATUS_PENDING,
            'step' => 'queued',
            'detail' => null,
            'profile' => $command->profile,
            'size' => null,
            'sha256' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ]);
    }

    private function dispatch(AcquireCommand $command): void
    {
        $class = $this->profiles[$command->profile];

        $job = new $class(
            $command->runId,
            $command->uploadUrl,
            $command->bundlePubkey,
            $command->callbackUrl,
            $command->callbackToken,
            $command->profile,
        );

        $job->timeout = $this->settings->acquireJobTimeout;

        if ($this->settings->acquireQueue !== null) {
            $job->onQueue($this->settings->acquireQueue);
        }

        if ($this->settings->acquireQueueConnection !== null) {
            $job->onConnection($this->settings->acquireQueueConnection);
        }

        $this->bus->dispatch($job);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeStatus(string $runId, array $record): void
    {
        $this->cache->put(self::statusKey($runId), $record, self::STATUS_TTL);
    }

    private static function seenKey(string $runId): string
    {
        return "ledge:acquire:seen:{$runId}";
    }

    private static function statusKey(string $runId): string
    {
        return "ledge:acquire:run:{$runId}";
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
