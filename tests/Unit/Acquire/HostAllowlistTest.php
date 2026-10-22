<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use LedgeHQ\Ledge\Acquire\HostAllowlist;
use PHPUnit\Framework\TestCase;

final class HostAllowlistTest extends TestCase
{
    public function test_exact_host_match(): void
    {
        $allowlist = new HostAllowlist(['app.ledgehq.app']);

        self::assertTrue($allowlist->allows('https://app.ledgehq.app/callback'));
        self::assertFalse($allowlist->allows('https://other.ledgehq.app/callback'));
        self::assertFalse($allowlist->allows('https://app.ledgehq.app.evil.com/callback'));
    }

    public function test_matching_is_case_insensitive(): void
    {
        self::assertTrue((new HostAllowlist(['App.LedgeHQ.app']))->allows('https://APP.ledgehq.APP/callback'));
    }

    public function test_wildcard_subdomain_match(): void
    {
        $allowlist = new HostAllowlist(['*.ledgehq.app']);

        self::assertTrue($allowlist->allows('https://storage.ledgehq.app/bundle'));
        self::assertTrue($allowlist->allows('https://a.b.ledgehq.app/bundle'));
        self::assertFalse($allowlist->allows('https://ledgehq.app/bundle'));
        self::assertFalse($allowlist->allows('https://evilledgehq.app/bundle'));
    }

    public function test_https_is_required_by_default(): void
    {
        $allowlist = new HostAllowlist(['app.ledgehq.app']);

        self::assertFalse($allowlist->allows('http://app.ledgehq.app/callback'));
        self::assertFalse($allowlist->allows('ftp://app.ledgehq.app/callback'));
    }

    public function test_http_is_allowed_when_insecure_is_enabled(): void
    {
        $allowlist = new HostAllowlist(['site.test'], true);

        self::assertTrue($allowlist->allows('http://site.test/callback'));
        self::assertFalse($allowlist->allows('ftp://site.test/callback'));
    }

    public function test_empty_allowlist_allows_nothing(): void
    {
        self::assertFalse((new HostAllowlist([]))->allows('https://app.ledgehq.app/callback'));
    }

    public function test_unparseable_url_is_rejected(): void
    {
        $allowlist = new HostAllowlist(['app.ledgehq.app']);

        self::assertFalse($allowlist->allows('not a url'));
        self::assertFalse($allowlist->allows(''));
    }
}
