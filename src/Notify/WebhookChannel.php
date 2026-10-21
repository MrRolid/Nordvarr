<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

use Nordvarr\Http\Client\HttpClient;

/**
 * POST JSON na zadanou adresu s podpisem X-Nordvarr-Signature
 * (HMAC-SHA256 těla s tajemstvím kanálu, hex, s prefixem "sha256=").
 */
final class WebhookChannel implements Channel
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $url,
        private readonly string $secret,
    ) {
    }

    public static function signature(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function send(Notification $notification): string
    {
        $body = json_encode(
            $notification->payload + ['subject' => $notification->subject, 'text' => $notification->text],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $response = $this->http->post($this->url, $body, [
            'Content-Type: application/json',
            'X-Nordvarr-Signature: ' . self::signature($body, $this->secret),
            'X-Nordvarr-Event: ' . $notification->kind,
        ], 15);
        if ($response->status === 0) {
            throw new ChannelException('Webhook nedostupný: ' . $response->error);
        }
        if (!$response->ok()) {
            throw new ChannelException('Webhook vrátil HTTP ' . $response->status);
        }

        return 'HTTP ' . $response->status;
    }
}
