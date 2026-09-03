<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LedgeHQ\Ledge\Settings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-key check for the read-only endpoints. The key is read from the
 * configured header only, unless `allow_query_key` is on, in which case the
 * configured query parameter is accepted as a fallback. Any other query
 * string (e.g. a cache-buster) is ignored.
 */
class VerifyLedgeKey
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = $this->settings->secretKey;

        if ($configuredKey === null) {
            return $this->reject('LEDGE_SECRET_KEY is not configured');
        }

        $providedKey = $request->header($this->settings->keyHeader);

        if ($providedKey === null && $this->settings->allowQueryKey) {
            $providedKey = $request->query($this->settings->queryKeyParam);
        }

        if (! is_string($providedKey) || $providedKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            return $this->reject('Unauthorized');
        }

        $response = $next($request);
        $this->preventCaching($response);

        return $response;
    }

    private function reject(string $error): JsonResponse
    {
        $response = new JsonResponse(['error' => $error], 401);
        $this->preventCaching($response);

        return $response;
    }

    private function preventCaching(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
    }
}
