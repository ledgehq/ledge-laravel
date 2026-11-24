<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Streamed PUT of the bundle to the command's upload_url, retried with
 * backoff on transient failures. Any 2xx is success and the response body is
 * ignored: the target may be a Ledge signed URL or an S3-style pre-signed
 * PUT, and they answer with different codes and bodies. A PUT to the same URL
 * overwrites the same object, so repeating it is safe.
 *
 * Retried: transport errors with no response, and HTTP 408, 429, 500, 502,
 * 503 and 504. Terminal: 413 (bundle_too_large), 409 (run_already_finished),
 * anything else (upload_failed). No new attempt starts once the time since
 * the first one began would exceed the per-attempt timeout, or at or after
 * the optional deadline (the end of the upload URL's validity, less a
 * margin); with a deadline, each attempt's timeout is also clipped to it.
 */
class BundleUploader
{
    public const DEFAULT_MAX_ATTEMPTS = 5;

    public const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public const MIN_ATTEMPT_TIMEOUT = 30;

    private const BASE_DELAY_MS = 2000;

    private const BACKOFF_FACTOR = 3;

    private const JITTER_MIN = 0.75;

    private const JITTER_MAX = 1.25;

    private const MAX_RETRY_AFTER_MS = 60000;

    /** @var Closure(int): void */
    private Closure $sleeper;

    /** @var Closure(): float */
    private Closure $clock;

    /** @var Closure(): float */
    private Closure $jitter;

    private ?TransferStats $stats = null;

    /**
     * @param  Closure(int): void|null  $sleeper  waits the given milliseconds
     * @param  Closure(): float|null  $clock  current Unix time in seconds
     * @param  Closure(): float|null  $jitter  backoff multiplier in [0.75, 1.25]
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly int $timeout = 600,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        ?Closure $sleeper = null,
        ?Closure $clock = null,
        ?Closure $jitter = null,
    ) {
        // usleep() is not portable past one second, so whole seconds go to sleep().
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            sleep(intdiv($ms, 1000));
            usleep(($ms % 1000) * 1000);
        };
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->jitter = $jitter ?? static fn (): float => self::JITTER_MIN + mt_rand() / mt_getrandmax() * (self::JITTER_MAX - self::JITTER_MIN);
    }

    /**
     * @param  Closure(UploadAttempt, UploadDiagnostics): void|null  $onRetry
     *                                                                         called before each wait with the attempt that failed (retry_in_ms set)
     *                                                                         and the upload so far. Exceptions from it are ignored.
     * @param  float|null  $deadline  Unix time no retry may start at or after
     * @param  string[]  $alsoRedact  further secrets to keep out of diagnostics
     *
     * @throws AcquireException with the UploadDiagnostics under `context['upload']`
     */
    public function upload(string $path, string $url, ?Closure $onRetry = null, ?float $deadline = null, array $alsoRedact = []): UploadDiagnostics
    {
        $redactor = new SignedUrlRedactor($url, $alsoRedact);
        $firstStartedAt = ($this->clock)();
        $size = null;
        $attempts = [];
        $maxAttempts = max(1, $this->maxAttempts);

        for ($number = 1; ; $number++) {
            try {
                $body = new Stream(Utils::tryFopen($path, 'rb'));
                $size = $body->getSize();
            } catch (Throwable) {
                $body = null;
            }

            if ($body === null || $size === null) {
                $body?->close();
                throw $this->failure('upload_failed', 'unable to open bundle for upload', $redactor, $size, $maxAttempts, $firstStartedAt, $attempts);
            }

            $startedAt = ($this->clock)();
            [$response, $error, $stats] = $this->send($url, $body, $size, $this->attemptTimeout($deadline));
            $attempt = UploadAttempt::capture($number, $startedAt, $this->msSince($startedAt), $response, $error, $stats, $redactor);
            $status = $response?->getStatusCode();

            if ($attempt->succeeded()) {
                $attempts[] = $attempt;

                return $this->diagnostics($redactor, $size, $maxAttempts, $firstStartedAt, $attempts);
            }

            $failure = $this->describe($attempt);

            if (! $this->isRetryable($status, $error)) {
                $attempts[] = $attempt;
                [$reason, $detail] = $this->terminal($status, $failure);

                throw $this->failure($reason, $this->withAttempts($detail, $number), $redactor, $size, $maxAttempts, $firstStartedAt, $attempts);
            }

            if ($number >= $maxAttempts) {
                $attempts[] = $attempt;

                throw $this->failure('upload_failed', $this->withAttempts($failure, $number), $redactor, $size, $maxAttempts, $firstStartedAt, $attempts);
            }

            $delayMs = $this->delayMs($number, $response);
            $stop = $this->stopReason(($this->clock)() + $delayMs / 1000, $firstStartedAt, $deadline);

            if ($stop !== null) {
                $attempts[] = $attempt;

                throw $this->failure('upload_failed', $this->withAttempts($failure, $number).$stop, $redactor, $size, $maxAttempts, $firstStartedAt, $attempts);
            }

            $attempt = $attempt->withRetryIn($delayMs);
            $attempts[] = $attempt;

            if ($onRetry !== null) {
                try {
                    $onRetry($attempt, $this->diagnostics($redactor, $size, $maxAttempts, $firstStartedAt, $attempts));
                } catch (Throwable) {
                }
            }

            ($this->sleeper)($delayMs);

            // The hook (a callback with its own timeouts) and the wait can
            // overrun the estimate above, so check again before starting.
            $stop = $this->stopReason(($this->clock)(), $firstStartedAt, $deadline);

            if ($stop !== null) {
                $attempts[count($attempts) - 1] = $attempt->withRetryIn(null);

                throw $this->failure('upload_failed', $this->withAttempts($failure, $number).$stop, $redactor, $size, $maxAttempts, $firstStartedAt, $attempts);
            }
        }
    }

