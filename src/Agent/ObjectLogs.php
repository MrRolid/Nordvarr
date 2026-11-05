<?php

declare(strict_types=1);

namespace Nordvarr\Agent;

use Nordvarr\Repository\AgentRepository;
use PDO;

/**
 * Logy webů a služeb z agenta. Cesty k souborům má objekt v atributu "logy" (jedna cesta
 * na řádek). Agent na serveru, se kterým objekt souvisí (vztah do vzdálenosti 2), dostane
 * cesty v odpovědi na hlášení a soubory čte, pokud leží v jeho povolených adresářích.
 */
final class ObjectLogs
{
    public const ATTRIBUTE = 'logy';
    public const MAX_PATHS = 20;
    private const MAX_PATH_LENGTH = 300;

    /** Typy objektů, u kterých karta nabízí logy. */
    public const TYPES = ['website', 'service', 'database', 'container'];

    /**
     * Platné cesty z textu (oddělené novým řádkem, mezerou, čárkou nebo středníkem).
     *
     * @return array{paths: list<string>, invalid: list<string>}
     */
    public static function parse(string $value): array
    {
        $paths = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', trim($value)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (self::isValidPath($part)) {
                $paths[$part] = true;
            } else {
                $invalid[] = $part;
            }
        }

        return ['paths' => array_slice(array_keys($paths), 0, self::MAX_PATHS), 'invalid' => $invalid];
    }

    /**
     * Absolutní cesta jen z bezpečných znaků, bez "." a ".." (agent ji ještě ověří proti povoleným adresářům).
     */
    public static function isValidPath(string $path): bool
    {
        return strlen($path) <= self::MAX_PATH_LENGTH
            && preg_match('#^/[A-Za-z0-9._@+\-/]+$#', $path) === 1
            && preg_match('#(^|/)\.{1,2}(/|$)#', $path) !== 1
            && !str_ends_with($path, '/');
    }

    /**
     * Cesty k logům pro agenta na serveru: atribut "logy" objektů do vzdálenosti 2 od serveru.
     *
     * @return list<string>
     */
    public static function pathsForHost(PDO $pdo, string $hostId): array
    {
        $ids = array_values(array_diff(self::neighborhood($pdo, $hostId), [$hostId]));
        $paths = [];
        foreach (self::attributeValues($pdo, $ids) as $value) {
            foreach (self::parse($value)['paths'] as $path) {
                $paths[$path] = true;
            }
        }
        ksort($paths);

        return array_slice(array_keys($paths), 0, self::MAX_PATHS);
    }

    /**
     * Logy objektu pro kartu: každá cesta z atributu s posledními řádky z agentů souvisejících serverů.
     *
     * @return array{paths: list<string>, value: string, hosts: list<string>, entries: list<array{path: string, host: string, lines: ?string, logs_at: ?string}>}
     */
    public static function forObject(PDO $pdo, string $objectId): array
    {
        $value = self::attributeValues($pdo, [$objectId])[$objectId] ?? '';
        $paths = self::parse($value)['paths'];
        $hostIds = array_values(array_diff(self::neighborhood($pdo, $objectId), [$objectId]));
        $hosts = (new AgentRepository($pdo))->hosts($hostIds);
        $entries = [];
        foreach ($paths as $path) {
            $found = false;
            foreach ($hosts as $host) {
                foreach ((array) $host['logs'] as $log) {
                    if (($log['path'] ?? null) === $path) {
                        $entries[] = ['path' => $path, 'host' => (string) $host['hostname'], 'lines' => (string) $log['lines'], 'logs_at' => $host['logs_at']];
                        $found = true;
                    }
                }
            }
            if (!$found) {
                $entries[] = ['path' => $path, 'host' => '', 'lines' => null, 'logs_at' => null];
            }
        }

        return [
            'paths' => $paths,
            'value' => implode("\n", $paths),
            'hosts' => array_values(array_map(static fn (array $h): string => (string) $h['hostname'], $hosts)),
            'entries' => $entries,
        ];
    }

    /**
     * Objekty spojené vztahem (oběma směry) do vzdálenosti 2 včetně výchozího.
     *
     * @return list<string>
     */
    private static function neighborhood(PDO $pdo, string $id): array
    {
        $adjacent = [];
        foreach ($pdo->query('SELECT from_id, to_id FROM relationships')->fetchAll() as $e) {
            $adjacent[(string) $e['from_id']][] = (string) $e['to_id'];
            $adjacent[(string) $e['to_id']][] = (string) $e['from_id'];
        }
        $seen = [$id => true];
        $frontier = [$id];
        for ($depth = 0; $depth < 2; $depth++) {
            $next = [];
            foreach ($frontier as $current) {
                foreach ($adjacent[$current] ?? [] as $other) {
                    if (!isset($seen[$other])) {
                        $seen[$other] = true;
                        $next[] = $other;
                    }
                }
            }
            $frontier = $next;
        }

        return array_keys($seen);
    }

    /**
     * Hodnoty atributu "logy" (všechny zdroje spojené) podle objektu.
     *
     * @param list<string> $ids
     * @return array<string, string>
     */
    private static function attributeValues(PDO $pdo, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT object_id, value FROM object_attributes WHERE key = ? AND object_id IN ($placeholders) ORDER BY source");
        $stmt->execute([self::ATTRIBUTE, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (string) $row['object_id'];
            $out[$id] = isset($out[$id]) ? $out[$id] . "\n" . $row['value'] : (string) $row['value'];
        }

        return $out;
    }
}
