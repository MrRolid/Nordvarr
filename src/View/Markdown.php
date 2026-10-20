<?php

declare(strict_types=1);

namespace Nordvarr\View;

/**
 * Vlastní bezpečný renderer Markdownu s omezenou sadou: nadpisy, odstavce,
 * seznamy, bloky a úseky kódu, odkazy, tučné písmo a kurzíva, jednoduché tabulky.
 * Veškerý text se escapuje, HTML ze vstupu se nikdy nepropouští.
 */
final class Markdown
{
    /** @var list<string> */
    private array $placeholders = [];

    /**
     * @param int $headingOffset o kolik se posunou úrovně nadpisů (# -> h2 při offsetu 1)
     */
    public function __construct(private readonly int $headingOffset = 1)
    {
    }

    public function render(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $html = [];
        $paragraph = [];
        $count = count($lines);

        $flush = function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . $this->inline(implode("\n", $paragraph)) . '</p>';
                $paragraph = [];
            }
        };

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (preg_match('/^\s*(```|~~~)/', $line, $fence) === 1) {
                $flush();
                $code = [];
                for ($i++; $i < $count && preg_match('/^\s*' . preg_quote($fence[1], '/') . '\s*$/', $lines[$i]) !== 1; $i++) {
                    $code[] = $lines[$i];
                }
                $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            if (trim($line) === '') {
                $flush();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $flush();
                $level = min(6, strlen($m[1]) + $this->headingOffset);
                $html[] = "<h$level>" . $this->inline($m[2]) . "</h$level>";
                continue;
            }

            if (str_starts_with(ltrim($line), '|') && $i + 1 < $count && self::isTableSeparator($lines[$i + 1])) {
                $flush();
                $header = self::cells($line);
                $rows = [];
                for ($i += 2; $i < $count && str_starts_with(ltrim($lines[$i]), '|'); $i++) {
                    $rows[] = self::cells($lines[$i]);
                }
                $i--;
                $html[] = $this->table($header, $rows);
                continue;
            }

            if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $line, $m) === 1) {
                $flush();
                $ordered = ctype_digit($m[1][0]);
                $pattern = $ordered ? '/^\s*\d+[.)]\s+(.*)$/' : '/^\s*[-*+]\s+(.*)$/';
                $items = [];
                for (; $i < $count && preg_match($pattern, $lines[$i], $item) === 1; $i++) {
                    $items[] = '<li>' . $this->inline($item[1]) . '</li>';
                }
                $i--;
                $tag = $ordered ? 'ol' : 'ul';
                $html[] = "<$tag>" . implode('', $items) . "</$tag>";
                continue;
            }

            $paragraph[] = trim($line);
        }
        $flush();

        return implode("\n", $html);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function isTableSeparator(string $line): bool
    {
        return preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $line) === 1;
    }

    /**
     * @return list<string>
     */
    private static function cells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\||\|$/', '', $line) ?? $line;

        return array_map('trim', explode('|', $line));
    }

    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    private function table(array $header, array $rows): string
    {
        $html = '<table><thead><tr>';
        foreach ($header as $cell) {
            $html .= '<th>' . $this->inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_keys($header) as $index) {
                $html .= '<td>' . $this->inline($row[$index] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * Řádkové formátování. Kód a odkazy se nejdřív nahradí zástupnými značkami,
     * zbytek se escapuje a teprve potom se doplní tučné písmo a kurzíva.
     */
    private function inline(string $text): string
    {
        $this->placeholders = [];
        $text = str_replace("\x00", '', $text);

        $text = preg_replace_callback('/`([^`]+)`/', fn (array $m): string => $this->hold(
            '<code>' . self::escape($m[1]) . '</code>'
        ), $text) ?? '';

        $text = preg_replace_callback('/\[([^\]\n]+)\]\(([^()\s]+)\)/', function (array $m): string {
            $url = $m[2];
            if (!self::isSafeUrl($url)) {
                return $this->hold(self::escape($m[0]));
            }

            return $this->hold('<a href="' . self::escape($url) . '" rel="noopener noreferrer nofollow">'
                . $this->emphasis(self::escape($m[1])) . '</a>');
        }, $text) ?? '';

        $html = $this->emphasis(self::escape($text));
        $html = str_replace("\n", "<br>\n", $html);

        // Odkaz může obsahovat úsek kódu, proto se značky nahrazují, dokud nějaké zbývají.
        for ($round = 0; $round < 3 && str_contains($html, "\x00"); $round++) {
            $html = preg_replace_callback('/\x00(\d+)\x00/', fn (array $m): string => $this->placeholders[(int) $m[1]], $html) ?? '';
        }

        return $html;
    }

    private function emphasis(string $html): string
    {
        $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/(?<![\*\w])\*(?=\S)(.+?)(?<=\S)\*(?![\*\w])/u', '<em>$1</em>', $html) ?? $html;

        return preg_replace('/(?<![\w])_(?=\S)(.+?)(?<=\S)_(?![\w])/u', '<em>$1</em>', $html) ?? $html;
    }

    private function hold(string $html): string
    {
        $this->placeholders[] = $html;

        return "\x00" . (count($this->placeholders) - 1) . "\x00";
    }

    public static function isSafeUrl(string $url): bool
    {
        if (preg_match('#^(https?://|mailto:)#i', $url) === 1) {
            return true;
        }
        // Relativní odkazy v rámci aplikace (ne //host, který by vedl jinam).
        return preg_match('#^(/(?!/)|\#|\?)#', $url) === 1;
    }
}
