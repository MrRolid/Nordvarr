<?php

declare(strict_types=1);

namespace Nordvarr\Agent;

use DateTimeImmutable;
use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\CheckService;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Checks\Reason;
use Nordvarr\Checks\Status;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\ValidationException;
use Nordvarr\Repository\AgentRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Time;
use PDO;

/**
 * Příjem hlášení hostového agenta: najde nebo založí objekt serveru, uloží metriky,
 * založí kontrolu typu "agent" a hned ji vyhodnotí. Worker pak hlídá, jestli hlášení chodí.
 */
final class AgentService
{
    private Inventory $inventory;
    private AgentRepository $agents;
    private CheckService $checks;

    public function __construct(private readonly PDO $pdo)
    {
        $this->inventory = new Inventory($pdo);
        $this->agents = new AgentRepository($pdo);
        $this->checks = new CheckService($pdo);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{object_id: string, created: bool, check_id: string, status: string, problems: int}
     */
    public function ingest(array $input, string $source, ?DateTimeImmutable $now = null): array
    {
        $now ??= Time::now();
        $report = Report::normalize($input, Time::toDb($now));
        $snapshot = $report['snapshot'];
        $at = $snapshot['collected_at'];

        [$objectId, $created, $checkId] = $this->inventory->transaction(function () use ($report, $source, $snapshot, $at): array {
            [$objectId, $created] = $this->resolveObject($report, $source);
            $this->agents->saveReport($objectId, $snapshot, $report['smart'], $report['smart_error'], $at);
            $this->agents->insertSample($objectId, $at, Report::sample($snapshot));

            return [$objectId, $created, $this->ensureCheck($objectId)];
        });

        $check = (array) $this->checks->checks->find($checkId);
        $status = Status::UNKNOWN;
        $problems = [];
        if ($check['enabled']) {
            $host = (array) $this->agents->host($objectId);
            $problems = HostEvaluator::problems($snapshot, $host['smart'], $check['config']);
            $summary = HostEvaluator::summarize($problems);
            $this->checks->record($check, new CheckResult($summary['status'], $summary['reason'], $summary['detail'], null, $at, $at));
            $status = $summary['status'];
            // Další vyhodnocení (chybějící hlášení) až po intervalu a toleranci.
            $this->checks->checks->setNextRun($checkId, Time::toDb($now->modify('+' . self::window($check) . ' seconds')));
        }

        return ['object_id' => $objectId, 'created' => $created, 'check_id' => $checkId, 'status' => $status, 'problems' => count($problems)];
    }

    /**
     * Vyhodnocení workerem: když hlášení nepřišlo do intervalu + tolerance, je server "nefunguje".
     * Vrací null, když lhůta ještě neuplynula.
     *
     * @param array<string, mixed> $check
     */
    public function evaluateMissing(array $check, DateTimeImmutable $now): ?CheckResult
    {
        $host = $this->agents->host((string) $check['object_id']);
        $last = (string) ($host['last_report_at'] ?? $check['created_at']);
        $deadline = Time::fromDb($last)->modify('+' . self::window($check) . ' seconds');
        if ($now <= $deadline) {
            $this->checks->checks->setNextRun((string) $check['id'], Time::toDb($deadline->modify('+1 second')));

            return null;
        }
        $this->checks->checks->setNextRun((string) $check['id'], Time::toDb($now->modify('+' . (int) $check['interval_s'] . ' seconds')));
        $at = Time::toDb($now);
        $detail = $host === null ? 'agent se zatím neozval' : 'poslední hlášení ' . $this->localTime($last);

        return new CheckResult(Status::UNHEALTHY, Reason::AGENT_MISSING, $detail, null, $at, $at);
    }

    /**
     * @param array<string, mixed> $check
     */
    public static function window(array $check): int
    {
        return (int) $check['interval_s'] + (int) ($check['config']['grace_s'] ?? HostEvaluator::DEFAULTS['grace_s']);
    }

    /**
     * Objekt podle object_id z hlášení, jinak podle (zdroj tokenu, external_id), jinak nový server.
     *
     * @param array<string, mixed> $report
     * @return array{string, bool}
     */
    private function resolveObject(array $report, string $source): array
    {
        $inv = $this->inventory;
        $mapped = $inv->externalIds->objectId($source, $report['external_id']);
        if ($report['object_id'] !== null) {
            if ($inv->objects->find($report['object_id']) === null) {
                throw new ValidationException(['object_id' => 'unknown_object']);
            }
            if ($mapped !== null && $mapped !== $report['object_id']) {
                throw new ValidationException(['external_id' => 'external_id_conflict']);
            }
            if ($mapped === null) {
                $inv->externalIds->add($report['object_id'], $source, $report['external_id']);
            }

            return [$report['object_id'], false];
        }
        if ($mapped !== null) {
            return [$mapped, false];
        }
        $id = $inv->objects->create(['type' => 'server', 'name' => $report['snapshot']['hostname'], 'description' => '']);
        $inv->externalIds->add($id, $source, $report['external_id']);

        return [$id, true];
    }

    /**
     * Kontrola typu "agent" u objektu; první hlášení ji založí s výchozími prahy.
     */
    private function ensureCheck(string $objectId): string
    {
        foreach ($this->checks->checks->forObject($objectId) as $check) {
            if ($check['type'] === 'agent') {
                return (string) $check['id'];
            }
        }
        $valid = CheckTypes::validate('agent', []);

        return $this->checks->checks->create($objectId, 'agent', $valid['common'], $valid['config']);
    }

    private function localTime(string $utc): string
    {
        $tz = (new SettingsRepository($this->pdo))->get('timezone', 'UTC') ?? 'UTC';
        $zone = new \DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC');

        return Time::fromDb($utc)->setTimezone($zone)->format('j. n. Y H:i');
    }
}
