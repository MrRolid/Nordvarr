<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\I18n;
use Nordvarr\View\UrlGenerator;
use Nordvarr\View\View;
use PHPUnit\Framework\TestCase;

final class WebLinkTest extends TestCase
{
    private function view(): View
    {
        $root = dirname(__DIR__, 2);

        return new View($root . '/templates', new I18n($root . '/lang', 'cs'), new UrlGenerator('', true, ''), '');
    }

    public function testHttpAndHttpsBecomeLinks(): void
    {
        $html = $this->view()->webLink('https://www.example.com/obchod?a=1&b=2', 'GET https://www.example.com/obchod');
        self::assertStringStartsWith('<a href="https://www.example.com/obchod?a=1&amp;b=2" target="_blank" rel="noopener noreferrer" class="web-link">GET https://www.example.com/obchod<svg', $html);
        self::assertStringContainsString('href="http://intranet.example.net/"', $this->view()->webLink('http://intranet.example.net/'));
    }

    public function testOtherValuesStayText(): void
    {
        $v = $this->view();
        self::assertSame('javascript:alert(1)', $v->webLink('javascript:alert(1)'));
        self::assertSame('ftp://example.com/', $v->webLink('ftp://example.com/'));
        self::assertSame('192.0.2.10', $v->webLink('192.0.2.10'));
        self::assertSame('&lt;b&gt;', $v->webLink('<b>'));
        self::assertSame('https://example.com/&quot;onmouseover', $v->webLink('https://example.com/"onmouseover'));
    }
}
