<?php

declare(strict_types=1);

namespace LedgeHQ\Ledge\Tests\Unit\Acquire;

use LedgeHQ\Ledge\Acquire\SignedUrlRedactor;
use PHPUnit\Framework\TestCase;

final class SignedUrlRedactorTest extends TestCase
{
    private const SIGNATURE = 'c0ffee00deadbeef0123456789abcdef';

    private const CREDENTIAL = 'AKIAEXAMPLE/20261124/us-east-1/s3/aws4_request';

    public function test_reduces_the_url_to_scheme_host_and_path(): void
    {
        $url = 'https://Bucket.S3.example.com:8443/backups/run-1.enc?X-Amz-Signature='.self::SIGNATURE;
        $redactor = new SignedUrlRedactor($url);

        $this->assertSame('bucket.s3.example.com', $redactor->host());
        $this->assertSame(
            'cURL error 28: timed out for https://Bucket.S3.example.com:8443/backups/run-1.enc',
            $redactor->redact("cURL error 28: timed out for {$url}"),
        );
    }

    public function test_redacts_query_values_raw_and_decoded(): void
    {
        $url = 'https://s3.example.com/b/k?X-Amz-Credential='.rawurlencode(self::CREDENTIAL)
            .'&X-Amz-Expires=600&X-Amz-Signature='.self::SIGNATURE;
        $redactor = new SignedUrlRedactor($url);

        $text = '<Credential>'.self::CREDENTIAL.'</Credential><SignatureProvided>'.self::SIGNATURE.'</SignatureProvided><Expires>600</Expires>';
        $redacted = $redactor->redact($text);

        $this->assertStringNotContainsString(self::SIGNATURE, $redacted);
        $this->assertStringNotContainsString('AKIAEXAMPLE', $redacted);
        $this->assertStringContainsString('<Expires>600</Expires>', $redacted);
        $this->assertStringContainsString('<SignatureProvided>[redacted]</SignatureProvided>', $redacted);
    }

    public function test_excerpt_drops_a_partial_secret_at_the_cut(): void
    {
        $redactor = new SignedUrlRedactor('https://ledgehq.app/bundles/1?signature='.self::SIGNATURE);

        $excerpt = $redactor->excerpt(str_repeat('.', 100).substr(self::SIGNATURE, 0, 20), 110);

        $this->assertSame(str_repeat('.', 100), $excerpt);
    }

    public function test_excerpt_is_valid_utf8(): void
    {
        $redactor = new SignedUrlRedactor('https://ledgehq.app/bundles/1');

        $excerpt = $redactor->excerpt("ab\xc3\xa9cd\xff", 3);

        $this->assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
        $this->assertSame(3, strlen($excerpt));
    }

    public function test_url_without_query_is_left_alone(): void
    {
        $redactor = new SignedUrlRedactor('https://ledgehq.app/bundles/1');

        $this->assertSame('for https://ledgehq.app/bundles/1', $redactor->redact('for https://ledgehq.app/bundles/1'));
        $this->assertSame(0, $redactor->longestSecret());
    }
}
