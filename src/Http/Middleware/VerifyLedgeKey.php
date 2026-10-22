<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LedgeHQ\Ledge\Settings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-key check for every Ledge endpoint. The key is read from the
 * configured header only, unless `allow_query_key` is on, in which case the
 * configured query parameter is accepted as a fallback. Any other query
 * string (e.g. a cache-buster) is ignored.
 *
 * The `acquire` mode never accepts the query parameter and answers with the
 * acquire protocol's `{accepted: false, reason}` shape.
 */
class VerifyLedgeKey
{
    public const MODE_ACQUIRE = 'acquire';

    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next, string $mode = 'default'): Response
    {
        $acquire = $mode === self::MODE_ACQUIRE;
        $configuredKey = $this->settings->secretKey;

        if ($configuredKey === null) {
            return $this->reject($acquire ? ['accepted' => false, 'reason' => 'invalid_key'] : ['error' => 'LEDGE_SECRET_KEY is not configured']);
        }

        $providedKey = $request->header($this->settings->keyHeader);

        if ($providedKey === null && $this->settings->allowQueryKey && ! $acquire) {
            $providedKey = $request->query($this->settings->queryKeyParam);
        }

        if (! is_string($providedKey) || $providedKey === '' || ! hash_equals($configuredKey, $providedKey)) {
            return $this->reject($acquire ? ['accepted' => false, 'reason' => 'invalid_key'] : ['error' => 'Unauthorized']);
        }

        $response = $next($request);
        $this->preventCaching($response);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reject(array $body): JsonResponse
    {
        $response = new JsonResponse($body, 401);
        $this->preventCaching($response);

        return $response;
    }

    private function preventCaching(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
    }
}
