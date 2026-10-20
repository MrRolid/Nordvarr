<?php

declare(strict_types=1);

namespace Nordvarr\Notify;

/**
 * Malý SMTP klient: implicitní TLS (ssl), STARTTLS nebo bez šifrování,
 * přihlášení AUTH PLAIN nebo AUTH LOGIN, jedna zpráva na spojení.
 */
final class SmtpClient
{
    /** @var resource|null */
    private $socket = null;
    private string $transcriptError = '';

    public function __construct(private readonly int $timeout = 15, private readonly string $heloName = 'nordvarr.localhost')
    {
    }

    /**
     * @param list<string> $recipients
     */
    public function send(SmtpServer $server, string $from, array $recipients, string $message): void
    {
        try {
            $this->connect($server);
            $this->expect([220], 'pozdrav serveru');
            $extensions = $this->ehlo();
            if ($server->security === 'starttls') {
                if (!isset($extensions['STARTTLS'])) {
                    throw new SmtpException('Server nepodporuje STARTTLS.');
                }
                $this->command('STARTTLS', [220]);
                $this->enableTls($server);
                $extensions = $this->ehlo();
            }
            if ($server->username !== '') {
                $this->authenticate($server, $extensions);
            }
            $this->command('MAIL FROM:<' . self::address($from) . '>', [250]);
            foreach ($recipients as $recipient) {
                $this->command('RCPT TO:<' . self::address($recipient) . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            $this->write(self::dotStuff($message) . "\r\n.\r\n");
            $this->expect([250], 'přijetí zprávy');
            $this->write("QUIT\r\n");
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function connect(SmtpServer $server): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $server->verifyTls,
            'verify_peer_name' => $server->verifyTls,
            'allow_self_signed' => !$server->verifyTls,
            'peer_name' => $server->host,
            'SNI_enabled' => true,
        ]]);
        $scheme = $server->security === 'ssl' ? 'tls' : 'tcp';
        $socket = @stream_socket_client(
            $scheme . '://' . $server->host . ':' . $server->port,
            $errno,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new SmtpException('Spojení se nepodařilo: ' . ($error !== '' ? $error : 'chyba ' . $errno));
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    private function enableTls(SmtpServer $server): void
    {
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        set_error_handler(function (int $no, string $message): bool {
            $this->transcriptError = $message;

            return true;
        });
        try {
            $ok = stream_socket_enable_crypto($this->socket, true, $method);
        } finally {
            restore_error_handler();
        }
        if ($ok !== true) {
            throw new SmtpException('STARTTLS selhalo: ' . ($this->transcriptError !== '' ? $this->transcriptError : 'handshake se nepodařil'));
        }
    }

    /**
     * @return array<string, string> rozšíření (velkými písmeny) => parametry
     */
    private function ehlo(): array
    {
        [, $lines] = $this->command('EHLO ' . $this->heloName, [250]);
        $extensions = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', trim($line), 2);
            $extensions[strtoupper($parts[0])] = strtoupper($parts[1] ?? '');
        }

        return $extensions;
    }

    /**
     * @param array<string, string> $extensions
     */
    private function authenticate(SmtpServer $server, array $extensions): void
    {
        if ($server->security === 'none' && !in_array($server->host, ['127.0.0.1', '::1', 'localhost'], true)) {
            throw new SmtpException('Přihlášení bez šifrovaného spojení je zakázané.');
        }
        $mechanisms = explode(' ', $extensions['AUTH'] ?? '');
        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $server->username . "\0" . $server->password), [235], true);
        } elseif (in_array('LOGIN', $mechanisms, true)) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($server->username), [334], true);
            $this->command(base64_encode($server->password), [235], true);
        } else {
            throw new SmtpException('Server nenabízí AUTH PLAIN ani AUTH LOGIN.');
        }
    }

    /**
     * @param list<int> $expected
     * @return array{0: int, 1: list<string>}
     */
    private function command(string $line, array $expected, bool $secret = false): array
    {
        $this->write($line . "\r\n");

        return $this->expect($expected, $secret ? 'přihlášení' : explode(' ', $line, 2)[0]);
    }

    /**
     * @param list<int> $expected
     * @return array{0: int, 1: list<string>}
     */
    private function expect(array $expected, string $step): array
    {
        $lines = [];
        do {
            $line = fgets($this->socket, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new SmtpException("Server neodpověděl ($step)" . ($meta['timed_out'] ? ': vypršel časový limit' : ''));
            }
            $line = rtrim($line, "\r\n");
            $lines[] = substr($line, 4);
        } while (strlen($line) > 3 && $line[3] === '-');
        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new SmtpException("Server odmítl $step: $code " . end($lines));
        }

        return [$code, $lines];
    }

    private function write(string $data): void
    {
        $length = strlen($data);
        for ($written = 0; $written < $length; $written += $n) {
            $n = fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new SmtpException('Zápis do spojení selhal.');
            }
        }
    }

    private static function address(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new SmtpException('Neplatná e-mailová adresa.');
        }

        return $address;
    }

    /**
     * Sjednotí konce řádků na CRLF a zdvojí tečku na začátku řádku (RFC 5321).
     */
    public static function dotStuff(string $message): string
    {
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace('/^\./m', '..', $message) ?? $message;

        return str_replace("\n", "\r\n", rtrim($message, "\n"));
    }
}
