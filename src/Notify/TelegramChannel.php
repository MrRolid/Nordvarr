<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

use Nordvarr\Http\Client\HttpClient;

/**
 * Zpráva přes Telegram bota (metoda sendMessage).
 */
final class TelegramChannel implements Channel
{
    public const API_BASE = 'https://api.telegram.org';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $token,
        private readonly string $chatId,
        private readonly string $apiBase = self::API_BASE,
    ) {
    }

    public function send(Notification $notification): string
    {
        $body = json_encode([
            'chat_id' => $this->chatId,
            'text' => mb_substr($notification->subject . "\n\n" . $notification->text, 0, 4000),
            'disable_web_page_preview' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $url = rtrim($this->apiBase, '/') . '/bot' . $this->token . '/sendMessage';
        $response = $this->http->post($url, $body, ['Content-Type: application/json'], 15);
        $data = json_decode($response->body, true);
        if ($response->status === 0) {
            // Chyba spojení nesmí prozradit token, který je součástí adresy.
            throw new ChannelException('Telegram nedostupný: ' . str_replace($this->token, '***', $response->error));
        }
        if (!$response->ok() || !is_array($data) || ($data['ok'] ?? false) !== true) {
            $description = is_array($data) && is_string($data['description'] ?? null) ? $data['description'] : 'neplatná odpověď';
            throw new ChannelException('Telegram HTTP ' . $response->status . ': ' . str_replace($this->token, '***', $description));
        }

        return 'Telegram';
    }
}
