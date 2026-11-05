<?php

declare(strict_types=1);

namespace Nordvarr\Export;

use DateTimeImmutable;
use DateTimeZone;
use Nordvarr\Agent\HostView;
use Nordvarr\Alerts\MaintenanceState;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Checks\ObjectStatus;
use Nordvarr\Checks\Status;
use Nordvarr\I18n;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\AlertRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\CheckResultRepository;
use Nordvarr\Repository\DocRepository;
use Nordvarr\Security\SecretMasker;
use Nordvarr\Time;
use PDO;

/**
 * Export pro LLM (SPEC kap. 10): kontext objektu pro incident jako data (API, JSON)
 * a jako Markdown ke zkopírování. Všechny texty prochází přísným maskováním,
 * výsledek má nejvýš 24 kB.
 */
final class LlmContext
{
    public const FORMAT = 'nordvarr-llm-context/1';
    public const MAX_BYTES = 24576;
    public const MAX_NEIGHBORS = 30;
    public const MAX_EVENTS = 30;
    public const MAX_DOC_CHARS = 4000;
    private const MAX_DETAIL_CHARS = 300;

    private Inventory $inventory;

    public function __construct(private readonly PDO $pdo, private readonly I18n $i18n, private readonly string $timezone = 'Europe/Prague')
    {
        $this->inventory = new Inventory($pdo);
    }

