<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

use Nordvarr\Http\Client\HttpClient;

/**
 * Zpráva do kanálu Discordu přes webhook (zpráva s barevným pruhem podle druhu notifikace).
 * Adresa webhooku obsahuje token, proto se nikdy neobjeví v chybě ani ve výpisu.
 */
final class DiscordChannel implements Channel
{
    /** Povolené hostitele webhooků Discordu. */
    public const HOSTS = ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'];

    private const COLORS = ['alert.opened' => 0xD64545, 'alert.reminder' => 0xD64545, 'alert.after_maintenance' => 0xD99A14, 'alert.resolved' => 0x2F9E5B, 'test' => 0x1F5FA8];

    public function __construct(private readonly HttpClient $http, private readonly string $url)
    {
    }

    public static function isValidUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::HOSTS, true)
            && preg_match('#^/api/webhooks/\d+/[A-Za-z0-9_\-]+/?$#', $parts['path'] ?? '') === 1;
    }

    public function send(Notification $notification): string
    {
        $body = json_encode([
            'username' => 'Nordvarr',
            'embeds' => [[
                'title' => mb_substr($notification->subject, 0, 256),
                'description' => mb_substr($notification->text, 0, 4000),
                'color' => self::COLORS[$notification->kind] ?? 0x8A93A3,
            ]],
            'allowed_mentions' => ['parse' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = $this->http->post($this->url, $body, ['Content-Type: application/json'], 15);
        if ($response->status === 0) {
            throw new ChannelException('Discord nedostupný: ' . str_replace($this->url, '***', $response->error));
        }
        if (!$response->ok()) {
            $data = json_decode($response->body, true);
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : 'neplatná odpověď';
            throw new ChannelException('Discord HTTP ' . $response->status . ': ' . mb_substr($message, 0, 200));
        }

        return 'Discord';
    }
}
