<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\View\Markdown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownTest extends TestCase
{
    private function md(string $text): string
    {
        return (new Markdown())->render($text);
    }

    public function testBlocks(): void
    {
        self::assertSame('<h2>Nadpis</h2>', $this->md('# Nadpis'));
        self::assertSame('<h4>Menší</h4>', $this->md('### Menší'));
        self::assertSame("<p>a<br>\nb</p>\n<p>c</p>", $this->md("a\nb\n\nc"));
        self::assertSame('<ul><li>jedna</li><li>dvě</li></ul>', $this->md("- jedna\n* dvě"));
        self::assertSame('<ol><li>a</li><li>b</li></ol>', $this->md("1. a\n2) b"));
        self::assertSame("<pre><code>x = 1\n  y</code></pre>", $this->md("```\nx = 1\n  y\n```"));
        self::assertSame(
            '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr><tr><td>3</td><td></td></tr></tbody></table>',
            $this->md("| A | B |\n|---|:---:|\n| 1 | 2 |\n| 3 |")
        );
    }

    public function testInline(): void
    {
        self::assertSame('<p><strong>tučně</strong> a <em>kurzíva</em> a <em>taky</em></p>', $this->md('**tučně** a *kurzíva* a _taky_'));
        self::assertSame('<p><code>**ne**</code></p>', $this->md('`**ne**`'));
        self::assertSame('<p>snake_case_name a 2*3*4</p>', $this->md('snake_case_name a 2*3*4'));
        self::assertSame(
            '<p><a href="https://example.com/?a=1&amp;b=2" rel="noopener noreferrer nofollow">web</a></p>',
            $this->md('[web](https://example.com/?a=1&b=2)')
        );
        self::assertSame('<p><a href="/objects/1" rel="noopener noreferrer nofollow">objekt</a></p>', $this->md('[objekt](/objects/1)'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function attacks(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'img' => ['<img src=x onerror=alert(1)>'];
        yield 'javascript link' => ['[klik](javascript:alert(1))'];
        yield 'javascript link bez závorek' => ['[klik](javascript:alert`1`)'];
        yield 'data link' => ['[klik](data:text/html;base64,PHNjcmlwdD4=)'];
        yield 'protocol relative' => ['[klik](//evil.example.net/)'];
        yield 'uvozovky v url' => ['[klik](https://example.com/"onmouseover="alert(1))'];
        yield 'html v tabulce' => ["| <b onclick=x> |\n|---|\n| <iframe> |"];
        yield 'html v nadpisu' => ['# <svg onload=alert(1)>'];
        yield 'html v kódu' => ["```\n</code><script>x</script>\n```"];
        yield 'nulový bajt' => ["a\x00b <i>"];
    }

    #[DataProvider('attacks')]
    public function testNoHtmlPassesThrough(string $input): void
    {
        $html = $this->md($input);
        self::assertDoesNotMatchRegularExpression('/<(script|img|iframe|svg|b |i>)/i', $html);
        self::assertDoesNotMatchRegularExpression('/<[^>]*\son[a-z]+=/i', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringNotContainsString('href="data:', $html);
        self::assertStringNotContainsString('href="//', $html);
        self::assertStringNotContainsString("\x00", $html);
    }
}
