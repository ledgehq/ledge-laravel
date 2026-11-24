<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Jobs;

use GuzzleHttp\ClientInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Sleep;
use LedgeHQ\Ledge\Acquire\AcquireException;
use LedgeHQ\Ledge\Acquire\BundleBuilder;
use LedgeHQ\Ledge\Acquire\BundleResult;
use LedgeHQ\Ledge\Acquire\BundleUploader;
use LedgeHQ\Ledge\Acquire\CallbackClient;
use LedgeHQ\Ledge\Acquire\CallbackPayloads;
use LedgeHQ\Ledge\Acquire\Database\DatabaseDumper;
use LedgeHQ\Ledge\Acquire\Database\DatabaseInspector;
use LedgeHQ\Ledge\Acquire\Preflight;
use LedgeHQ\Ledge\Acquire\TempWorkspace;
use LedgeHQ\Ledge\Acquire\UploadAttempt;
use LedgeHQ\Ledge\Acquire\UploadDiagnostics;
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

    /**
     * Ledge's upload URL is valid for 60 minutes from when it sent the
     * command; no upload retry starts later than this after acceptance.
     */
    public const UPLOAD_DEADLINE_SECONDS = 55 * 60;

    public int $tries = 1;

    public ?int $timeout = 3600;

    /**
     * Unix time the command was accepted; 0 (no upload deadline) for jobs
     * built without it. A plain property with a default rather than a
     * readonly promoted one, so a job serialized by 1.2.0 unserializes with 0
     * instead of an uninitialized property.
     */
    public int $acceptedAt = 0;

    public function __construct(
        public readonly string $runId,
        public readonly string $uploadUrl,
        public readonly string $bundlePubkey,
        public readonly string $callbackUrl,
        public readonly string $callbackToken,
        public readonly string $profile,
        int $acceptedAt = 0,
    ) {
        $this->acceptedAt = $acceptedAt;
    }

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
        $dumpDurationMs = null;
        $encryptDurationMs = null;
        $uploadDurationMs = null;

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
            $encryptStart = microtime(true);
            $result = (new BundleBuilder)->build(
                $entries,
                $this->getBundlePubkeyBytes(),
                $settings->acquireMaxBundleBytes,
                $workspace,
            );
            $encryptDurationMs = (int) round((microtime(true) - $encryptStart) * 1000);
            $callbacks->send(CallbackClient::EVENT_PROGRESS, $step);

            $step = 'upload';
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $upload = (new BundleUploader($http, sleeper: static function (int $ms): void {
                Sleep::for($ms)->milliseconds();
            }))->upload(
                $result->path,
                $this->uploadUrl,
                function (UploadAttempt $attempt, UploadDiagnostics $soFar) use ($callbacks, $logger): void {
                    $this->reportRetry($callbacks, $logger, $attempt, $soFar);
                },
                $this->acceptedAt > 0 ? (float) ($this->acceptedAt + self::UPLOAD_DEADLINE_SECONDS) : null,
                [$this->callbackToken, $this->bundlePubkey],
            );
            $uploadDurationMs = $upload->durationMs;

            $service->transition($this->runId, AcquireService::STATUS_COMPLETED, $step, null, $result->size, $result->sha256);
            $callbacks->send(CallbackClient::EVENT_COMPLETED, $step, CallbackPayloads::completed(
                $this->completedDetail($result, $dumpDurationMs, $inspector),
                ['encrypt_duration_ms' => $encryptDurationMs, 'upload_duration_ms' => $uploadDurationMs],
                memory_get_peak_usage(true),
                $upload,
            ));
        } catch (Throwable $e) {
            $reason = $e instanceof AcquireException ? $e->reason : 'unexpected_error';
            $detail = $e instanceof AcquireException ? $e->detail : $e->getMessage();
            $upload = $e instanceof AcquireException && ($e->context['upload'] ?? null) instanceof UploadDiagnostics ? $e->context['upload'] : null;

            if ($upload !== null) {
                $uploadDurationMs = $upload->durationMs;
            }

            $logger->error("Ledge acquisition {$this->runId} failed at {$step}: {$e->getMessage()}");
            $service->transition($this->runId, AcquireService::STATUS_FAILED, $step, $reason);
            $callbacks->send(CallbackClient::EVENT_FAILED, $step, CallbackPayloads::failed(
                $reason,
                $detail,
                [
                    'dump_duration_ms' => $dumpDurationMs,
                    'encrypt_duration_ms' => $encryptDurationMs,
                    'upload_duration_ms' => $uploadDurationMs,
                ],
                memory_get_peak_usage(true),
                $upload,
            ));

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

    private function reportRetry(CallbackClient $callbacks, LoggerInterface $logger, UploadAttempt $attempt, UploadDiagnostics $soFar): void
    {
        $payload = CallbackPayloads::uploadRetried($soFar);

        $logger->warning("Ledge acquisition {$this->runId} upload {$payload['reason']}; retrying in {$attempt->retryInMs}ms");
        $callbacks->send(CallbackClient::EVENT_UPLOAD_RETRIED, 'upload', $payload);
    }

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
