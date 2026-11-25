<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * One upload attempt as reported to Ledge: small, bounded, and scrubbed of
 * the signed URL's credentials (and any other secret the redactor knows).
 */
class UploadAttempt
{
    public const MAX_ERROR_BYTES = 200;

    public const MAX_BODY_BYTES = 300;

    public const MAX_REQUEST_IDS = 5;

    public const MAX_REQUEST_ID_BYTES = 128;

    public const MAX_RETRY_AFTER_BYTES = 64;

    public const MAX_ERROR_CODE_BYTES = 64;

    // How much of an error body is read to find the S3 <Code> and the excerpt.
    private const SCAN_BYTES = 4096;

    /**
     * @param  array<string, string>|null  $requestIds
     */
    public function __construct(
        public readonly int $attempt,
        public readonly string $startedAt,
        public readonly ?int $status,
        public readonly ?string $errorType,
        public readonly ?string $error,
        public readonly ?int $curlErrno,
        public readonly ?string $errorCode,
        public readonly ?string $requestId,
        public readonly ?array $requestIds,
        public readonly ?string $retryAfter,
        public readonly int $durationMs,
        public readonly ?int $bytesSent,
        public readonly ?string $remoteIp,
        public readonly ?string $body,
        public readonly ?int $retryInMs = null,
    ) {}

    public static function capture(
        int $attempt,
        float $startedAt,
        int $durationMs,
        ?ResponseInterface $response,
        ?Throwable $error,
        ?TransferStats $stats,
        SignedUrlRedactor $redactor,
    ): self {
        $status = $response?->getStatusCode();
        $succeeded = $error === null && $status !== null && $status >= 200 && $status < 300;
        $scan = $response !== null && ! $succeeded ? self::scanBody($response, $redactor) : '';
        $requestIds = $response !== null ? self::requestIds($response, $redactor) : [];
        $retryAfter = $response?->getHeaderLine('Retry-After') ?? '';
        $handlerStats = $stats?->getHandlerStats() ?? [];

        return new self(
            $attempt,
            gmdate('Y-m-d\TH:i:s\Z', (int) floor($startedAt)),
            $status,
            $error !== null ? self::shortClass($error) : null,
            $error !== null ? $redactor->excerpt(self::shortClass($error).': '.$error->getMessage(), self::MAX_ERROR_BYTES) : null,
            $error !== null ? self::curlErrno($error, $stats) : null,
            self::errorCode($scan),
            $requestIds['x-amz-request-id'] ?? ($requestIds === [] ? null : reset($requestIds)),
            $requestIds === [] ? null : $requestIds,
            $retryAfter === '' ? null : $redactor->excerpt($retryAfter, self::MAX_RETRY_AFTER_BYTES),
            $durationMs,
            is_numeric($handlerStats['size_upload'] ?? null) ? (int) $handlerStats['size_upload'] : null,
            is_string($handlerStats['primary_ip'] ?? null) && $handlerStats['primary_ip'] !== '' ? $handlerStats['primary_ip'] : null,
            $scan === '' ? null : $redactor->excerpt($scan, self::MAX_BODY_BYTES),
        );
    }

    public function succeeded(): bool
    {
        return $this->error === null && $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function withRetryIn(?int $retryInMs): self
    {
        return new self(
            $this->attempt,
            $this->startedAt,
            $this->status,
            $this->errorType,
            $this->error,
            $this->curlErrno,
            $this->errorCode,
            $this->requestId,
            $this->requestIds,
            $this->retryAfter,
            $this->durationMs,
            $this->bytesSent,
            $this->remoteIp,
            $this->body,
            $retryInMs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attempt' => $this->attempt,
            'started_at' => $this->startedAt,
            'status' => $this->status,
            'error' => $this->error,
            'curl_errno' => $this->curlErrno,
            'error_code' => $this->errorCode,
            'request_id' => $this->requestId,
            'request_ids' => $this->requestIds,
            'retry_after' => $this->retryAfter,
            'duration_ms' => $this->durationMs,
            'bytes_sent' => $this->bytesSent,
            'remote_ip' => $this->remoteIp,
            'body' => $this->body,
            'retry_in_ms' => $this->retryInMs,
        ];
    }

    public static function isRequestIdHeader(string $name): bool
    {
        $name = strtolower($name);

        return str_contains($name, 'request-id') || str_contains($name, 'amz-id') || $name === 'cf-ray';
    }

    private static function shortClass(Throwable $error): string
    {
        $class = get_class($error);
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }

    /**
     * Guzzle 7 carries curl's errno in the exception's handler context.
     * Guzzle 8 removed that; its curl handler reports the errno as the
     * transfer stats' handler error data instead (any other handler puts an
     * exception there, which gives no errno).
     */
    private static function curlErrno(Throwable $error, ?TransferStats $stats): ?int
    {
        if (! $error instanceof GuzzleException) {
            return null;
        }

        if (method_exists($error, 'getHandlerContext')) {
            $context = $error->getHandlerContext();
            $errno = is_array($context) ? ($context['errno'] ?? null) : null;
        } else {
            $errno = $stats?->getHandlerErrorData();
        }

        return is_int($errno) && $errno > 0 ? $errno : null;
    }

    /**
     * @return array<string, string>
     */
    private static function requestIds(ResponseInterface $response, SignedUrlRedactor $redactor): array
    {
        $ids = [];

        foreach ($response->getHeaders() as $name => $values) {
            $name = strtolower((string) $name);

            if (! self::isRequestIdHeader($name)) {
                continue;
            }

            $ids[SignedUrlRedactor::utf8($name)] = $redactor->excerpt(implode(', ', $values), self::MAX_REQUEST_ID_BYTES);
        }

        if (isset($ids['x-amz-request-id'])) {
            $ids = ['x-amz-request-id' => $ids['x-amz-request-id']] + $ids;
        }

        return array_slice($ids, 0, self::MAX_REQUEST_IDS, true);
    }

    /**
     * The start of an error body, redacted. Reads past the scan length by the
     * longest secret so an occurrence straddling the cut is matched whole.
     */
    private static function scanBody(ResponseInterface $response, SignedUrlRedactor $redactor): string
    {
        $limit = self::SCAN_BYTES + $redactor->longestSecret();
        $buffer = '';

        try {
            $stream = $response->getBody();

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            while (strlen($buffer) < $limit && ! $stream->eof()) {
                $chunk = $stream->read($limit - strlen($buffer));

                if ($chunk === '') {
                    break;
                }

                $buffer .= $chunk;
            }
        } catch (Throwable) {
            return '';
        }

        return $buffer === '' ? '' : $redactor->excerpt($buffer, self::SCAN_BYTES);
    }

    private static function errorCode(string $body): ?string
    {
        if ($body === '' || preg_match('/<Code>\s*([A-Za-z0-9._-]{1,'.self::MAX_ERROR_CODE_BYTES.'})\s*<\/Code>/', $body, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
