<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Alerts\NotificationBuilder;
use Nordvarr\I18n;
use Nordvarr\Notify\ChannelTypes;
use Nordvarr\Notify\MailMessage;
use Nordvarr\Notify\SmtpClient;
use Nordvarr\Security\SecretBox;
use PHPUnit\Framework\TestCase;

final class NotifyTest extends TestCase
{
    public function testMailMessageEncodingAndHeaderInjection(): void
    {
        $mail = MailMessage::build('nordvarr@example.com', ['admin@example.com'], "Příliš žluťoučký kůň\r\nBcc: zly@example.net", "Text\nřádek", 1_792_000_000);
        [$headers, $body] = explode("\r\n\r\n", $mail, 2);
        self::assertStringNotContainsString("\r\nBcc:", $headers);
        self::assertMatchesRegularExpression('/^Subject: =\?UTF-8\?B\?/m', $headers);
        preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/', $headers, $m);
        self::assertSame('Příliš žluťoučký kůň Bcc: zly@example.net', implode('', array_map('base64_decode', $m[1])));
        foreach (explode("\r\n", $headers) as $line) {
            self::assertLessThanOrEqual(78, strlen($line));
        }
        self::assertSame("Text\r\nřádek", base64_decode(str_replace("\r\n", '', $body)));
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $headers);
        self::assertStringContainsString('Auto-Submitted: auto-generated', $headers);
    }

    public function testDotStuffing(): void
    {
        self::assertSame("a\r\n..b\r\n...\r\nc", SmtpClient::dotStuff("a\n.b\n..\nc\n"));
    }

    public function testSecretBox(): void
    {
        $box = new SecretBox('tajemstvi-aplikace');
        $enc = $box->encrypt('heslo');
        self::assertStringStartsWith('enc:v1:', $enc);
        self::assertNotSame($enc, $box->encrypt('heslo'), 'Náhodný IV.');
        self::assertSame('heslo', $box->decrypt($enc));
        self::assertSame('', $box->encrypt(''));
        self::assertSame('nezasifrovane', $box->decrypt('nezasifrovane'));
    }

    public function testChannelValidation(): void
    {
        $box = new SecretBox('x');
        $email = ChannelTypes::validate('email', ['from' => 'neplatny', 'to' => 'a@example.com, spatne', 'primary_host' => '', 'primary_security' => 'xyz'], null, $box);
        self::assertSame(['from', 'to', 'primary_host', 'primary_security'], array_keys($email['errors']));

        $ok = ChannelTypes::validate('email', ['from' => 'n@example.com', 'to' => "a@example.com\nb@example.net", 'primary_host' => 'smtp.example.com',
            'primary_security' => 'ssl', 'primary_username' => 'u', 'primary_password' => 'p'], null, $box);
        self::assertSame([], $ok['errors']);
        self::assertSame(['a@example.com', 'b@example.net'], $ok['config']['to']);
        self::assertSame(465, $ok['config']['primary']['port']);
        self::assertNull($ok['config']['fallback']);
        self::assertSame('p', $box->decrypt($ok['config']['primary']['password']));

        // Změna serveru zahodí staré heslo.
        $changed = ChannelTypes::validate('email', ['from' => 'n@example.com', 'to' => 'a@example.com', 'primary_host' => 'jiny.example.com',
            'primary_security' => 'ssl', 'primary_username' => 'u'], $ok['config'], $box);
        self::assertSame('', $changed['config']['primary']['password']);
        $same = ChannelTypes::validate('email', ['from' => 'n@example.com', 'to' => 'a@example.com', 'primary_host' => 'smtp.example.com',
            'primary_security' => 'ssl', 'primary_username' => 'u'], $ok['config'], $box);
        self::assertSame('p', $box->decrypt($same['config']['primary']['password']));

        self::assertArrayHasKey('bot_token', ChannelTypes::validate('telegram', ['chat_id' => '-100', 'bot_token' => 'nesmysl'], null, $box)['errors']);
        self::assertArrayHasKey('chat_id', ChannelTypes::validate('telegram', ['chat_id' => 'abc', 'bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWX'], null, $box)['errors']);
        self::assertSame([], ChannelTypes::validate('telegram', ['chat_id' => '@nordvarr_ops', 'bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWX'], null, $box)['errors']);
        self::assertSame('invalid_channel_type', ChannelTypes::validate('sms', [], null, $box)['errors']['type']);
    }

    public function testNotificationTextInEnglish(): void
    {
        $builder = new NotificationBuilder(new I18n(dirname(__DIR__, 2) . '/lang', 'en'), 'UTC', 'https://nordvarr.example.com/index.php?r=');
        $alert = [
            'id' => 'a1', 'object_id' => 'o1', 'object_name' => 'shop.example.com', 'check_id' => 'c1', 'check_type' => 'http', 'check_name' => 'home',
            'status' => 'open', 'level' => 'unhealthy', 'reason_code' => 'http_5xx', 'reason_detail' => 'HTTP 503', 'opened_at' => '2026-10-20T13:58:00Z',
            'resolved_at' => null, 'probable_cause_object_id' => 'o2', 'cause_name' => 'db1.example.com',
        ];
        $n = $builder->forAlert('opened', $alert, ['type' => 'http', 'config' => ['url' => 'https://shop.example.com/', 'method' => 'GET']]);
        self::assertSame('[Nordvarr] DOWN: shop.example.com (HTTP home: http_5xx)', $n->subject);
        self::assertStringContainsString('Probable cause: db1.example.com', $n->text);
        self::assertStringContainsString('Since: 20. 10. 2026 13:58 (UTC)', $n->text);
        self::assertStringContainsString('https://nordvarr.example.com/index.php?r=/objects/o1', $n->text);
        self::assertSame('GET https://shop.example.com/', $n->payload['check']['target']);
        self::assertSame(['object_id' => 'o2', 'name' => 'db1.example.com'], $n->payload['probable_cause']);
    }
}
