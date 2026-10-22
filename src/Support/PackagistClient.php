<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Support;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Fetches each package's release list from Packagist's static p2 metadata
 * (`repo.packagist.org/p2/{vendor}/{name}.json`). Packages Packagist does
 * not know (private or path repositories) resolve to null.
 */
class PackagistClient
{
    public const BASE_URL = 'https://repo.packagist.org/p2/';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly int $concurrency = 8,
        private readonly int $timeout = 10,
    ) {}

    /**
     * @param  list<string>  $names
     * @return array<string, list<string>|null> name => versions, or null when the lookup failed or the package is unknown
     */
    public function versions(array $names): array
    {
        $results = [];

        $requests = function () use ($names) {
            foreach ($names as $name) {
                yield $name => new Request('GET', self::BASE_URL.$name.'.json', ['Accept' => 'application/json']);
            }
        };

        $pool = new Pool($this->client, $requests(), [
            'concurrency' => $this->concurrency,
            'options' => ['timeout' => $this->timeout, 'http_errors' => false, 'allow_redirects' => false],
            'fulfilled' => function (ResponseInterface $response, string $name) use (&$results): void {
                $results[$name] = $this->parse($response, $name);
            },
            'rejected' => function (Throwable $reason, string $name) use (&$results): void {
                $results[$name] = null;
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }

    /**
     * @return list<string>|null
     */
    private function parse(ResponseInterface $response, string $name): ?array
    {
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = json_decode((string) $response->getBody(), true);
        $entries = $data['packages'][$name] ?? null;

        if (! is_array($entries)) {
            return null;
        }

        $versions = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['version'] ?? null)) {
                $versions[] = $entry['version'];
            }
        }

        return $versions;
    }
}
