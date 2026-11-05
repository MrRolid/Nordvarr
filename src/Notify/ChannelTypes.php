<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

use InvalidArgumentException;
use Nordvarr\Http\Client\HttpClient;
use Nordvarr\Security\SecretBox;

/**
 * Typy kanálů: ověření konfigurace z formuláře a vytvoření instance kanálu.
 * Tajné položky se ukládají zašifrované; prázdné pole ve formuláři zachová
 * dosavadní hodnotu.
 */
final class ChannelTypes
{
    public const TYPES = ['email', 'telegram', 'webhook', 'discord'];

    /**
     * @param array<string, mixed> $input pole formuláře pro daný typ
     * @param array<string, mixed>|null $existing dosavadní (zašifrovaná) konfigurace při úpravě
     * @return array{config: array<string, mixed>, errors: array<string, string>}
     */
    public static function validate(string $type, array $input, ?array $existing, SecretBox $box): array
    {
        $errors = [];
        $str = static fn (string $key): string => trim((string) ($input[$key] ?? ''));
        $secret = static function (string $key, ?string $current) use ($input, $box): string {
            $value = (string) ($input[$key] ?? '');

            return $value !== '' ? $box->encrypt($value) : (string) $current;
        };

        switch ($type) {
            case 'email':
                $from = $str('from');
                if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                    $errors['from'] = 'invalid_email_from';
                }
                $to = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $str('to')) ?: [])));
                if ($to === [] || count($to) > 20) {
                    $errors['to'] = 'invalid_email_to';
                }
                foreach ($to as $address) {
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        $errors['to'] = 'invalid_email_to';
                    }
                }
                $primary = self::server($input, 'primary', $existing['primary'] ?? null, $secret, $errors, true);
                $fallback = $str('fallback_host') === '' ? null
                    : self::server($input, 'fallback', $existing['fallback'] ?? null, $secret, $errors, false);
                $config = [
                    'from' => $from,
                    'to' => $to,
                    'verify_tls' => ($input['verify_tls'] ?? '1') === '1',
                    'primary' => $primary,
                    'fallback' => $fallback,
                ];
                break;

            case 'telegram':
                $chatId = $str('chat_id');
                if (preg_match('/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/', $chatId) !== 1) {
                    $errors['chat_id'] = 'invalid_chat_id';
                }
                $token = (string) ($input['bot_token'] ?? '');
                if ($token !== '' && preg_match('/^\d{5,15}:[A-Za-z0-9_-]{20,64}$/', $token) !== 1) {
                    $errors['bot_token'] = 'invalid_bot_token';
                } elseif ($token === '' && ($existing['bot_token'] ?? '') === '') {
                    $errors['bot_token'] = 'invalid_bot_token';
                }
                $config = ['chat_id' => $chatId, 'bot_token' => $secret('bot_token', $existing['bot_token'] ?? null)];
                break;

            case 'webhook':
                $url = $str('url');
                $parts = parse_url($url);
                if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
                    $errors['url'] = 'invalid_url';
                }
                $value = (string) ($input['secret'] ?? '');
                if ($value !== '' && strlen($value) < 16) {
                    $errors['secret'] = 'invalid_webhook_secret';
                } elseif ($value === '' && ($existing['secret'] ?? '') === '') {
                    $errors['secret'] = 'invalid_webhook_secret';
                }
                $config = ['url' => $url, 'secret' => $secret('secret', $existing['secret'] ?? null)];
                break;

            case 'discord':
                // Adresa obsahuje token: ukládá se zašifrovaná, prázdné pole zachová dosavadní.
                $url = $str('url');
                if ($url !== '' && !DiscordChannel::isValidUrl($url)) {
                    $errors['url'] = 'invalid_discord_url';
                } elseif ($url === '' && ($existing['url'] ?? '') === '') {
                    $errors['url'] = 'invalid_discord_url';
                }
                $config = ['url' => $url !== '' ? $box->encrypt($url) : (string) ($existing['url'] ?? '')];
                break;

            default:
                return ['config' => [], 'errors' => ['type' => 'invalid_channel_type']];
        }

        return ['config' => $config, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private static function server(array $input, string $prefix, ?array $current, callable $secret, array &$errors, bool $required): array
    {
        $host = strtolower(trim((string) ($input[$prefix . '_host'] ?? '')));
        if ($host === '' || !\Nordvarr\Checks\CheckTypes::isValidHost($host)) {
            $errors[$prefix . '_host'] = 'invalid_host';
        }
        $security = (string) ($input[$prefix . '_security'] ?? 'starttls');
        if (!in_array($security, SmtpServer::SECURITY, true)) {
            $errors[$prefix . '_security'] = 'invalid_security';
        }
        $port = trim((string) ($input[$prefix . '_port'] ?? ''));
        $port = $port === '' ? ($security === 'ssl' ? 465 : 587) : (ctype_digit($port) ? (int) $port : 0);
        if ($port < 1 || $port > 65535) {
            $errors[$prefix . '_port'] = 'invalid_port';
        }
        $username = trim((string) ($input[$prefix . '_username'] ?? ''));
        // Když se změní server nebo uživatel, staré heslo se nepoužije.
        $keepPassword = $current !== null && ($current['host'] ?? null) === $host && ($current['username'] ?? null) === $username;

        return [
            'host' => $host,
            'port' => $port,
            'security' => $security,
            'username' => $username,
            'password' => $username === '' ? '' : $secret($prefix . '_password', $keepPassword ? ($current['password'] ?? null) : null),
        ];
    }

    /**
     * Vytvoří kanál z uloženého řádku.
     *
     * @param array<string, mixed> $row řádek tabulky channels s dekódovaným config
     */
    public static function create(array $row, SecretBox $box, HttpClient $http, SmtpClient $smtp, string $telegramApi = TelegramChannel::API_BASE): Channel
    {
        $config = $row['config'];

        return match ($row['type']) {
            'email' => new EmailChannel(
                $smtp,
                (string) $config['from'],
                array_values(array_map('strval', (array) $config['to'])),
                self::smtpServer($config['primary'], (bool) ($config['verify_tls'] ?? true), $box),
                is_array($config['fallback'] ?? null) ? self::smtpServer($config['fallback'], (bool) ($config['verify_tls'] ?? true), $box) : null,
            ),
            'telegram' => new TelegramChannel($http, $box->decrypt((string) $config['bot_token']), (string) $config['chat_id'], $telegramApi),
            'webhook' => new WebhookChannel($http, (string) $config['url'], $box->decrypt((string) $config['secret'])),
            'discord' => new DiscordChannel($http, $box->decrypt((string) $config['url'])),
            default => throw new InvalidArgumentException('Neznámý typ kanálu: ' . $row['type']),
        };
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function smtpServer(array $server, bool $verify, SecretBox $box): SmtpServer
    {
        return new SmtpServer(
            (string) $server['host'],
            (int) $server['port'],
            (string) $server['security'],
            (string) ($server['username'] ?? ''),
            $box->decrypt((string) ($server['password'] ?? '')),
            $verify,
        );
    }

    /**
     * Krátký popis cíle kanálu pro výpisy (bez tajných údajů).
     *
     * @param array<string, mixed> $config
     */
    public static function summary(string $type, array $config): string
    {
        return match ($type) {
            'email' => implode(', ', (array) ($config['to'] ?? [])) . ' (' . ($config['primary']['host'] ?? '') . (is_array($config['fallback'] ?? null) ? ', ' . $config['fallback']['host'] : '') . ')',
            'telegram' => 'chat ' . ($config['chat_id'] ?? ''),
            'webhook' => (string) ($config['url'] ?? ''),
            'discord' => 'Discord webhook',
            default => '',
        };
    }
}
