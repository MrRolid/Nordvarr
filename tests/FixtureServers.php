<?php

declare(strict_types=1);

namespace Nordvarr\Tests;

use RuntimeException;

/**
 * Lokální fixture servery pro testy kontrol (HTTP přes php -S, TLS se zadaným certifikátem).
 * Běží jen na 127.0.0.1, procesy se po testu ukončí.
 */
final class FixtureServers
{
    /** @var list<resource> */
    private array $processes = [];
    private ?string $certDir = null;

    public function __destruct()
    {
        $this->stop();
    }

    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('Volný port nelze najít: ' . $error);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Spustí HTTP fixture server a vrátí jeho základní URL.
     */
    public function http(): string
    {
        $port = self::freePort();
        $this->start([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/http-server.php'], ['PHP_CLI_SERVER_WORKERS' => '8']);
        $this->waitForPort($port);

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * Spustí TLS server s certifikátem platným daný počet dní a vrátí port.
     */
    public function tls(int $validDays): int
    {
        [$certFile, $keyFile] = $this->certificate($validDays);
        $port = self::freePort();
        $this->start([PHP_BINARY, __DIR__ . '/fixtures/tls-server.php', (string) $port, $certFile, $keyFile]);
        $this->waitForPort($port);

        return $port;
    }

    /**
     * Spustí pomalý HTTP server, který obslouží všechna spojení současně (test paralelismu).
     */
    public function slowHttp(int $delayMs): string
    {
        $port = self::freePort();
        $this->start([PHP_BINARY, __DIR__ . '/fixtures/slow-server.php', (string) $port, (string) $delayMs]);
        $this->waitForPort($port);

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * Spustí falešný SMTP server. Vrací port; zprávy se ukládají do $dir.
     *
     * @param array<string, mixed> $options viz tests/fixtures/smtp-server.php
     */
    public function smtp(string $dir, array $options = [], bool $tls = false): int
    {
        $port = self::freePort();
        if ($tls) {
            [$cert, $key] = $this->certificate(90);
            $options += ['cert' => $cert, 'key' => $key];
        }
        $this->start([PHP_BINARY, __DIR__ . '/fixtures/smtp-server.php', json_encode(['port' => $port, 'dir' => $dir] + $options, JSON_THROW_ON_ERROR)]);
        $this->waitForPort($port, (string) ($options['bind'] ?? '127.0.0.1'));

        return $port;
    }

    /**
     * Spustí mock endpoint pro Telegram a webhooky. Požadavky se ukládají do $dir.
     */
    public function mockEndpoint(string $dir): string
    {
        $port = self::freePort();
        $this->start([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/mock-endpoint.php'], ['NORDVARR_MOCK_DIR' => $dir, 'PHP_CLI_SERVER_WORKERS' => '4']);
        $this->waitForPort($port);

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * @return array{0: string, 1: string} certifikát a klíč (PEM soubory)
     */
    private function certificate(int $validDays): array
    {
        $this->certDir ??= TempDir::create('nordvarr-certs-');
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
        $cert = openssl_csr_sign($csr, null, $key, $validDays);
        if ($key === false || $csr === false || $cert === false) {
            throw new RuntimeException('Testovací certifikát nelze vytvořit.');
        }
        $certFile = $this->certDir . '/cert-' . $validDays . '-' . bin2hex(random_bytes(3)) . '.pem';
        openssl_x509_export_to_file($cert, $certFile);
        openssl_pkey_export_to_file($key, $certFile . '.key');

        return [$certFile, $certFile . '.key'];
    }

    public function stop(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    // php -S s workery spouští podprocesy: ukončit celou skupinu.
                    posix_kill(-$status['pid'], 15);
                    proc_terminate($process, 15);
                }
                proc_close($process);
            }
        }
        $this->processes = [];
        if ($this->certDir !== null) {
            TempDir::remove($this->certDir);
            $this->certDir = null;
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private function start(array $command, array $env = []): void
    {
        // setsid: vlastní skupina procesů, aby šlo ukončit i workery php -S.
        $setsid = is_executable('/usr/bin/setsid') ? ['/usr/bin/setsid'] : [];
        $process = proc_open([...$setsid, ...$command], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env + getenv());
        if (!is_resource($process)) {
            throw new RuntimeException('Fixture server nelze spustit.');
        }
        $this->processes[] = $process;
    }

    private function waitForPort(int $port, string $host = '127.0.0.1'): void
    {
        $until = microtime(true) + 10;
        while (microtime(true) < $until) {
            $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
        throw new RuntimeException("Fixture server na portu $port nenaběhl.");
    }
}
