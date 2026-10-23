<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\CheckRepository;
use PDO;

/**
 * Doporučené kontroly pro objekt podle typu, názvu a atributů. Nic se nezakládá samo:
 * návrhy se ukážou na kartě objektu (nebo se přidají hromadně z inventáře).
 *
 * Pravidla:
 * - web: HTTP na atribut url, jinak https://<název>/; u https certifikát TLS,
 * - doména: expirace domény podle názvu,
 * - server, VM, síťové zařízení: ping na atribut ip, jinak na název (IP nebo jméno hosta),
 * - služba a databáze: HTTP na atribut url, TCP na host/ip + port.
 * Návrh, který už existující kontrola pokrývá (stejný typ a cíl), se vynechá.
 */
final class Suggestions
{
    /**
     * Návrhy pro objekt podle id (načte atributy a kontroly z databáze).
     *
     * @return list<array{key: string, type: string, input: array<string, string>, target: string}>
     */
    public static function load(PDO $pdo, string $objectId): array
    {
        $inventory = new Inventory($pdo);
        $object = $inventory->objects->find($objectId);
        if ($object === null) {
            return [];
        }

        return self::forObject(
            $object,
            self::attributeValues($inventory->attributes->grouped($objectId)),
            (new CheckRepository($pdo))->forObject($objectId)
        );
    }

    /**
     * Založí navržené kontroly s výchozím nastavením typu. $keys omezí výběr (null = všechny).
     *
     * @param list<string>|null $keys
     * @return list<array{id: string, type: string, target: string}>
     */
    public static function apply(PDO $pdo, string $objectId, ?array $keys = null): array
    {
        $checks = new CheckRepository($pdo);
        $created = [];
        foreach (self::load($pdo, $objectId) as $s) {
            if ($keys !== null && !in_array($s['key'], $keys, true)) {
                continue;
            }
            $valid = CheckTypes::validate($s['type'], $s['input']);
            $created[] = ['id' => $checks->create($objectId, $s['type'], $valid['common'], $valid['config']), 'type' => $s['type'], 'target' => $s['target']];
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $object
     * @param array<string, string> $attributes platné hodnoty atributů (klíč => hodnota)
     * @param list<array<string, mixed>> $checks existující kontroly objektu
     * @return list<array{key: string, type: string, input: array<string, string>, target: string}>
     */
    public static function forObject(array $object, array $attributes, array $checks): array
    {
        $name = strtolower(trim((string) $object['name']));
        $attr = static fn (string ...$keys): string => self::firstAttribute($attributes, $keys);
        $hostFromName = CheckTypes::isValidHost($name) && (str_contains($name, '.') || filter_var($name, FILTER_VALIDATE_IP) !== false) ? $name : '';
        $suggestions = [];

        $url = $attr('url', 'adresa');
        $host = $attr('ip', 'ipv4', 'ipv6', 'host', 'hostname');

        switch ((string) $object['type']) {
            case 'website':
                $url = $url !== '' ? $url : ($hostFromName !== '' && filter_var($hostFromName, FILTER_VALIDATE_IP) === false ? 'https://' . $hostFromName . '/' : '');
                self::addHttp($suggestions, $url);
                break;
            case 'domain':
                if ($hostFromName !== '' && filter_var($hostFromName, FILTER_VALIDATE_IP) === false) {
                    $suggestions[] = self::make('domain_expiry', ['domain' => rtrim($hostFromName, '.')]);
                }
                break;
            case 'server':
            case 'vm':
            case 'network_device':
                $target = $host !== '' ? $host : $hostFromName;
                if ($target !== '' && CheckTypes::isValidHost(strtolower($target))) {
                    $suggestions[] = self::make('ping', ['host' => strtolower($target)]);
                }
                break;
            case 'service':
            case 'database':
            case 'other':
                self::addHttp($suggestions, $url);
                $port = $attr('port');
                $tcpHost = $host !== '' ? $host : $attr('server');
                if ($tcpHost !== '' && preg_match('/^\d{1,5}$/', $port) === 1 && $url === '') {
                    $suggestions[] = self::make('tcp', ['host' => strtolower($tcpHost), 'port' => $port]);
                }
                break;
        }

        // Jen platné návrhy, které ještě nemají odpovídající kontrolu.
        $existing = [];
        foreach ($checks as $check) {
            $existing[$check['type'] . '|' . CheckTypes::target((string) $check['type'], $check['config'])] = true;
        }
        $result = [];
        foreach ($suggestions as $s) {
            $valid = CheckTypes::validate($s['type'], $s['input']);
            if ($valid['errors'] !== []) {
                continue;
            }
            $target = CheckTypes::target($s['type'], $valid['config']);
            if (isset($existing[$s['type'] . '|' . $target])) {
                continue;
            }
            $result[] = ['key' => $s['type'] . ':' . $target, 'type' => $s['type'], 'input' => $s['input'], 'target' => $target];
        }

        return $result;
    }

    /**
     * Návrh HTTP kontroly a u https i certifikátu TLS.
     *
     * @param list<array{type: string, input: array<string, string>}> $suggestions
     */
    private static function addHttp(array &$suggestions, string $url): void
    {
        $parts = parse_url($url);
        if ($url === '' || !is_array($parts) || ($parts['host'] ?? '') === '') {
            return;
        }
        $suggestions[] = self::make('http', ['url' => $url]);
        if (strtolower($parts['scheme'] ?? '') === 'https') {
            $suggestions[] = self::make('tls_expiry', ['host' => strtolower((string) $parts['host']), 'port' => (string) ($parts['port'] ?? 443)]);
        }
    }

    /**
     * @param array<string, string> $input
     * @return array{type: string, input: array<string, string>}
     */
    private static function make(string $type, array $input): array
    {
        return ['type' => $type, 'input' => $input];
    }

    /**
     * @param array<string, string> $attributes
     * @param list<string> $keys
     */
    private static function firstAttribute(array $attributes, array $keys): string
    {
        foreach ($keys as $key) {
            foreach ($attributes as $k => $value) {
                if (strtolower($k) === $key && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return '';
    }

    /**
     * Platné hodnoty atributů objektu (při rozporu ruční, jinak shodná hodnota zdrojů).
     *
     * @param list<array{key: string, valid: ?string}> $grouped
     * @return array<string, string>
     */
    public static function attributeValues(array $grouped): array
    {
        $values = [];
        foreach ($grouped as $g) {
            if ($g['valid'] !== null) {
                $values[$g['key']] = $g['valid'];
            }
        }

        return $values;
    }
}
