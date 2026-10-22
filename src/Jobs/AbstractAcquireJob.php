<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Jobs;

use GuzzleHttp\ClientInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\BundleBuilder;
use LedgeHQ\Ledge\Acquire\BundleResult;
use LedgeHQ\Ledge\Acquire\BundleUploader;
use LedgeHQ\Ledge\Acquire\CallbackClient;
use LedgeHQ\Ledge\Acquire\Database\DatabaseDumper;
use LedgeHQ\Ledge\Acquire\Database\DatabaseInspector;
use LedgeHQ\Ledge\Acquire\Preflight;
use LedgeHQ\Ledge\Acquire\TempWorkspace;
use LedgeHQ\Ledge\AcquireService;
use LedgeHQ\Ledge\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The acquire pipeline shared by every profile: preflight → dump → entries →
 * encrypt → upload, with status transitions and callbacks at each step and
 * the workspace cleaned up on every exit. Profiles only decide what goes in
 * the archive and what the completed callback reports.
 */
abstract class AbstractAcquireJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public ?int $timeout = 3600;

    public function __construct(
        public readonly string $runId,
        public readonly string $uploadUrl,
        public readonly string $bundlePubkey,
        public readonly string $callbackUrl,
        public readonly string $callbackToken,
        public readonly string $profile,
    ) {}

    public function handle(
        AcquireService $service,
        Settings $settings,
        DatabaseDumper $dumper,
        DatabaseInspector $inspector,
        ClientInterface $http,
        LoggerInterface $logger,
    ): void {
        $callbacks = $service->createCallbackClient($this->callbackUrl, $this->callbackToken);
        $workspace = new TempWorkspace($service->tmpPath(), $this->runId);
        $step = 'preflight';

        try {
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $callbacks->send(CallbackClient::EVENT_STARTED, $step);

            $workspace->create();
            $this->runPreflight($workspace, $dumper, $inspector);
            $callbacks->send(CallbackClient::EVENT_PROGRESS, $step);

            $step = 'dump';
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $dumpPath = $workspace->path('dump.sql');
            $dumpStart = microtime(true);
            $dumper->dump($dumpPath);
            $dumpDurationMs = (int) round((microtime(true) - $dumpStart) * 1000);
            $callbacks->send(CallbackClient::EVENT_PROGRESS, $step);

            $step = 'manifest';
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $entries = $this->buildEntries($workspace, $dumpPath);
            $callbacks->send(CallbackClient::EVENT_PROGRESS, $step);

            $step = 'encrypt';
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $result = (new BundleBuilder)->build(
                $entries,
                $this->getBundlePubkeyBytes(),
                $settings->acquireMaxBundleBytes,
                $workspace,
            );
            $callbacks->send(CallbackClient::EVENT_PROGRESS, $step);

            $step = 'upload';
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            (new BundleUploader($http))->upload($result->path, $this->uploadUrl);

            $service->transition($this->runId, AcquireService::STATUS_COMPLETED, $step, null, $result->size, $result->sha256);
            $callbacks->send(CallbackClient::EVENT_COMPLETED, $step, $this->completedDetail($result, $dumpDurationMs, $inspector));
        } catch (Throwable $e) {
            $reason = $e instanceof AcquireException ? $e->reason : 'unexpected_error';
            $detail = $e instanceof AcquireException ? $e->detail : $e->getMessage();

            $logger->error("Ledge acquisition {$this->runId} failed at {$step}: {$e->getMessage()}");
            $service->transition($this->runId, AcquireService::STATUS_FAILED, $step, $reason);
            $callbacks->send(CallbackClient::EVENT_FAILED, $step, [
                'reason' => $reason,
                'detail' => $detail,
            ]);

            throw $e;
        } finally {
            $workspace->cleanup();
        }
    }

    /**
     * Archive contents: archive name => absolute source path.
     *
     * @return array<string, string>
     */
    abstract protected function buildEntries(TempWorkspace $workspace, string $dumpPath): array;

    /**
     * Detail payload for the `acquire.completed` callback.
     *
     * @return array<string, mixed>
     */
    abstract protected function completedDetail(BundleResult $result, int $dumpDurationMs, DatabaseInspector $inspector): array;

    private function runPreflight(TempWorkspace $workspace, DatabaseDumper $dumper, DatabaseInspector $inspector): void
    {
        (new Preflight(
            $dumper->isAvailable(...),
            fn (string $dir): float|false => @disk_free_space($dir),
            $inspector->estimateDumpBytes(),
            $workspace->getDir(),
            extension_loaded('sodium'),
        ))->run();
    }

    private function getBundlePubkeyBytes(): string
    {
        $bytes = base64_decode($this->bundlePubkey, true);

        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new AcquireException('encrypt_failed', 500, 'invalid bundle_pubkey');
        }

        return $bytes;
    }
}