    /**
     * Kontext objektu jako data (už zamaskovaná a zkrácená na limit).
     *
     * @return array<string, mixed>|null null, když objekt neexistuje
     */
    public function build(string $objectId, ?DateTimeImmutable $now = null): ?array
    {
        $object = $this->inventory->objects->find($objectId);
        if ($object === null) {
            return null;
        }
        $now ??= Time::now();
        $nowDb = Time::toDb($now);
        $maintenance = MaintenanceState::load($this->pdo, $nowDb);
        $paused = $maintenance->pausedObjects();
        $checkRepo = new CheckRepository($this->pdo);
        $allChecks = $checkRepo->all();
        $statuses = ObjectStatus::forObjects($allChecks, $paused);
        $checks = array_values(array_filter($allChecks, static fn (array $c): bool => $c['object_id'] === $objectId));
        $state = ObjectStatus::forObject($object, $checks, $paused);
        $worst = $state['check'];

        $neighbors = $this->neighbors($objectId, $statuses, $now);
        $eventObjectIds = [$objectId, ...array_column($neighbors, 'id')];
        $names = $this->inventory->objects->names();

        $data = [
            'format' => self::FORMAT,
            'generated_at' => $nowDb,
            'object' => [
                'id' => $objectId,
                'name' => self::mask((string) $object['name']),
                'type' => (string) $object['type'],
                'lifecycle' => (string) $object['lifecycle'],
                'description' => self::mask((string) $object['description']),
                'tags' => $this->inventory->tags->forObject($objectId),
                'external_ids' => array_map(
                    static fn (array $e): array => ['source' => (string) $e['source'], 'external_id' => self::mask((string) $e['external_id'])],
                    $this->inventory->externalIds->forObject($objectId)
                ),
            ],
            'status' => [
                'status' => $state['status'],
                'reason' => $worst === null || in_array($state['status'], [Status::HEALTHY, Status::PAUSED, Status::NONE], true) ? null : [
                    'check_type' => (string) $worst['type'],
                    'check_name' => self::mask((string) $worst['name']),
                    'reason_code' => (string) ($worst['reason_code'] ?? ''),
                    'detail' => self::cut(self::mask((string) ($worst['reason_detail'] ?? '')), self::MAX_DETAIL_CHARS),
                    'since' => $worst['since'],
                ],
                'in_maintenance' => $maintenance->inMaintenance($objectId),
            ],
            'open_alerts' => $this->openAlerts($objectId),
            'checks_24h' => $this->checks($checks, $paused, $now),
            'neighbors' => $neighbors,
            'events_7d' => $this->events($eventObjectIds, $names, $now),
            'host' => $this->host($objectId, $checks),
            'attributes' => $this->attributes($objectId),
            'docs' => $this->docs($objectId),
            'truncated' => false,
        ];

        return $this->fit($data);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openAlerts(string $objectId): array
    {
        return array_map(static fn (array $a): array => [
            'check_type' => (string) $a['check_type'],
            'check_name' => self::mask((string) $a['check_name']),
            'level' => (string) $a['level'],
            'reason_code' => (string) ($a['reason_code'] ?? ''),
            'detail' => self::cut(self::mask((string) ($a['reason_detail'] ?? '')), self::MAX_DETAIL_CHARS),
            'opened_at' => (string) $a['opened_at'],
            'suppressed' => $a['suppressed_by_alert_id'] !== null,
            'in_maintenance' => (bool) $a['maintenance_suppressed'],
            'probable_cause' => $a['probable_cause_object_id'] === null ? null
                : ['id' => (string) $a['probable_cause_object_id'], 'name' => self::mask((string) $a['cause_name'])],
        ], (new AlertRepository($this->pdo))->openForObject($objectId));
    }

    /**
     * Souhrn kontrol za 24 h z hodinových agregací.
     *
     * @param list<array<string, mixed>> $checks
     * @param array<string, true> $paused
     * @return list<array<string, mixed>>
     */
    private function checks(array $checks, array $paused, DateTimeImmutable $now): array
    {
        $results = new CheckResultRepository($this->pdo);
        $since = $now->modify('-24 hours')->format('Y-m-d\TH:00:00\Z');
        $rows = [];
        foreach ($checks as $check) {
            $summary = $results->summary((string) $check['id'], $since);
            $rows[] = [
                'type' => (string) $check['type'],
                'name' => self::mask((string) $check['name']),
                'target' => self::mask(CheckTypes::target((string) $check['type'], $check['config'])),
                'status' => ObjectStatus::checkStatus($check, $paused),
                'reason_code' => (string) ($check['reason_code'] ?? ''),
                'runs' => $summary['count'],
                'failures' => $summary['failures'],
                'avg_latency_ms' => $summary['avg_latency_ms'],
            ];
        }

        return $rows;
    }

    /**
     * Závislosti a závislé objekty do 2 kroků v obou směrech, max. 30.
     * Řazení: nezdravé (od nejhoršího), nedávno změněné, bližší.
     *
     * @param array<string, array{status: string, check: array<string, mixed>|null}> $statuses
     * @return list<array<string, mixed>>
     */
    private function neighbors(string $objectId, array $statuses, DateTimeImmutable $now): array
    {
        // Sousedé každého uzlu s rolí souseda vůči němu.
        $adjacent = [];
        foreach ($this->inventory->relationships->all() as $rel) {
            $from = (string) $rel['from_id'];
            $to = (string) $rel['to_id'];
            $dependsOn = $rel['kind'] === 'depends_on';
            $adjacent[$from][] = [$to, $dependsOn ? 'dependency' : 'child'];
            $adjacent[$to][] = [$from, $dependsOn ? 'dependent' : 'parent'];
        }
        $found = [];
        foreach ($adjacent[$objectId] ?? [] as [$id, $role]) {
            if ($id !== $objectId) {
                $found[$id] ??= ['distance' => 1, 'role' => $role, 'via' => null];
            }
        }
        foreach ($found as $viaId => $first) {
            if ($first['distance'] !== 1) {
                continue;
            }
            foreach ($adjacent[$viaId] ?? [] as [$id, $role]) {
                if ($id !== $objectId && !isset($found[$id])) {
                    $found[$id] = ['distance' => 2, 'role' => $role, 'via' => $viaId];
                }
            }
        }
        if ($found === []) {
            return [];
        }

        $objects = $this->inventory->objects->findMany(array_keys($found));
        $lastChange = $this->lastChanges(array_keys($found), Time::toDb($now->modify('-7 days')));
        $rows = [];
        foreach ($found as $id => $info) {
            if (!isset($objects[$id])) {
                continue;
            }
            $status = $statuses[$id]['status'] ?? Status::NONE;
            $rows[] = [
                'id' => $id,
                'name' => self::mask((string) $objects[$id]['name']),
                'type' => (string) $objects[$id]['type'],
                'status' => $status,
                'distance' => $info['distance'],
                'role' => $info['role'],
                'via' => $info['via'] === null ? null : ['id' => $info['via'], 'name' => self::mask((string) ($objects[$info['via']]['name'] ?? ''))],
                'last_change_at' => $lastChange[$id] ?? null,
            ];
        }
        $badness = static fn (string $s): int => in_array($s, [Status::UNHEALTHY, Status::DEGRADED, Status::UNKNOWN], true) ? Status::severity($s) : 0;
        usort($rows, static fn (array $a, array $b): int => [$badness($b['status']), (string) $b['last_change_at'], $a['distance'], $a['name']]
            <=> [$badness($a['status']), (string) $a['last_change_at'], $b['distance'], $b['name']]);

        return array_slice($rows, 0, self::MAX_NEIGHBORS);
    }

    /**
     * Čas poslední události u každého z objektů od daného okamžiku.
     *
     * @param list<string> $objectIds
     * @return array<string, string>
     */
    private function lastChanges(array $objectIds, string $since): array
    {
        $last = [];
        foreach ($this->inventory->events->timeline($objectIds, 500, $since) as $event) {
            foreach ($event['object_ids'] as $id) {
                $last[$id] ??= (string) $event['occurred_at'];
            }
        }

        return $last;
    }

    /**
     * @param list<string> $objectIds
     * @param array<string, string> $names
     * @return list<array<string, mixed>>
     */
    private function events(array $objectIds, array $names, DateTimeImmutable $now): array
    {
        $events = $this->inventory->events->timeline($objectIds, self::MAX_EVENTS, Time::toDb($now->modify('-7 days')));

        return array_map(static fn (array $e): array => [
            'occurred_at' => (string) $e['occurred_at'],
            'type' => (string) $e['type'],
            'severity' => (string) $e['severity'],
            'source' => (string) $e['source'],
            'summary' => self::mask((string) $e['summary']),
            'details' => self::cut(self::mask((string) $e['details']), self::MAX_DETAIL_CHARS),
            'objects' => array_map(static fn (string $id): string => self::mask($names[$id] ?? $id), $e['object_ids']),
        ], $events);
    }

    /**
     * Stav serveru z hostového agenta (null, když objekt agenta nemá).
     *
     * @param list<array<string, mixed>> $checks
     * @return array<string, mixed>|null
     */
    private function host(string $objectId, array $checks): ?array
    {
        $h = HostView::forObject($this->pdo, $objectId, $checks, false);
        if ($h === null) {
            return null;
        }
        $temps = array_slice($h['temps'], 0, 10);

        return [
            'hostname' => self::mask($h['hostname']),
            'last_report_at' => $h['last_report_at'],
            'stale' => $h['stale'],
            'os' => self::mask((string) $h['os']),
            'kernel' => self::mask((string) $h['kernel']),
            'uptime_s' => $h['uptime_s'],
            'cpu_pct' => $h['cpu']['pct'],
            'cores' => $h['cpu']['cores'],
            'load' => $h['cpu']['load'],
            'mem_pct' => $h['mem']['pct'],
            'mem_total_b' => $h['mem']['total_b'],
            'swap_pct' => $h['swap']['pct'],
            'disks' => array_map(static fn (array $d): array => [
                'mount' => self::mask($d['mount']), 'pct' => $d['pct'], 'size_b' => $d['size_b'], 'inodes_pct' => $d['inodes_pct'],
            ], $h['disks']),
            'temperatures' => array_map(static fn (array $t): array => ['name' => self::mask($t['name']), 'c' => $t['c'], 'crit_c' => $t['crit_c']], $temps),
            'gpus' => array_map(static fn (array $g): array => [
                'index' => $g['index'], 'name' => self::mask((string) $g['name']), 'util_pct' => $g['util_pct'], 'mem_pct' => $g['mem_pct'],
                'temp_c' => $g['temp_c'], 'power_w' => $g['power_w'],
            ], $h['gpus']),
            'smart' => array_map(static fn (array $d): array => [
                'device' => self::mask($d['device']), 'model' => self::mask($d['model']), 'health_passed' => $d['health_passed'],
                'wear_pct' => $d['wear_pct'], 'temp_c' => $d['temp_c'], 'power_on_hours' => $d['power_on_hours'],
                'problems' => array_map(static fn (array $p): string => self::mask($p['text']), $d['problems']),
            ], $h['smart']),
            'smart_at' => $h['smart_at'],
            'problems' => array_map(static fn (array $p): string => self::mask($p['text']), $h['problems']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attributes(string $objectId): array
    {
        $rows = [];
        foreach ($this->inventory->attributes->grouped($objectId) as $group) {
            $key = $group['key'];
            $row = [
                'key' => $key,
                'value' => $group['valid'] === null ? null : SecretMasker::maskAttributeStrict($key, $group['valid']),
                'conflict' => $group['conflict'],
            ];
            if ($group['conflict']) {
                $row['values'] = array_map(static fn (array $v): array => [
                    'source' => (string) $v['source'],
                    'value' => SecretMasker::maskAttributeStrict($key, (string) $v['value']),
                ], $group['values']);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array{markdown: string, truncated: bool}|null
     */
    private function docs(string $objectId): ?array
    {
        $parts = [];
        $docs = array_filter($this->inventory->docs->all($objectId), static fn (array $d): bool => trim((string) $d['markdown']) !== '');
        foreach ($docs as $category => $doc) {
            // Jen provozní dokumentace jde bez nadpisu, ostatní kategorie dostanou nadpis s názvem.
            $text = trim((string) $doc['markdown']);
            if ($category !== DocRepository::MAIN || count($docs) > 1) {
                $text = '# ' . $this->i18n->t('docs.cat.' . $category) . "\n\n" . preg_replace('/^(#{1,5})(?=\s)/m', '#$1', $text);
            }
            $parts[] = $text;
        }
        if ($parts === []) {
            return null;
        }
        $markdown = self::mask(implode("\n\n", $parts));
        $truncated = mb_strlen($markdown) > self::MAX_DOC_CHARS;

        return ['markdown' => $truncated ? mb_substr($markdown, 0, self::MAX_DOC_CHARS) : $markdown, 'truncated' => $truncated];
    }

    /**
     * Zkrátí data tak, aby JSON i Markdown měly nejvýš 24 kB. Ubírá postupně
     * dokumentaci, události, okolí a atributy.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function fit(array $data): array
    {
        for ($i = 0; $i < 500 && $this->size($data) > self::MAX_BYTES; $i++) {
            $data['truncated'] = true;
            if ($data['docs'] !== null && mb_strlen($data['docs']['markdown']) > 500) {
                $data['docs'] = ['markdown' => mb_substr($data['docs']['markdown'], 0, intdiv(mb_strlen($data['docs']['markdown']), 2)), 'truncated' => true];
            } elseif (count($data['events_7d']) > 5) {
                array_pop($data['events_7d']);
            } elseif (count($data['neighbors']) > 5) {
                array_pop($data['neighbors']);
            } elseif (count($data['attributes']) > 5) {
                array_pop($data['attributes']);
            } elseif ($data['host'] !== null && count($data['host']['disks']) > 5) {
                array_pop($data['host']['disks']);
            } elseif (count($data['checks_24h']) > 5) {
                array_pop($data['checks_24h']);
            } else {
                break;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function size(array $data): int
    {
        return max(strlen(self::json($data)), strlen($this->markdown($data)));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Markdown ke zkopírování. Když by i po zkrácení dat přesáhl limit, ořízne se.
     *
     * @param array<string, mixed> $data
     */
    public function markdown(array $data): string
    {
        $t = $this->i18n;
        $o = $data['object'];
        $out = [];
        $out[] = '# ' . $t->t('llm.title', ['name' => $o['name'], 'type' => $t->t('type.' . $o['type'])]);
        $out[] = '';
        $out[] = $t->t('llm.generated', ['time' => $this->time($data['generated_at']), 'tz' => $this->timezone]) . ' ' . $t->t('llm.masked_note');
        $out[] = '';

        $out[] = '## ' . $t->t('llm.identification');
        $out[] = '- ' . $t->t('llm.type') . ': ' . $t->t('type.' . $o['type']);
        if ($o['lifecycle'] !== 'active') {
            $out[] = '- ' . $t->t('llm.lifecycle') . ': ' . $t->t('lifecycle.' . $o['lifecycle']);
        }
        if ($o['tags'] !== []) {
            $out[] = '- ' . $t->t('llm.tags') . ': ' . implode(', ', $o['tags']);
        }
        if ($o['external_ids'] !== []) {
            $out[] = '- ' . $t->t('llm.external_ids') . ': ' . implode(', ', array_map(static fn (array $e): string => $e['source'] . ':' . $e['external_id'], $o['external_ids']));
        }
        if ($o['description'] !== '') {
            $out[] = '- ' . $t->t('llm.description') . ': ' . self::oneLine($o['description']);
        }
        $out[] = '';

        $out[] = '## ' . $t->t('llm.status');
        $s = $data['status'];
        $line = '- ' . $t->t('llm.state') . ': ' . $t->t('status.' . $s['status']);
        if ($s['reason'] !== null) {
            $r = $s['reason'];
            $line .= ' (' . $t->t('checktype.' . $r['check_type']) . ': ' . $r['reason_code'] . ', ' . $t->t('llm.since', ['time' => $this->time($r['since'])]) . ')';
            $out[] = $line;
            if ($r['detail'] !== '') {
                $out[] = '- ' . $t->t('llm.detail') . ': ' . self::oneLine($r['detail']);
            }
        } else {
            $out[] = $line;
        }
        if ($s['in_maintenance']) {
            $out[] = '- ' . $t->t('llm.in_maintenance');
        }
        if ($data['open_alerts'] === []) {
            $out[] = '- ' . $t->t('llm.no_open_alerts');
        }
        foreach ($data['open_alerts'] as $a) {
            $line = '- ' . $t->t('llm.alert', [
                'check' => trim($t->t('checktype.' . $a['check_type']) . ' ' . $a['check_name']),
                'level' => $t->t('status.' . $a['level']),
                'reason' => $a['reason_code'],
                'time' => $this->time($a['opened_at']),
            ]);
            if ($a['probable_cause'] !== null) {
                $line .= '; ' . $t->t('llm.probable_cause') . ': ' . $a['probable_cause']['name'];
            }
            if ($a['suppressed']) {
                $line .= '; ' . $t->t('llm.suppressed');
            }
            $out[] = $line;
        }
        $out[] = '';

        if ($data['host'] !== null) {
            $h = $data['host'];
            $pct = static fn (?float $v): string => $v === null ? '-' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . ' %';
            $out[] = '## ' . $t->t('llm.host');
            $out[] = '- ' . $t->t('llm.host_report', ['time' => $this->time($h['last_report_at'])]) . ($h['stale'] ? ' (' . $t->t('llm.host_stale') . ')' : '');
            $out[] = '- ' . trim($h['os'] . ', ' . $h['kernel'], ', ') . ', ' . $t->t('llm.host_uptime', ['days' => intdiv((int) $h['uptime_s'], 86400)]);
            $out[] = '- CPU ' . $pct($h['cpu_pct']) . ' (' . $t->t('host.cores', ['n' => (int) $h['cores']]) . ', load ' . implode(' / ', array_map(static fn ($l): string => $l === null ? '-' : (string) $l, $h['load'])) . ')'
                . ', RAM ' . $pct($h['mem_pct']) . ', swap ' . $pct($h['swap_pct']);
            foreach ($h['disks'] as $d) {
                $out[] = '- ' . $t->t('llm.host_disk', ['mount' => $d['mount'], 'pct' => $pct($d['pct']), 'inodes' => $pct($d['inodes_pct'])]);
            }
            if ($h['temperatures'] !== []) {
                $out[] = '- ' . $t->t('llm.host_temps') . ': ' . implode(', ', array_map(static fn (array $x): string => $x['name'] . ' ' . $x['c'] . ' °C', $h['temperatures']));
            }
            foreach ($h['gpus'] as $g) {
                $out[] = '- GPU ' . $g['index'] . ' ' . $g['name'] . ': ' . $pct($g['util_pct']) . ', VRAM ' . $pct($g['mem_pct']) . ', ' . ($g['temp_c'] ?? '-') . ' °C'
                    . ($g['power_w'] !== null ? ', ' . $g['power_w'] . ' W' : '');
            }
            foreach ($h['smart'] as $d) {
                $out[] = '- ' . $t->t('llm.host_smart', [
                    'device' => trim($d['device'] . ' ' . $d['model']),
                    'health' => $d['health_passed'] === false ? $t->t('host.health_failing') : ($d['problems'] === [] ? $t->t('host.health_ok') : $t->t('host.health_warn')),
                    'wear' => $pct($d['wear_pct']),
                    'hours' => $d['power_on_hours'] ?? '-',
                ]) . ($d['problems'] !== [] ? ': ' . implode('; ', $d['problems']) : '');
            }
            if ($h['problems'] !== []) {
                $out[] = '- ' . $t->t('llm.host_problems') . ': ' . implode('; ', $h['problems']);
            }
            $out[] = '';
        }

        $out[] = '## ' . $t->t('llm.checks');
        if ($data['checks_24h'] === []) {
            $out[] = $t->t('llm.no_checks');
        } else {
            $out[] = '| ' . implode(' | ', [$t->t('llm.col.check'), $t->t('llm.col.target'), $t->t('llm.col.status'), $t->t('llm.col.runs'), $t->t('llm.col.failures'), $t->t('llm.col.latency')]) . ' |';
            $out[] = '|---|---|---|---:|---:|---:|';
            foreach ($data['checks_24h'] as $c) {
                $status = $t->t('status.' . $c['status']) . ($c['reason_code'] !== '' && $c['status'] !== Status::HEALTHY ? ' (' . $c['reason_code'] . ')' : '');
                $out[] = '| ' . implode(' | ', array_map(self::cell(...), [
                    trim($t->t('checktype.' . $c['type']) . ' ' . $c['name']), $c['target'], $status,
                    (string) $c['runs'], (string) $c['failures'], $c['avg_latency_ms'] === null ? '-' : $c['avg_latency_ms'] . ' ms',
                ])) . ' |';
            }
        }
        $out[] = '';

        $out[] = '## ' . $t->t('llm.neighbors');
        if ($data['neighbors'] === []) {
            $out[] = $t->t('llm.no_neighbors');
        } else {
            $out[] = '| ' . implode(' | ', [$t->t('llm.col.object'), $t->t('llm.col.type'), $t->t('llm.col.relation'), $t->t('llm.col.status'), $t->t('llm.col.last_change')]) . ' |';
            $out[] = '|---|---|---|---|---|';
            foreach ($data['neighbors'] as $n) {
                // Ve 2. kroku je vztah popsaný vůči prostřednímu objektu.
                $relation = $n['via'] === null ? $t->t('llm.role.' . $n['role']) : $t->t('llm.role2.' . $n['role'], ['name' => $n['via']['name']]);
                $out[] = '| ' . implode(' | ', array_map(self::cell(...), [
                    $n['name'], $t->t('type.' . $n['type']), $relation, $t->t('status.' . $n['status']),
                    $n['last_change_at'] === null ? '-' : $this->time($n['last_change_at']),
                ])) . ' |';
            }
        }
        $out[] = '';

        $out[] = '## ' . $t->t('llm.events');
        if ($data['events_7d'] === []) {
            $out[] = $t->t('llm.no_events');
        }
        foreach ($data['events_7d'] as $e) {
            $label = $t->t('event.type.' . $e['type']) . ($e['severity'] !== 'info' ? ', ' . $t->t('event.severity.' . $e['severity']) : '');
            $line = '- ' . $this->time($e['occurred_at']) . ' [' . $label . '] ' . self::oneLine($e['summary'])
                . ' (' . implode(', ', $e['objects']) . ')';
            if ($e['details'] !== '') {
                $line .= ': ' . self::oneLine($e['details']);
            }
            $out[] = $line;
        }
        $out[] = '';

        $out[] = '## ' . $t->t('llm.attributes');
        if ($data['attributes'] === []) {
            $out[] = $t->t('llm.no_attributes');
        }
        foreach ($data['attributes'] as $a) {
            if ($a['conflict']) {
                $values = implode('; ', array_map(static fn (array $v): string => $v['source'] . ' = ' . self::oneLine($v['value']), $a['values']));
                $out[] = '- ' . $a['key'] . ': ' . ($a['value'] === null ? '' : self::oneLine($a['value']) . ' ') . '(' . $t->t('llm.conflict') . ': ' . $values . ')';
            } else {
                $out[] = '- ' . $a['key'] . ': ' . self::oneLine((string) $a['value']);
            }
        }
        $out[] = '';

        $out[] = '## ' . $t->t('llm.docs');
        if ($data['docs'] === null) {
            $out[] = $t->t('llm.no_docs');
        } else {
            // Nadpisy dokumentace o dvě úrovně níž, aby nerozbily strukturu exportu.
            $out[] = preg_replace('/^(#{1,4})(?=\s)/m', '##$1', $data['docs']['markdown']) ?? $data['docs']['markdown'];
            if ($data['docs']['truncated']) {
                $out[] = '';
                $out[] = $t->t('llm.docs_truncated');
            }
        }
        if ($data['truncated']) {
            $out[] = '';
            $out[] = $t->t('llm.truncated', ['kb' => intdiv(self::MAX_BYTES, 1024)]);
        }

        $markdown = implode("\n", $out) . "\n";
        if (strlen($markdown) > self::MAX_BYTES) {
            $markdown = mb_strcut($markdown, 0, self::MAX_BYTES - 200) . "\n\n" . $t->t('llm.truncated', ['kb' => intdiv(self::MAX_BYTES, 1024)]) . "\n";
        }

        return $markdown;
    }

    private function time(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '-';
        }

        return (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone($this->timezone))->format('j. n. Y H:i');
    }

    private static function mask(string $text): string
    {
        return SecretMasker::maskStrict($text);
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 3) . '...' : $text;
    }

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private static function cell(string $text): string
    {
        return str_replace('|', '\|', self::oneLine($text));
    }
}
