<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

/**
 * Sestavení textového e-mailu v UTF-8 (předmět podle RFC 2047, tělo v base64).
 */
final class MailMessage
{
    /**
     * @param list<string> $to
     */
    public static function build(string $from, array $to, string $subject, string $text, ?int $time = null): string
    {
        $time ??= time();
        $domain = substr((string) strrchr($from, '@'), 1) ?: 'nordvarr.localhost';
        $headers = [
            'Date' => gmdate('D, d M Y H:i:s', $time) . ' +0000',
            'From' => self::clean($from),
            'To' => implode(', ', array_map([self::class, 'clean'], $to)),
            'Subject' => self::encodeHeader(self::clean($subject)),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . self::clean($domain) . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
            'Auto-Submitted' => 'auto-generated',
            'X-Mailer' => 'Nordvarr',
        ];
        $out = '';
        foreach ($headers as $name => $value) {
            $out .= $name . ': ' . $value . "\r\n";
        }

        return $out . "\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text))), 76, "\r\n")) . "\r\n";
    }

    /**
     * Odstraní konce řádků (ochrana proti vložení hlaviček).
     */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\0]+/', ' ', $value));
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $value) === 1) {
            return $value;
        }
        $words = [];
        // Kódovaná slova max. 75 znaků: dělí se po celých znacích UTF-8.
        foreach (mb_str_split($value, 30) as $chunk) {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }

        return implode("\r\n ", $words);
    }
}
