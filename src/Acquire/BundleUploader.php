<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * Single streamed PUT to the command's upload_url. Any 2xx is success and the
 * response body is ignored — the target is a Ledge signed URL today and an S3
 * pre-signed PUT later, and the two answer with different codes and bodies.
 */
class BundleUploader
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly int $timeout = 600,
    ) {}

    public function upload(string $path, string $url): void
    {
        $stream = @fopen($path, 'rb');
        $size = filesize($path);

        if ($stream === false || $size === false) {
            throw new AcquireException('upload_failed', 500, 'unable to open bundle for upload');
        }

        try {
            $response = $this->client->request('PUT', $url, [
                'body' => $stream,
                'timeout' => $this->timeout,
                'http_errors' => false,
                'allow_redirects' => false,
                'headers' => [
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) $size,
                ],
            ]);
        } catch (Throwable $e) {
            throw new AcquireException('upload_failed', 500, $e->getMessage());
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status === 413) {
            throw new AcquireException('bundle_too_large', 500, 'upload target rejected the bundle size');
        }

        if ($status === 409) {
            throw new AcquireException('run_already_finished', 500, 'upload target reports the run already finished');
        }

        throw new AcquireException('upload_failed', 500, "upload target responded with HTTP {$status}");
    }
}