    /**
     * @return array{0: ResponseInterface|null, 1: Throwable|null, 2: TransferStats|null}
     */
    private function send(string $url, Stream $body, int $size, int $timeout): array
    {
        $response = null;
        $error = null;

        try {
            $response = $this->client->request('PUT', $url, [
                'body' => $body,
                'timeout' => $timeout,
                'http_errors' => false,
                'allow_redirects' => false,
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) $size,
                ],
                'on_stats' => function (TransferStats $stats): void {
                    $this->stats = $stats;
                },
            ]);
        } catch (Throwable $e) {
            $error = $e;
            $response = $e instanceof RequestException ? $e->getResponse() : null;

            // curl's "connection" errnos (28 timeout among them) become a
            // ConnectException without the response even when its headers
            // had arrived; the transfer stats still carry it.
            if ($response === null && $this->stats !== null) {
                $response = $this->stats->getResponse();
            }
        } finally {
            $body->close();
        }

        $stats = $this->stats;
        $this->stats = null;

        return [$response, $error, $stats];
    }

    private function attemptTimeout(?float $deadline): int
    {
        if ($deadline === null) {
            return $this->timeout;
        }

        $remaining = (int) floor($deadline - ($this->clock)());

        return max(self::MIN_ATTEMPT_TIMEOUT, min($this->timeout, $remaining));
    }

    private function stopReason(float $nextStartAt, float $firstStartedAt, ?float $deadline): ?string
    {
        if ($nextStartAt - $firstStartedAt > $this->timeout) {
            return " (retry time budget of {$this->timeout}s exhausted)";
        }

        if ($deadline !== null && $nextStartAt >= $deadline) {
            return ' (upload URL deadline reached)';
        }

        return null;
    }

    /**
     * An exception that still carries a non-2xx response is judged by its
     * status. One without a response (or with a 2xx it could not finish) is
     * a transport failure and retryable, if it came from Guzzle.
     */
    private function isRetryable(?int $status, ?Throwable $error): bool
    {
        if ($status !== null && ($status < 200 || $status >= 300)) {
            return in_array($status, self::RETRYABLE_STATUSES, true);
        }

        return $error instanceof GuzzleException;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function terminal(?int $status, string $failure): array
    {
        return match ($status) {
            413 => ['bundle_too_large', 'upload target rejected the bundle size'],
            409 => ['run_already_finished', 'upload target reports the run already finished'],
            default => ['upload_failed', $failure],
        };
    }

    private function describe(UploadAttempt $attempt): string
    {
        if ($attempt->status !== null && ($attempt->status < 200 || $attempt->status >= 300)) {
            return "upload target responded with HTTP {$attempt->status}";
        }

        return $attempt->error ?? 'upload request failed';
    }

    private function withAttempts(string $detail, int $number): string
    {
        return $number > 1 ? "{$detail} after {$number} attempts" : $detail;
    }

    /**
     * 2s, 6s, 18s, 54s, ... times jitter. A longer Retry-After wins, capped.
     */
    private function delayMs(int $number, ?ResponseInterface $response): int
    {
        $jitter = max(self::JITTER_MIN, min(self::JITTER_MAX, ($this->jitter)()));
        $delayMs = (int) round(self::BASE_DELAY_MS * self::BACKOFF_FACTOR ** ($number - 1) * $jitter);
        $retryAfterMs = $response !== null ? $this->retryAfterMs($response->getHeaderLine('Retry-After')) : null;

        if ($retryAfterMs !== null && $retryAfterMs > $delayMs) {
            return min($retryAfterMs, self::MAX_RETRY_AFTER_MS);
        }

        return $delayMs;
    }

    private function retryAfterMs(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return strlen($value) > 6 ? self::MAX_RETRY_AFTER_MS : (int) $value * 1000;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, (int) round(($timestamp - ($this->clock)()) * 1000));
    }

    private function msSince(float $start): int
    {
        return max(0, (int) round((($this->clock)() - $start) * 1000));
    }

    /**
     * @param  UploadAttempt[]  $attempts
     */
    private function diagnostics(SignedUrlRedactor $redactor, ?int $size, int $maxAttempts, float $firstStartedAt, array $attempts): UploadDiagnostics
    {
        return new UploadDiagnostics($redactor->host(), $size, $maxAttempts, $this->msSince($firstStartedAt), $attempts);
    }

    /**
     * @param  UploadAttempt[]  $attempts
     */
    private function failure(string $reason, string $detail, SignedUrlRedactor $redactor, ?int $size, int $maxAttempts, float $firstStartedAt, array $attempts): AcquireException
    {
        $diagnostics = $this->diagnostics($redactor, $size, $maxAttempts, $firstStartedAt, $attempts);

        return new AcquireException($reason, 500, $redactor->redact($detail), ['upload' => $diagnostics]);
    }
}
