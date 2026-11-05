<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Hlavičky z $_SERVER pod různými SAPI (PHP-FPM, CGI, vestavěný server).
 */
final class RequestHeadersTest extends TestCase
{
    public function testPhpFpmPassesContentTypeWithoutHttpPrefix(): void
    {
        // Tak posílá požadavek Apache s mod_proxy_fcgi do PHP-FPM: bez HTTP_CONTENT_TYPE.
        $headers = Request::headersFromServer([
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '42',
            'HTTP_AUTHORIZATION' => 'Bearer nv_test',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ]);
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('42', $headers['content-length']);
        self::assertSame('Bearer nv_test', $headers['authorization']);
        self::assertSame('203.0.113.5', $headers['x-forwarded-for']);
    }

    public function testBuiltInServerAndRedirectedAuthorization(): void
    {
        $headers = Request::headersFromServer([
            'HTTP_CONTENT_TYPE' => 'application/json; charset=utf-8',
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer nv_redirect',
        ]);
        self::assertSame('application/json; charset=utf-8', $headers['content-type']);
        self::assertSame('Bearer nv_redirect', $headers['authorization']);

        $headers = Request::headersFromServer(['CONTENT_TYPE' => '', 'CONTENT_LENGTH' => '']);
        self::assertSame([], $headers, 'Prázdné hodnoty (GET pod FPM) se nepřenáší.');
    }
}
