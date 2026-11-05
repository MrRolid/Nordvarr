<?php

declare(strict_types=1);

namespace Nordvarr\Agent;

use Nordvarr\Checks\CheckTypes;
use Nordvarr\Checks\Suggestions;
use Nordvarr\Inventory\EventIngest;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\ValidationException;
use Nordvarr\Repository\AgentRepository;
use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

/**
 * Zprávy z agenta mimo pravidelné hlášení: události ze serveru (fail2ban, objednávka,
 * formulář podpory, vzkaz AI agenta) a zakládání webů s cestami k logům. Server se
 * pozná stejně jako u hlášení (object_id, external_id nebo host:<hostname>) a musí
 * už mít aspoň jedno hlášení.
 */
final class AgentInbox
{
    private Inventory $inventory;

    public function __construct(private readonly PDO $pdo)
    {
        $this->inventory = new Inventory($pdo);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: string, created: bool, objects: list<string>}
     */
    public function event(array $input, string $source): array
    {
        $hostId = $this->host($input, $source);
        $objects = [$hostId];
        $site = $input['site'] ?? null;
        if ($site !== null) {
            if (!is_string($site) || ($siteId = $this->findSite($site)) === null) {
                throw new ValidationException(['site' => 'unknown_site']);
            }
            $objects[] = $siteId;
        }
        $eventId = $input['event_id'] ?? null;
        if ($eventId !== null && (!is_string($eventId) || $eventId === '' || mb_strlen($eventId) > 150)) {
            throw new ValidationException(['event_id' => 'invalid_event_id']);
        }
        // Id události je jedinečné jen v rámci serveru (fail2ban na dvou serverech může poslat stejné).
        $result = (new EventIngest($this->inventory))->run([
            'event_id' => $hostId . ':' . ($eventId ?? Uuid::v7()),
            'type' => $input['type'] ?? 'message',
            'severity' => $input['severity'] ?? 'info',
            'occurred_at' => is_string($input['occurred_at'] ?? null) && $input['occurred_at'] !== '' ? $input['occurred_at'] : Time::toDb(Time::now()),
            'summary' => $input['summary'] ?? null,
            'details' => $input['details'] ?? '',
            'objects' => array_map(static fn (string $id): array => ['id' => $id], $objects),
        ], $source);

        return $result + ['objects' => $objects];
    }

    /**
     * Web na serveru: najde (podle názvu) nebo založí objekt typu web, vztah "závisí na" server,
     * cesty k logům a volitelně doporučené kontroly.
     *
     * @param array<string, mixed> $input
     * @return array{object_id: string, created: bool, log_paths: list<string>, checks: list<string>}
     */
    public function site(array $input, string $source): array
    {
        $hostId = $this->host($input, $source);
        $name = is_string($input['name'] ?? null) ? strtolower(rtrim(trim($input['name']), '.')) : '';
        if ($name === '' || !CheckTypes::isValidHost($name) || !str_contains($name, '.') || filter_var($name, FILTER_VALIDATE_IP) !== false) {
            throw new ValidationException(['name' => 'invalid_site_name']);
        }
        $logs = $input['logs'] ?? null;
        $paths = null;
        if ($logs !== null) {
            if (!is_array($logs) || !array_is_list($logs) || array_filter($logs, 'is_string') !== $logs) {
                throw new ValidationException(['logs' => 'invalid_logs']);
            }
            $parsed = ObjectLogs::parse(implode("\n", $logs));
            if ($parsed['invalid'] !== []) {
                throw new ValidationException(['logs' => 'invalid_logs']);
            }
            $paths = $parsed['paths'];
        }

        return $this->inventory->transaction(function () use ($hostId, $name, $paths, $input, $source): array {
            $inv = $this->inventory;
            $siteId = $this->findSite($name);
            $created = $siteId === null;
            if ($created) {
                $siteId = $inv->objects->create(['type' => 'website', 'name' => $name, 'description' => '']);
            }
            if ($inv->relationships->findEdge($siteId, $hostId, 'depends_on') === null) {
                $inv->relate($siteId, $hostId, 'depends_on', '', $source);
            }
            if ($paths !== null) {
                if ($paths === []) {
                    $inv->attributes->remove($siteId, ObjectLogs::ATTRIBUTE, $source);
                } else {
                    $inv->attributes->set($siteId, ObjectLogs::ATTRIBUTE, implode("\n", $paths), false, $source);
                }
            }
            $checks = ($input['checks'] ?? false) === true
                ? array_map(static fn (array $c): string => $c['type'] . ' ' . $c['target'], Suggestions::apply($this->pdo, $siteId))
                : [];

            return ['object_id' => $siteId, 'created' => $created, 'log_paths' => ObjectLogs::parse(implode("\n", $this->logValues($siteId)))['paths'], 'checks' => $checks];
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    private function host(array $input, string $source): string
    {
        $hostname = is_string($input['hostname'] ?? null) ? trim($input['hostname']) : '';
        $objectId = $input['object_id'] ?? null;
        $externalId = is_string($input['external_id'] ?? null) && $input['external_id'] !== '' ? $input['external_id'] : 'host:' . strtolower($hostname);
        $id = null;
        if (is_string($objectId) && $objectId !== '') {
            $id = $this->inventory->objects->find($objectId) !== null ? $objectId : null;
        } elseif ($hostname !== '' || isset($input['external_id'])) {
            $id = $this->inventory->externalIds->objectId($source, $externalId);
        }
        if ($id === null || (new AgentRepository($this->pdo))->host($id) === null) {
            throw new ValidationException(['host' => 'unknown_host']);
        }

        return $id;
    }

    private function findSite(string $name): ?string
    {
        $row = $this->inventory->objects->findByTypeAndName('website', strtolower(rtrim(trim($name), '.')));

        return $row === null ? null : (string) $row['id'];
    }

    /**
     * @return list<string>
     */
    private function logValues(string $objectId): array
    {
        return array_values(array_map(
            static fn (array $a): string => (string) $a['value'],
            array_filter($this->inventory->attributes->forObject($objectId), static fn (array $a): bool => $a['key'] === ObjectLogs::ATTRIBUTE)
        ));
    }
}
