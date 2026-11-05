<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Unit;

use Nordvarr\Http\Client\HttpResponse;
use Nordvarr\Notify\ChannelException;
use Nordvarr\Notify\ChannelTypes;
use Nordvarr\Notify\DiscordChannel;
use Nordvarr\Notify\Notification;
use Nordvarr\Notify\SmtpClient;
use Nordvarr\Security\SecretBox;
use Nordvarr\Tests\FixtureHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Kanál Discord: ověření adresy, zašifrované uložení, formát zprávy, chyby bez tokenu.
 */
final class DiscordChannelTest extends TestCase
{
    private const URL = 'https://discord.com/api/webhooks/123456789012345678/AbC-def_ghi123';

    public function testUrlValidation(): void
    {
        self::assertTrue(DiscordChannel::isValidUrl(self::URL));
        self::assertTrue(DiscordChannel::isValidUrl('https://discordapp.com/api/webhooks/1/x'));
        foreach (['http://discord.com/api/webhooks/1/x', 'https://discord.com.example.net/api/webhooks/1/x', 'https://discord.com/api/other/1/x', 'https://example.com/api/webhooks/1/x', 'nesmysl'] as $bad) {
            self::assertFalse(DiscordChannel::isValidUrl($bad), $bad);
        }
    }

    public function testConfigIsEncryptedAndKeptOnEmptyInput(): void
    {
        $box = new SecretBox(str_repeat('k', 32));
        $valid = ChannelTypes::validate('discord', ['url' => self::URL], null, $box);
        self::assertSame([], $valid['errors']);
        self::assertStringNotContainsString('AbC-def', json_encode($valid['config'], JSON_THROW_ON_ERROR));
        self::assertSame(self::URL, $box->decrypt($valid['config']['url']));
        self::assertSame('Discord webhook', ChannelTypes::summary('discord', $valid['config']));

        $kept = ChannelTypes::validate('discord', ['url' => ''], $valid['config'], $box);
        self::assertSame([[], $valid['config']['url']], [$kept['errors'], $kept['config']['url']]);
        self::assertSame('invalid_discord_url', ChannelTypes::validate('discord', ['url' => ''], null, $box)['errors']['url']);
        self::assertSame('invalid_discord_url', ChannelTypes::validate('discord', ['url' => 'https://example.com/hook'], null, $box)['errors']['url']);

        $http = new FixtureHttpClient([self::URL => new HttpResponse(204, '')]);
        $channel = ChannelTypes::create(['type' => 'discord', 'config' => $valid['config']], $box, $http, new SmtpClient());
        self::assertSame('Discord', $channel->send(new Notification('test', 'Test Nordvarru', 'Zkušební zpráva', [])));
    }

    public function testMessageFormatAndErrorsWithoutToken(): void
    {
        $http = new FixtureHttpClient([self::URL => new HttpResponse(204, '')]);
        (new DiscordChannel($http, self::URL))->send(new Notification('alert.opened', '[nefunguje] shop.example.com', "HTTP 503\n@everyone", []));
        $body = json_decode($http->posts[0]['body'], true);
        self::assertSame('Nordvarr', $body['username']);
        self::assertSame('[nefunguje] shop.example.com', $body['embeds'][0]['title']);
        self::assertSame(0xD64545, $body['embeds'][0]['color']);
        self::assertSame(['parse' => []], $body['allowed_mentions'], 'Text zprávy nikoho neoznačí.');

        $failing = new FixtureHttpClient([self::URL => new HttpResponse(404, '{"message":"Unknown Webhook"}')]);
        try {
            (new DiscordChannel($failing, self::URL))->send(new Notification('test', 'x', 'y', []));
            self::fail('Chyba Discordu');
        } catch (ChannelException $e) {
            self::assertSame('Discord HTTP 404: Unknown Webhook', $e->getMessage());
        }
        try {
            (new DiscordChannel(new FixtureHttpClient(), self::URL))->send(new Notification('test', 'x', 'y', []));
            self::fail('Nedostupný Discord');
        } catch (ChannelException $e) {
            self::assertStringNotContainsString('AbC-def', $e->getMessage());
        }
    }
}
