<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Acquire;

use GuzzleHttp\Psr7\Uri;
use Throwable;

/**
 * Keeps a signed upload URL's credentials out of anything that leaves the
 * uploader: exception messages (Guzzle appends the full URL), echoed error
 * bodies (S3 repeats the signature and canonical query) and headers. The URL
 * is reduced to scheme + host + path; the query string and each long query
 * value are replaced wherever they appear, raw or decoded.
 */
class SignedUrlRedactor
{
    public const MARKER = '[redacted]';

    private const MIN_VALUE_LENGTH = 8;

    private string $host;

    /** @var array<string, string> */
    private array $replacements = [];

    /**
     * @param  string[]  $alsoRedact  further secrets (e.g. the callback token)
     *                                replaced wherever they appear; values under 8 bytes are ignored
     */
    public function __construct(string $url, array $alsoRedact = [])
    {
        foreach ($alsoRedact as $secret) {
            $this->addSecret((string) $secret);
        }

        $parts = parse_url($url);
        $parts = is_array($parts) ? $parts : [];
        $this->host = strtolower($parts['host'] ?? '');

        $base = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $base .= $parts['host'] ?? '';
        $base .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $base .= $parts['path'] ?? '';

        $query = $parts['query'] ?? '';
        $hasSecrets = $query !== '' || isset($parts['user']) || isset($parts['fragment']);

        if (! $hasSecrets) {
            return;
        }

        foreach ($this->urlForms($url) as $form) {
            if ($form !== '' && $form !== $base) {
                $this->replacements[$form] = $base;
            }
        }

        if ($query !== '') {
            $this->addSecret($query);
            $this->addSecret(rawurldecode($query));

            foreach (explode('&', $query) as $pair) {
                $value = explode('=', $pair, 2)[1] ?? '';
                $this->addSecret($value);
                $this->addSecret(rawurldecode($value));
                $this->addSecret(urldecode($value));
            }
        }

        if (isset($parts['user'])) {
            $this->addSecret($parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : ''));
        }
    }

    public function host(): string
    {
        return $this->host;
    }

    public function redact(string $text): string
    {
        return $this->replacements === [] ? $text : strtr($text, $this->replacements);
    }

    /**
     * Redacts, then caps at $maxBytes. When the text is cut, a trailing
     * fragment that could be the start of a secret is dropped too, and the
     * result is always valid UTF-8.
     */
    public function excerpt(string $text, int $maxBytes): string
    {
        $text = $this->redact($text);

        if (strlen($text) > $maxBytes) {
            $text = $this->dropTrailingPartialSecret(substr($text, 0, $maxBytes));
        }

        return self::utf8($text);
    }

    /**
     * Longest secret the redactor knows about, so a caller cutting a stream
     * can read far enough past its limit for whole occurrences to be matched.
     */
    public function longestSecret(): int
    {
        $longest = 0;

        foreach (array_keys($this->replacements) as $secret) {
            $longest = max($longest, strlen((string) $secret));
        }

        return $longest;
    }

    public static function utf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return mb_scrub($text, 'UTF-8');
    }

    /**
     * @return string[]
     */
    private function urlForms(string $url): array
    {
        $forms = [$url];

        try {
            $forms[] = (string) new Uri($url);
        } catch (Throwable) {
        }

        return array_unique($forms);
    }

    private function addSecret(string $secret): void
    {
        if (strlen($secret) >= self::MIN_VALUE_LENGTH && ! isset($this->replacements[$secret])) {
            $this->replacements[$secret] = self::MARKER;
        }
    }

    private function dropTrailingPartialSecret(string $text): string
    {
        $drop = 0;

        foreach (array_keys($this->replacements) as $secret) {
            $secret = (string) $secret;

            for ($length = min(strlen($secret) - 1, strlen($text)); $length > $drop; $length--) {
                if (str_ends_with($text, substr($secret, 0, $length))) {
                    $drop = $length;
                    break;
                }
            }
        }

        return $drop > 0 ? substr($text, 0, -$drop) : $text;
    }
}
