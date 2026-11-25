<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds the exception the installed Guzzle major's curl handler raises for a
 * curl error, so the same test runs on Guzzle 7 and 8. Guzzle 7 puts the
 * errno in the exception's handler context; Guzzle 8 only reports it through
 * `on_stats`, so a test handler must pass it as the stats' handler error data
 * too, as curl's does on both.
 */
trait BuildsGuzzleFailures
{
    protected static function isGuzzle8(): bool
    {
        return class_exists(ResponseException::class);
    }

    /**
     * @param  ResponseInterface|null  $response  the response whose headers had arrived, if any
     */
    protected static function curlFailure(int $errno, string $message, RequestInterface $request, ?ResponseInterface $response = null): TransferException
    {
        if (! self::isGuzzle8()) {
            // Guzzle 7 turns its "connection" errnos into a ConnectException,
            // dropping any response; other errnos keep it.
            return in_array($errno, [6, 7, 28, 35, 52], true)
                ? new ConnectException($message, $request, null, ['errno' => $errno])
                : new RequestException($message, $request, $response, null, ['errno' => $errno]);
        }

        return match (true) {
            $response !== null && $errno === 28 => new ResponseTimeoutException($message, $request, $response),
            $response !== null => new ResponseTransferException($message, $request, $response),
            $errno === 28 => new NetworkTimeoutException($message, $request),
            in_array($errno, [5, 6, 7, 35, 60], true) => new ConnectException($message, $request),
            in_array($errno, [16, 52, 55, 56], true) => new NetworkException($message, $request),
            default => new RequestException($message, $request),
        };
    }
}
