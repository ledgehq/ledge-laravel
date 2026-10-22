<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

class HostAllowlist
{
    /**
     * @param  list<string>  $allowedHosts
     */
    public function __construct(
        private readonly array $allowedHosts,
        private readonly bool $allowInsecure = false,
    ) {}

    public function allows(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if ($scheme !== 'https' && ! ($this->allowInsecure && $scheme === 'http')) {
            return false;
        }

        foreach ($this->allowedHosts as $entry) {
            $entry = strtolower(trim($entry));

            if ($entry === '') {
                continue;
            }

            if (str_starts_with($entry, '*.')) {
                $suffix = substr($entry, 1);
                if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                    return true;
                }
            } elseif ($host === $entry) {
                return true;
            }
        }

        return false;
    }
}
