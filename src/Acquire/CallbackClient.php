<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * Best-effort callback delivery: short timeout, one retry, never throws.
 * A dropped callback must never fail an acquisition — Ledge falls back to
 * polling the status endpoint.
 */
class CallbackClient
{
    public const EVENT_ACCEPTED = 'acquire.accepted';

    public const EVENT_STARTED = 'acquire.started';

    public const EVENT_PROGRESS = 'acquire.progress';

    public const EVENT_COMPLETED = 'acquire.completed';

    public const EVENT_FAILED = 'acquire.failed';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $callbackUrl,
        private readonly string $token,
        private readonly float $timeout = 3.0,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     */
    public function send(string $event, ?string $step = null, array $detail = []): void
    {
        $body = [
            'event' => $event,
            'step' => $step,
            'detail' => $detail === [] ? null : $detail,
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = $this->client->request('POST', $this->callbackUrl, [
                    'json' => $body,
                    'timeout' => $this->timeout,
                    'connect_timeout' => $this->timeout,
                    'http_errors' => false,
                    'allow_redirects' => false,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->token,
                    ],
                ]);

                // Any non-5xx is treated as delivered and never retried: Ledge
                // answers 200 for late/duplicate events (logged, no status
                // change) and 409 result_already_recorded for a duplicate
                // result — both mean "already handled", not a retryable failure.
                if ($response->getStatusCode() < 500) {
                    return;
                }
            } catch (Throwable) {
            }
        }
    }
}
