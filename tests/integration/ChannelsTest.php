<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Http\Client\CurlHttpClient;
use Nordvarr\Notify\ChannelException;
use Nordvarr\Notify\EmailChannel;
use Nordvarr\Notify\Notification;
use Nordvarr\Notify\SmtpClient;
use Nordvarr\Notify\SmtpServer;
use Nordvarr\Notify\TelegramChannel;
use Nordvarr\Notify\WebhookChannel;
use Nordvarr\Tests\FixtureServers;
use Nordvarr\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Kanály proti lokálnímu falešnému SMTP serveru a mock endpointu (žádné skutečné služby).
 */
final class ChannelsTest extends TestCase
{
    private FixtureServers $servers;
    private string $dir;

    protected function setUp(): void
    {
        $this->servers = new FixtureServers();
        $this->dir = TempDir::create('nordvarr-mail-');
    }

    protected function tearDown(): void
    {
        $this->servers->stop();
        TempDir::remove($this->dir);
    }

    private function notification(): Notification
    {
        return new Notification('opened', '[Nordvarr] NEFUNGUJE: shop.example.com', "Řádek 1\n.tečka na začátku\nŘádek 3", ['event' => 'alert.opened', 'object' => ['name' => 'shop.example.com']]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function received(): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        sort($files);

        return array_map(static fn (string $f): array => json_decode((string) file_get_contents($f), true), $files);
    }

    private function channel(SmtpServer $primary, ?SmtpServer $fallback = null): EmailChannel
    {
        return new EmailChannel(new SmtpClient(5), 'nordvarr@example.com', ['admin@example.com', 'ops@example.net'], $primary, $fallback);
    }

    public function testStartTlsWithAuthPlain(): void
    {
        $port = $this->servers->smtp($this->dir, ['user' => 'nordvarr', 'pass' => 'tajne-heslo'], true);
        $note = $this->channel(new SmtpServer('127.0.0.1', $port, 'starttls', 'nordvarr', 'tajne-heslo', false))->send($this->notification());
        self::assertStringContainsString('primární', $note);

        $mail = $this->received();
        self::assertCount(1, $mail);
        self::assertTrue($mail[0]['tls']);
        self::assertSame('nordvarr:PLAIN', $mail[0]['auth']);
        self::assertCount(2, $mail[0]['to']);
        self::assertStringContainsString('Subject: [Nordvarr] NEFUNGUJE: shop.example.com', $mail[0]['data']);
        [, $body] = explode("\r\n\r\n", $mail[0]['data'], 2);
        self::assertSame("Řádek 1\r\n.tečka na začátku\r\nŘádek 3", base64_decode(str_replace("\r\n", '', $body)));
    }

    public function testImplicitTlsWithAuthLogin(): void
    {
        $port = $this->servers->smtp($this->dir, ['user' => 'u', 'pass' => 'p', 'auth' => 'LOGIN', 'implicit_tls' => true], true);
        $this->channel(new SmtpServer('127.0.0.1', $port, 'ssl', 'u', 'p', false))->send($this->notification());
        self::assertSame('u:LOGIN', $this->received()[0]['auth']);
    }

    public function testCertificateIsVerifiedByDefault(): void
    {
        $port = $this->servers->smtp($this->dir, [], true);
        $this->expectException(ChannelException::class);
        $this->expectExceptionMessage('STARTTLS selhalo');
        $this->channel(new SmtpServer('127.0.0.1', $port, 'starttls'))->send($this->notification());
    }

    public function testFallbackServerIsUsedWhenPrimaryFails(): void
    {
        $down = $this->servers->smtp($this->dir, ['mode' => 'down']);
        $fallback = $this->servers->smtp($this->dir, [], true);
        $note = $this->channel(
            new SmtpServer('127.0.0.1', $down, 'starttls', '', '', false),
            new SmtpServer('127.0.0.1', $fallback, 'starttls', '', '', false),
        )->send($this->notification());
        self::assertStringContainsString('záložní', $note);
        self::assertSame($fallback, $this->received()[0]['port']);
    }

    public function testBothServersFailing(): void
    {
        $closed = FixtureServers::freePort();
        $badAuth = $this->servers->smtp($this->dir, ['user' => 'u', 'pass' => 'p'], true);
        try {
            $this->channel(
                new SmtpServer('127.0.0.1', $closed, 'starttls'),
                new SmtpServer('127.0.0.1', $badAuth, 'starttls', 'u', 'spatne', false),
            )->send($this->notification());
            self::fail('Odeslání mělo selhat.');
        } catch (ChannelException $e) {
            self::assertStringContainsString('Spojení se nepodařilo', $e->getMessage());
            self::assertStringContainsString('535', $e->getMessage());
            self::assertStringNotContainsString('spatne', $e->getMessage());
        }
        self::assertSame([], $this->received());
    }

    public function testPlainAuthWithoutEncryptionIsRefusedForRemoteHosts(): void
    {
        $this->expectException(ChannelException::class);
        $this->expectExceptionMessage('bez šifrovaného spojení');
        // 127.0.0.2 je lokální adresa, ale ne v seznamu výjimek pro localhost.
        $port = $this->servers->smtp($this->dir, ['user' => 'u', 'pass' => 'p', 'bind' => '127.0.0.2']);
        $channel = new EmailChannel(new SmtpClient(5), 'nordvarr@example.com', ['admin@example.com'], new SmtpServer('127.0.0.2', $port, 'none', 'u', 'p'));
        $channel->send($this->notification());
    }

    public function testRejectedRecipient(): void
    {
        $port = $this->servers->smtp($this->dir, ['mode' => 'reject_rcpt']);
        $this->expectException(ChannelException::class);
        $this->expectExceptionMessage('550');
        $this->channel(new SmtpServer('127.0.0.1', $port, 'none'))->send($this->notification());
    }

    public function testTelegramAndWebhookAgainstMockEndpoint(): void
    {
        $base = $this->servers->mockEndpoint($this->dir);
        $http = new CurlHttpClient('Nordvarr-test');

        (new TelegramChannel($http, '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ012345', '-1001234', $base))->send($this->notification());
        try {
            (new TelegramChannel($http, '123456:BADBADBADBADBADBADBADBAD', '-1001234', $base))->send($this->notification());
            self::fail('Telegram měl selhat.');
        } catch (ChannelException $e) {
            self::assertStringContainsString('Unauthorized', $e->getMessage());
            self::assertStringNotContainsString('BADBAD', $e->getMessage());
        }

        $secret = 'tajemstvi-webhooku-123';
        (new WebhookChannel($http, $base . '/hook/ok', $secret))->send($this->notification());
        try {
            (new WebhookChannel($http, $base . '/hook/fail', $secret))->send($this->notification());
            self::fail('Webhook měl selhat.');
        } catch (ChannelException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $requests = $this->received();
        $telegram = $requests[0];
        self::assertSame('/bot123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ012345/sendMessage', $telegram['path']);
        $message = json_decode($telegram['body'], true);
        self::assertSame('-1001234', $message['chat_id']);
        self::assertStringStartsWith('[Nordvarr] NEFUNGUJE: shop.example.com', $message['text']);

        $hook = array_values(array_filter($requests, static fn (array $r): bool => $r['path'] === '/hook/ok'))[0];
        self::assertSame(WebhookChannel::signature($hook['body'], $secret), $hook['headers']['x-nordvarr-signature']);
        self::assertSame('sha256=' . hash_hmac('sha256', $hook['body'], $secret), $hook['headers']['x-nordvarr-signature']);
        self::assertSame('alert.opened', json_decode($hook['body'], true)['event']);
        self::assertSame('application/json', $hook['headers']['content-type']);
    }
}
