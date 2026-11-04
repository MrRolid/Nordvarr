<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Http\Client\CurlProtocols;
use PHPUnit\Framework\TestCase;

final class CurlProtocolsTest extends TestCase
{
    public function testOlderLibcurlUsesNumericOptions(): void
    {
        $options = CurlProtocols::options(false);
        self::assertSame([CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS], $options);
        self::assertTrue(curl_setopt_array(curl_init(), $options), 'libcurl volby přijme.');
    }

    public function testNewerLibcurlUsesStringOptions(): void
    {
        if (!defined('CURLOPT_PROTOCOLS_STR')) {
            self::markTestSkipped('PHP je sestavené se starší libcurl bez CURLOPT_PROTOCOLS_STR.');
        }
        $options = CurlProtocols::options();
        self::assertSame(['http,https', 'http,https'], array_values($options));
        self::assertTrue(curl_setopt_array(curl_init(), $options));
    }
}
