<?php

declare(strict_types=1);

namespace Nordvarr\Topology;

use Nordvarr\Agent\HostResources;
use Nordvarr\Agent\Report;
use Nordvarr\Alerts\MaintenanceState;
use Nordvarr\Checks\ObjectStatus;
use Nordvarr\Checks\Status;
use Nordvarr\I18n;
use Nordvarr\Repository\AgentRepository;
use Nordvarr\Repository\AlertRepository;
use Nordvarr\Repository\AttributeRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\ObjectRepository;
use Nordvarr\Repository\TagRepository;
use Nordvarr\Time;
use PDO;

/**
 * Data pro graf topologie: okolí vybraného objektu (1 nebo 2 kroky) nebo objekty
 * podle filtru (nejvýš 300 uzlů). Výsledek je ve tvaru prvků pro Cytoscape,
 * včetně rozvržení do pater (Floors) pro pohled "patra".
 */
final class Topology
{
    public const MAX_NODES = 300;

    public function __construct(private readonly PDO $pdo, private readonly I18n $i18n)
    {
    }

    /**
     * Id objektů v okolí (oba směry, oba druhy vztahů), centrum první.
     *
     * @return list<string>
     */
    public function neighborhoodIds(string $centerId, int $depth): array
    {
        $depth = max(1, min(2, $depth));
        $edges = $this->pdo->query('SELECT from_id, to_id FROM relationships')->fetchAll();
        $adjacent = [];
        foreach ($edges as $e) {
            $adjacent[(string) $e['from_id']][] = (string) $e['to_id'];
            $adjacent[(string) $e['to_id']][] = (string) $e['from_id'];
        }
        $seen = [$centerId => 0];
        $queue = [$centerId];
        while ($queue !== []) {
            $id = array_shift($queue);
            if ($seen[$id] >= $depth) {
                continue;
            }
            foreach ($adjacent[$id] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = $seen[$id] + 1;
                    $queue[] = $next;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Graf okolí objektu.
     *
     * @return array<string, mixed>
     */
    public function neighborhood(string $centerId, int $depth): array
    {
        $ids = $this->neighborhoodIds($centerId, $depth);
        $truncated = count($ids) > self::MAX_NODES;

        return $this->graph(array_slice($ids, 0, self::MAX_NODES), $centerId) + ['truncated' => $truncated, 'total' => count($ids)];
    }

    /**
     * Graf objektů podle filtru. Při více než 300 objektech vrátí jen počet a výzvu k zúžení.
     *
     * @param array{type?: string, tag?: string, status?: string} $filter
     * @return array<string, mixed>
     */
    public function filtered(array $filter): array
    {
        $objects = (new ObjectRepository($this->pdo))->search(['lifecycle' => 'active'] + $filter, 100000);
        if (($filter['status'] ?? '') !== '') {
            $statuses = $this->statuses();
            $objects = array_values(array_filter($objects, fn (array $o): bool => ($statuses[(string) $o['id']]['status'] ?? Status::NONE) === $filter['status']));
        }
        $total = count($objects);
        if ($total > self::MAX_NODES) {
            return ['nodes' => [], 'edges' => [], 'floors' => [], 'truncated' => true, 'total' => $total];
        }

        return $this->graph(array_map(static fn (array $o): string => (string) $o['id'], $objects), null) + ['truncated' => false, 'total' => $total];
    }

    /**
     * @return array<string, array{status: string, check: array<string, mixed>|null}>
     */
    private function statuses(): array
    {
        $paused = MaintenanceState::load($this->pdo, Time::toDb(Time::now()))->pausedObjects();

        return ObjectStatus::forObjects((new CheckRepository($this->pdo))->all(), $paused);
    }

    /**
     * @param list<string> $ids
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, floors: list<array<string, mixed>>}
     */
    private function graph(array $ids, ?string $centerId): array
    {
        if ($ids === []) {
            return ['nodes' => [], 'edges' => [], 'floors' => []];
        }
        $now = Time::toDb(Time::now());
        $maintenance = MaintenanceState::load($this->pdo, $now);
        $paused = $maintenance->pausedObjects();
        $objects = (new ObjectRepository($this->pdo))->findMany($ids);
        $tags = (new TagRepository($this->pdo))->forObjects($ids);
        $hosts = (new AgentRepository($this->pdo))->hosts($ids);
        $floorOverrides = (new AttributeRepository($this->pdo))->validValues($ids, Floors::ATTRIBUTE_KEYS);

        $checksByObject = [];
        foreach ((new CheckRepository($this->pdo))->all() as $check) {
            if (isset($objects[(string) $check['object_id']])) {
                $checksByObject[(string) $check['object_id']][] = $check;
            }
        }
        $alertsByObject = [];
        foreach ((new AlertRepository($this->pdo))->openAlerts() as $alert) {
            $alertsByObject[(string) $alert['object_id']][] = $alert;
        }

        $nodes = [];
        $causes = [];
        foreach ($ids as $id) {
            $object = $objects[$id] ?? null;
            if ($object === null) {
                continue;
            }
            $checks = $checksByObject[$id] ?? [];
            $state = ObjectStatus::forObject($object, $checks, $paused);
            $counts = array_fill_keys(['healthy', 'degraded', 'unhealthy', 'unknown', 'paused'], 0);
            $latency = null;
            foreach ($checks as $check) {
                $counts[ObjectStatus::checkStatus($check, $paused)]++;
                if ($latency === null && in_array($check['type'], ['http', 'tcp', 'ping'], true) && $check['last_latency_ms'] !== null) {
                    $latency = (int) $check['last_latency_ms'];
                }
            }
            $cause = null;
            foreach ($alertsByObject[$id] ?? [] as $alert) {
                if ($alert['probable_cause_object_id'] !== null) {
                    $cause = (string) $alert['probable_cause_object_id'];
                    $causes[$id] = $cause;
                    break;
                }
            }
            $worst = $state['check'];
            $status = $state['status'];
            $floor = Floors::floorOf((string) $object['type'], $floorOverrides[$id] ?? null);
            $nodes[] = ['data' => [
                'id' => $id,
                'name' => (string) $object['name'],
                'type' => (string) $object['type'],
                'typeLabel' => $this->i18n->t('type.' . $object['type']),
                'status' => $status,
                'statusLabel' => $this->i18n->t('status.' . $status),
                'reason' => $worst !== null && !in_array($status, [Status::HEALTHY, Status::PAUSED, Status::NONE], true)
                    ? $this->i18n->t('checktype.' . $worst['type']) . ((string) ($worst['reason_code'] ?? '') !== '' ? ': ' . $worst['reason_code'] : '') : '',
                'since' => $worst['since'] ?? null,
                'latency' => $latency,
                'checks' => count($checks),
                'ring' => $counts,
                'alerts' => count($alertsByObject[$id] ?? []),
                'cause' => $cause,
                'maintenance' => $maintenance->inMaintenance($id),
                'tags' => $tags[$id] ?? [],
                'center' => $id === $centerId,
                'subtitle' => isset($hosts[$id]) ? $this->hostSubtitle($hosts[$id]) : $this->subtitle($object, $status, $latency, count($checks)),
                'host' => isset($hosts[$id]),
                'resources' => isset($hosts[$id]) ? HostResources::evaluate($hosts[$id], self::agentConfig($checks)) : null,
                'floor' => $floor,
            ]];
        }

        $idSet = array_flip($ids);
        $edges = [];
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM relationships WHERE from_id IN ($placeholders) AND to_id IN ($placeholders) ORDER BY id");
        $stmt->execute([...$ids, ...$ids]);
        foreach ($stmt->fetchAll() as $rel) {
            $from = (string) $rel['from_id'];
            $to = (string) $rel['to_id'];
            if (!isset($idSet[$from], $idSet[$to])) {
                continue;
            }
            // Cesta k pravděpodobné příčině: X závisí na Y, nebo Y obsahuje X.
            $causal = ($rel['kind'] === 'depends_on' && ($causes[$from] ?? null) === $to)
                || ($rel['kind'] === 'contains' && ($causes[$to] ?? null) === $from);
            $edges[] = ['data' => [
                'id' => (string) $rel['id'],
                'source' => $from,
                'target' => $to,
                'kind' => (string) $rel['kind'],
                'label' => $this->i18n->t('rel.out.' . $rel['kind']),
                'note' => (string) $rel['note'],
                'causal' => $causal,
            ]];
        }

        $layout = Floors::layout(
            array_map(static fn (array $n): array => ['id' => $n['data']['id'], 'name' => $n['data']['name'], 'floor' => $n['data']['floor']], $nodes),
            array_map(static fn (array $e): array => ['source' => $e['data']['source'], 'target' => $e['data']['target']], $edges)
        );
        foreach ($nodes as &$node) {
            $node['data']['fx'] = $layout['positions'][$node['data']['id']]['x'];
            $node['data']['fy'] = $layout['positions'][$node['data']['id']]['y'];
        }
        unset($node);
        $floors = array_map(fn (array $b): array => $b + ['label' => $this->i18n->t('floor.' . $b['floor'])], $layout['bands']);

        return ['nodes' => $nodes, 'edges' => $edges, 'floors' => $floors];
    }

    /**
     * Prahy z kontroly typu agent (první nalezená), jinak výchozí.
     *
     * @param list<array<string, mixed>> $checks
     * @return array<string, mixed>
     */
    private static function agentConfig(array $checks): array
    {
        foreach ($checks as $check) {
            if ($check['type'] === 'agent' && is_array($check['config'])) {
                return $check['config'];
            }
        }

        return [];
    }

    /**
     * Druhý řádek popisku serveru s agentem: vytížení CPU a RAM (a GPU, pokud ho má).
     *
     * @param array<string, mixed> $host
     */
    private function hostSubtitle(array $host): string
    {
        $sample = Report::sample($host['snapshot']);
        $fmt = static fn (?float $v): string => $v === null ? '-' : (string) (int) round($v);
        $text = 'CPU ' . $fmt($sample['cpu_pct']) . " %\nRAM " . $fmt($sample['mem_pct']) . ' %';

        return $sample['gpu_util_max_pct'] === null ? $text : $text . "\nGPU " . $fmt($sample['gpu_util_max_pct']) . ' %';
    }

    /**
     * Druhý řádek popisku uzlu: latence, počet kontrol nebo typ.
     *
     * @param array<string, mixed> $object
     */
    private function subtitle(array $object, string $status, ?int $latency, int $checks): string
    {
        if ($checks === 0) {
            return $this->i18n->t('type.' . $object['type']);
        }
        if ($latency !== null && in_array($status, [Status::HEALTHY, Status::DEGRADED], true)) {
            return $latency . ' ms';
        }

        return $this->i18n->t('status.' . $status);
    }

    /**
     * Výchozí centrum okolí: objekt s nejstarším otevřeným nepotlačeným alertem
     * (nejdřív úroveň unhealthy, pak degraded), jinak objekt s nejvíce vztahy.
     */
    public function defaultCenter(): ?string
    {
        $roots = array_values(array_filter(
            (new AlertRepository($this->pdo))->openAlerts(),
            static fn (array $a): bool => $a['suppressed_by_alert_id'] === null
        ));
        foreach ([Status::UNHEALTHY, Status::DEGRADED] as $level) {
            foreach ($roots as $alert) {
                if ($alert['level'] === $level) {
                    return (string) $alert['object_id'];
                }
            }
        }
        $id = $this->pdo->query(
            "SELECT o.id FROM objects o LEFT JOIN relationships r ON r.from_id = o.id OR r.to_id = o.id
             WHERE o.lifecycle = 'active' GROUP BY o.id ORDER BY COUNT(r.id) DESC, o.name LIMIT 1"
        )->fetchColumn();

        return $id === false ? null : (string) $id;
    }
}
