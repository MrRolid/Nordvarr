<?php

declare(strict_types=1);

namespace Nordvarr\Alerts;

use Nordvarr\Config;
use Nordvarr\Http\Client\CurlHttpClient;
use Nordvarr\Http\Client\HttpClient;
use Nordvarr\I18n;
use Nordvarr\Installer;
use Nordvarr\Notify\Channel;
use Nordvarr\Notify\ChannelTypes;
use Nordvarr\Notify\SmtpClient;
use Nordvarr\Notify\TelegramChannel;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Security\SecretBox;
use PDO;

/**
 * Sestavení alertů a fronty podle nastavení instalace; volá ho worker po kontrolách.
 */
final class Notifier
{
    public const DEFAULT_REMINDER_HOURS = 24;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $appRoot,
        private readonly string $appSecret,
        private readonly HttpClient $http = new CurlHttpClient('Nordvarr'),
        private readonly SmtpClient $smtp = new SmtpClient(),
        private readonly string $telegramApi = TelegramChannel::API_BASE,
    ) {
    }

    /**
     * Notifier podle config.php (adresa API Telegramu jde změnit kvůli testům).
     */
    public static function fromConfig(PDO $pdo, string $appRoot, Config $config): self
    {
        $userAgent = 'Nordvarr/' . Installer::appVersion($appRoot);

        return new self(
            $pdo,
            $appRoot,
            $config->appSecret(),
            new CurlHttpClient($userAgent),
            new SmtpClient(15),
            (string) $config->get('telegram_api_url', TelegramChannel::API_BASE),
        );
    }

    public function builder(): NotificationBuilder
    {
        $settings = new SettingsRepository($this->pdo);
        $lang = I18n::resolve($settings->get('notification_lang', I18n::DEFAULT));

        return new NotificationBuilder(
            new I18n($this->appRoot . '/lang', $lang),
            (string) $settings->get('timezone', 'Europe/Prague'),
            (string) $settings->get('base_url', ''),
        );
    }

    public function engine(): AlertEngine
    {
        $hours = (new SettingsRepository($this->pdo))->get('alert_reminder_hours');

        return new AlertEngine($this->pdo, $this->builder(), $hours === null ? self::DEFAULT_REMINDER_HOURS : (int) $hours);
    }

    public function queue(): DeliveryQueue
    {
        $box = new SecretBox($this->appSecret);

        return new DeliveryQueue(
            $this->pdo,
            fn (array $channel): Channel => ChannelTypes::create($channel, $box, $this->http, $this->smtp, $this->telegramApi)
        );
    }

    /**
     * @return array{opened: int, resolved: int, notifications: int, sent: int, failed: int}
     */
    public function run(float $deadline = INF): array
    {
        return $this->engine()->run() + $this->queue()->process($deadline);
    }
}
