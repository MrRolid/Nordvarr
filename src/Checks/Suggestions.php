<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\Vocabulary;
use Nordvarr\Repository\CheckRepository;
use PDO;

/**
 * Doporučené kontroly pro objekt podle typu, názvu a atributů. Nic se nezakládá samo:
 * návrhy se ukážou na kartě objektu (nebo se přidají hromadně z inventáře).
 *
 * Pravidla:
 * - web: HTTP na atribut url, jinak https://<název>/; u https certifikát TLS; expirace registrované
 *   domény webu (example.com u shop.example.com) na objektu typu doména, který se při přidání
 *   založí (nebo najde podle názvu) a web na něm "závisí",
 * - doména: expirace domény podle názvu,
 * - server, VM, síťové zařízení: ping na atribut ip, jinak na název (IP nebo jméno hosta),
 * - služba, databáze, kontejner, cluster, poskytovatel: HTTP na atribut url, TCP na host/ip + port,
 * - externí služba: HTTP na atribut url, jinak ping na ip/host nebo název.
 * Návrh, který už existující kontrola pokrývá (stejný typ a cíl), se vynechá.
 */
final class Suggestions
{
    /** Veřejné přípony o dvou úrovních, u kterých je registrovaná doména o úroveň delší. */
    private const TWO_LEVEL_SUFFIXES = ['co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'com.br', 'co.jp', 'com.pl', 'com.cn', 'co.za', 'com.tr', 'com.mx'];

    /**
     * Návrhy pro objekt podle id (načte atributy a kontroly z databáze).
     *
     * @return list<array{key: string, type: string, input: array<string, string>, target: string, via: string}>
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
            (new CheckRepository($pdo))->forObject($objectId),
            self::coveredDomains($pdo, $objectId)
        );
    }

    /**
     * Domény, na kterých objekt už závisí a které mají kontrolu expirace (návrh pro ně odpadá).
     *
     * @return list<string>
     */
    private static function coveredDomains(PDO $pdo, string $objectId): array
    {
        $stmt = $pdo->prepare(
            "SELECT lower(o.name) FROM relationships r JOIN objects o ON o.id = r.to_id
             WHERE r.from_id = ? AND r.kind = 'depends_on' AND o.type = 'domain'
             AND EXISTS (SELECT 1 FROM checks c WHERE c.object_id = o.id AND c.type = 'domain_expiry')"
        );
        $stmt->execute([$objectId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
            if ($s['via'] === 'domain') {
                $id = self::applyDomain($pdo, $objectId, $s['target'], $valid);
                if ($id !== null) {
                    $created[] = ['id' => $id, 'type' => $s['type'], 'target' => $s['target']];
                }
                continue;
            }
            $created[] = ['id' => $checks->create($objectId, $s['type'], $valid['common'], $valid['config']), 'type' => $s['type'], 'target' => $s['target']];
        }

        return $created;
    }

    /**
     * Doména webu: najde nebo založí objekt typu doména, doplní mu kontrolu expirace a vztah
     * "web závisí na doméně". Vrací id nově založené kontroly (null, když už existovala).
     *
     * @param array{common: array<string, mixed>, config: array<string, mixed>, errors: array<string, string>} $valid
     */
    private static function applyDomain(PDO $pdo, string $objectId, string $domain, array $valid): ?string
    {
        $inventory = new Inventory($pdo);
        $checks = new CheckRepository($pdo);
        $domainObject = $inventory->objects->findByTypeAndName('domain', $domain);
        $domainId = $domainObject !== null ? (string) $domainObject['id'] : $inventory->objects->create(['type' => 'domain', 'name' => $domain, 'description' => '']);
        $checkId = null;
        $hasCheck = false;
        foreach ($checks->forObject($domainId) as $check) {
            $hasCheck = $hasCheck || ($check['type'] === 'domain_expiry' && CheckTypes::target('domain_expiry', $check['config']) === $domain);
        }
        if (!$hasCheck) {
            $checkId = $checks->create($domainId, 'domain_expiry', $valid['common'], $valid['config']);
        }
        if ($inventory->relationships->findEdge($objectId, $domainId, 'depends_on') === null) {
            $inventory->relate($objectId, $domainId, 'depends_on', '', Vocabulary::MANUAL_SOURCE);
        }

        return $checkId;
    }

    /**
     * Registrovaná doména k jménu hosta (shop.example.com -> example.com, www.example.co.uk -> example.co.uk).
     */
    public static function registrableDomain(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !CheckTypes::isValidHost($host)) {
            return null;
        }
        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return null;
        }
        $take = in_array(implode('.', array_slice($labels, -2)), self::TWO_LEVEL_SUFFIXES, true) ? 3 : 2;

        return count($labels) >= $take ? implode('.', array_slice($labels, -$take)) : null;
    }

    /**
     * @param array<string, mixed> $object
     * @param array<string, string> $attributes platné hodnoty atributů (klíč => hodnota)
     * @param list<array<string, mixed>> $checks existující kontroly objektu
     * @param list<string> $coveredDomains domény, na kterých objekt už závisí a mají kontrolu expirace
     * @return list<array{key: string, type: string, input: array<string, string>, target: string, via: string}>
     */
    public static function forObject(array $object, array $attributes, array $checks, array $coveredDomains = []): array
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
                $host = parse_url($url, PHP_URL_HOST);
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                $domain = is_string($host) && in_array($scheme, ['http', 'https'], true) ? self::registrableDomain($host) : null;
                if ($domain !== null && !in_array($domain, $coveredDomains, true)) {
                    $suggestions[] = self::make('domain_expiry', ['domain' => $domain]) + ['via' => 'domain'];
                }
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
            case 'external':
                // Externí služba (CDN, veřejné DNS, peering): HTTP na url, jinak ping na IP nebo jméno.
                self::addHttp($suggestions, $url);
                $target = $host !== '' ? $host : $hostFromName;
                if ($url === '' && $target !== '' && CheckTypes::isValidHost(strtolower($target))) {
                    $suggestions[] = self::make('ping', ['host' => strtolower($target)]);
                }
                break;
            case 'service':
            case 'database':
            case 'container':
            case 'cluster':
            case 'provider':
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
            $result[] = ['key' => $s['type'] . ':' . $target, 'type' => $s['type'], 'input' => $s['input'], 'target' => $target, 'via' => $s['via'] ?? ''];
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
