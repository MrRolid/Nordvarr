<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\I18n;
use Nordvarr\View\UrlGenerator;
use Nordvarr\View\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewTest extends TestCase
{
    private function view(bool $pretty = true): View
    {
        $root = dirname(__DIR__, 2);

        return new View($root . '/templates', new I18n($root . '/lang', 'cs'), new UrlGenerator('/app', $pretty), 'tok"en');
    }

    public function testEscapeNeutralizesHtml(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', View::escape('<script>alert("x")</script>'));
        self::assertSame('&apos;', View::escape("'"));
    }

    public function testTranslationParametersAreEscaped(): void
    {
        $out = $this->view()->t('user.logged_in_as', ['email' => '<b>x</b>@example.com']);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;@example.com', $out);
        self::assertStringNotContainsString('<b>', $out);
    }

    public function testCsrfFieldEscapesToken(): void
    {
        self::assertSame('<input type="hidden" name="_csrf" value="tok&quot;en">', $this->view()->csrfField());
    }

    public function testPrettyAndFallbackUrls(): void
    {
        self::assertSame('/app/login', (new UrlGenerator('/app', true))->route('/login'));
        self::assertSame('/app/index.php?r=/login', (new UrlGenerator('/app', false))->route('/login'));
        self::assertSame('/app/index.php?r=/objects&amp;type=vm', $this->view(false)->url('/objects', ['type' => 'vm']));
        self::assertSame('/app/assets/css/app.css?v=1.0', (new UrlGenerator('/app', true, '1.0'))->asset('css/app.css'));
    }

    public function testErrorTemplateEscapesMessageAndRejectsUnknownTemplates(): void
    {
        $html = $this->view()->render('error', ['message' => 'error.not_found']);
        self::assertStringContainsString('Stránka nebyla nalezena.', $html);

        $this->expectException(RuntimeException::class);
        $this->view()->render('../config');
    }

    public function testTimeIsShownInConfiguredTimezone(): void
    {
        $root = dirname(__DIR__, 2);
        $view = new View($root . '/templates', new I18n($root . '/lang', 'cs'), new UrlGenerator('', true), '', 'Europe/Prague');
        self::assertSame('20. 10. 2026 15:58', $view->time('2026-10-20T13:58:00Z'));
    }
}
