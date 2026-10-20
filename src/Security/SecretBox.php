<?php

declare(strict_types=1);

namespace Nordvarr\Security;

use RuntimeException;

/**
 * Šifrování citlivých položek konfigurace kanálů (hesla SMTP, token Telegramu,
 * tajemství webhooku) klíčem odvozeným z tajemství aplikace. AES-256-GCM přes openssl.
 */
final class SecretBox
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private string $key;

    public function __construct(string $appSecret)
    {
        $this->key = hash_hmac('sha256', 'nordvarr-channel-secrets', $appSecret, true);
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Šifrování selhalo.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $value): string
    {
        if ($value === '' || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Poškozená zašifrovaná hodnota.');
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Hodnotu nelze dešifrovat (změnilo se tajemství aplikace?).');
        }

        return $plain;
    }
}
