<?php

declare(strict_types=1);

/*
 * Falešný SMTP server pro testy e-mailového kanálu. Přijaté zprávy ukládá jako JSON
 * do zadaného adresáře. Volby jako JSON v prvním argumentu:
 *   port, bind (výchozí 127.0.0.1), dir, mode (ok | down | reject_rcpt), cert, key (zapne STARTTLS),
 *   implicit_tls (bool), user, pass (vyžaduje přihlášení), auth (PLAIN LOGIN | LOGIN | PLAIN)
 */

$o = json_decode($argv[1], true, 8, JSON_THROW_ON_ERROR);
$tlsContext = isset($o['cert']) ? ['ssl' => ['local_cert' => $o['cert'], 'local_pk' => $o['key'], 'verify_peer' => false]] : [];
$scheme = !empty($o['implicit_tls']) ? 'tls' : 'tcp';
$server = stream_socket_server($scheme . '://' . ($o['bind'] ?? '127.0.0.1') . ':' . $o['port'], $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create($tlsContext));
if ($server === false) {
    fwrite(STDERR, "SMTP server nelze spustit: $error\n");
    exit(1);
}

$say = static function ($c, string $line): void {
    fwrite($c, $line . "\r\n");
};

while (true) {
    $c = @stream_socket_accept($server, 60);
    if ($c === false) {
        continue;
    }
    stream_set_timeout($c, 10);
    if (($o['mode'] ?? 'ok') === 'down') {
        $say($c, '421 4.3.2 Sluzba docasne nedostupna');
        fclose($c);
        continue;
    }
    $tls = $scheme === 'tls';
    $authed = null;
    $from = null;
    $to = [];
    $say($c, '220 fake.example.net ESMTP');
    while (($line = fgets($c)) !== false) {
        $line = rtrim($line, "\r\n");
        $verb = strtoupper(explode(' ', $line, 2)[0]);
        if ($verb === 'EHLO') {
            $lines = ['fake.example.net'];
            if (isset($o['cert']) && !$tls) {
                $lines[] = 'STARTTLS';
            }
            if (isset($o['user'])) {
                $lines[] = 'AUTH ' . ($o['auth'] ?? 'PLAIN LOGIN');
            }
            $lines[] = '8BITMIME';
            foreach ($lines as $i => $l) {
                $say($c, '250' . ($i === count($lines) - 1 ? ' ' : '-') . $l);
            }
        } elseif ($verb === 'STARTTLS') {
            $say($c, '220 2.0.0 Ready to start TLS');
            stream_context_set_option($c, $tlsContext);
            if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                break;
            }
            $tls = true;
        } elseif ($verb === 'AUTH') {
            $parts = explode(' ', $line);
            $mech = strtoupper($parts[1] ?? '');
            if ($mech === 'PLAIN') {
                [, $u, $p] = array_pad(explode("\0", (string) base64_decode($parts[2] ?? '')), 3, '');
            } elseif ($mech === 'LOGIN') {
                $say($c, '334 VXNlcm5hbWU6');
                $u = (string) base64_decode(trim((string) fgets($c)));
                $say($c, '334 UGFzc3dvcmQ6');
                $p = (string) base64_decode(trim((string) fgets($c)));
            } else {
                $say($c, '504 5.5.4 Mechanismus nepodporovan');
                continue;
            }
            if ($u === ($o['user'] ?? null) && $p === ($o['pass'] ?? null)) {
                $authed = $u . ':' . $mech;
                $say($c, '235 2.7.0 Authentication successful');
            } else {
                $say($c, '535 5.7.8 Authentication failed');
            }
        } elseif ($verb === 'MAIL') {
            if (isset($o['user']) && $authed === null) {
                $say($c, '530 5.7.0 Authentication required');
                continue;
            }
            $from = $line;
            $to = [];
            $say($c, '250 2.1.0 OK');
        } elseif ($verb === 'RCPT') {
            if (($o['mode'] ?? 'ok') === 'reject_rcpt') {
                $say($c, '550 5.1.1 Mailbox unavailable');
                continue;
            }
            $to[] = $line;
            $say($c, '250 2.1.5 OK');
        } elseif ($verb === 'DATA') {
            $say($c, '354 End data with <CR><LF>.<CR><LF>');
            $data = '';
            while (($l = fgets($c)) !== false && rtrim($l, "\r\n") !== '.') {
                $data .= str_starts_with($l, '..') ? substr($l, 1) : $l;
            }
            $file = $o['dir'] . '/mail-' . microtime(true) . '-' . bin2hex(random_bytes(3)) . '.json';
            file_put_contents($file, json_encode(['from' => $from, 'to' => $to, 'data' => $data, 'tls' => $tls, 'auth' => $authed, 'port' => $o['port']]));
            $say($c, '250 2.0.0 Queued');
        } elseif ($verb === 'QUIT') {
            $say($c, '221 2.0.0 Bye');
            break;
        } elseif ($verb === 'RSET' || $verb === 'NOOP') {
            $say($c, '250 OK');
        } else {
            $say($c, '502 5.5.2 Command not recognized');
        }
    }
    fclose($c);
}
