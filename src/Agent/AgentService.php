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
 * Kontejnery Dockeru z hlášení udržuje jako objekty typu kontejner pod serverem, každý
 * s vlastní kontrolou typu "container", kterou vyhodnotí každé hlášení.
 */
final class AgentService
{
    /** Zdroj atributů a vztahů, které spravuje agent. */
    public const SOURCE = 'agent';

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
     * @return array{object_id: string, created: bool, check_id: string, status: string, problems: int, log_paths: list<string>}
     */
    public function ingest(array $input, string $source, ?DateTimeImmutable $now = null): array
    {
        $now ??= Time::now();
        $report = Report::normalize($input, Time::toDb($now));
        $snapshot = $report['snapshot'];
        $at = $snapshot['collected_at'];

        [$objectId, $created, $checkId, $containerResults] = $this->inventory->transaction(function () use ($report, $source, $snapshot, $at): array {
            [$objectId, $created] = $this->resolveObject($report, $source);
            $this->agents->saveReport($objectId, $snapshot, $report['smart'], $report['smart_error'], $at);
            $this->agents->insertSample($objectId, $at, Report::sample($snapshot));
            if ($report['logs'] !== null) {
                $this->agents->saveLogs($objectId, $report['logs'], $at);
            }
            $containerResults = $report['containers'] !== null
                ? $this->syncContainers($objectId, $report['external_id'], $report['containers'], $source) : [];

            return [$objectId, $created, $this->ensureCheck($objectId, 'agent'), $containerResults];
        });

        // Kontroly kontejnerů (zápis výsledku má vlastní transakci, proto až po synchronizaci).
        foreach ($containerResults as [$containerCheckId, $r]) {
            $containerCheck = $this->checks->checks->find($containerCheckId);
            if ($containerCheck !== null && $containerCheck['enabled']) {
                $this->checks->record($containerCheck, new CheckResult($r['status'], $r['reason'], $r['detail'], null, $at, $at));
                $this->checks->checks->setNextRun($containerCheckId, Time::toDb($now->modify('+' . self::window($containerCheck) . ' seconds')));
            }
        }

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

        return [
            'object_id' => $objectId,
            'created' => $created,
            'check_id' => $checkId,
            'status' => $status,
            'problems' => count($problems),
            // Logy webů a služeb, které má agent číst (agent je ověří proti svým povoleným adresářům).
            'log_paths' => ObjectLogs::pathsForHost($this->pdo, $objectId),
        ];
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
     * Vyhodnocení kontroly kontejneru workerem: když server, který kontejner obsahuje, přestal hlásit,
     * stav kontejneru není známý. Vrací null, když hlášení serveru chodí.
     *
     * @param array<string, mixed> $check
     */
    public function evaluateContainerMissing(array $check, DateTimeImmutable $now): ?CheckResult
    {
        $stmt = $this->pdo->prepare(
            "SELECT h.last_report_at FROM relationships r JOIN agent_hosts h ON h.object_id = r.from_id
             WHERE r.to_id = ? AND r.kind = 'contains' ORDER BY h.last_report_at DESC LIMIT 1"
        );
        $stmt->execute([(string) $check['object_id']]);
        $last = $stmt->fetchColumn();
        $last = $last === false ? (string) $check['created_at'] : (string) $last;
        $deadline = Time::fromDb($last)->modify('+' . self::window($check) . ' seconds');
        if ($now <= $deadline) {
            $this->checks->checks->setNextRun((string) $check['id'], Time::toDb($deadline->modify('+1 second')));

            return null;
        }
        $this->checks->checks->setNextRun((string) $check['id'], Time::toDb($now->modify('+' . (int) $check['interval_s'] . ' seconds')));
        $at = Time::toDb($now);

        return new CheckResult(Status::UNKNOWN, Reason::AGENT_MISSING, 'server s kontejnerem se neozval, poslední hlášení ' . $this->localTime($last), null, $at, $at);
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
     * Kontejnery z hlášení jako objekty typu kontejner: server je obsahuje, atributy (obraz, stav,
     * healthcheck, projekt compose) spravuje agent. Kontejner, který v hlášení chybí, se archivuje;
     * když se vrátí, archivace se zruší. Externí id: docker:<externí id serveru>/<jméno kontejneru>.
     * Vrací výsledky kontrol kontejnerů k zápisu (id kontroly, vyhodnocení).
     *
     * @param list<array<string, mixed>> $containers
     * @return list<array{0: string, 1: array{status: string, reason: ?string, detail: string}}>
     */
    private function syncContainers(string $hostId, string $hostExternalId, array $containers, string $source): array
    {
        $results = [];
        $inv = $this->inventory;
        $prefix = 'docker:' . $hostExternalId . '/';
        $known = $inv->externalIds->withPrefix($source, $prefix);
        $seen = [];
        foreach ($containers as $ct) {
            $externalId = $prefix . $ct['name'];
            $id = $known[$externalId] ?? null;
            if ($id === null) {
                $id = $inv->objects->create(['type' => 'container', 'name' => $ct['name'], 'description' => '']);
                $inv->externalIds->add($id, $source, $externalId);
            } else {
                $inv->objects->update($id, ['lifecycle' => 'active']);
            }
            $seen[$externalId] = true;
            if ($id !== $hostId && $inv->relationships->findEdge($hostId, $id, 'contains') === null
                && !$inv->relationships->wouldCreateContainsCycle($hostId, $id)) {
                $inv->relate($hostId, $id, 'contains', '', self::SOURCE);
            }
            foreach (['image' => $ct['image'], 'docker_state' => $ct['state'], 'health' => $ct['health'], 'compose_project' => $ct['project']] as $key => $value) {
                if ($value === '') {
                    $inv->attributes->remove($id, $key, self::SOURCE);
                } else {
                    $inv->attributes->set($id, $key, $value, false, self::SOURCE);
                }
            }
            $results[] = [$this->ensureCheck($id, 'container'), ContainerEvaluator::evaluate($ct)];
        }
        foreach ($known as $externalId => $id) {
            if (!isset($seen[$externalId]) && in_array('lifecycle', $inv->objects->update($id, ['lifecycle' => 'archived']), true)) {
                $results[] = [$this->ensureCheck($id, 'container'), ['status' => Status::UNKNOWN, 'reason' => Reason::CONTAINER_MISSING, 'detail' => 'kontejner v hlášení serveru chybí (smazaný)']];
            }
        }

        return $results;
    }

    /**
     * Kontrola typu "agent" (server) nebo "container" u objektu; první hlášení ji založí s výchozím nastavením.
     */
    private function ensureCheck(string $objectId, string $type): string
    {
        foreach ($this->checks->checks->forObject($objectId) as $check) {
            if ($check['type'] === $type) {
                return (string) $check['id'];
            }
        }
        $valid = CheckTypes::validate($type, []);

        return $this->checks->checks->create($objectId, $type, $valid['common'], $valid['config']);
    }

    private function localTime(string $utc): string
    {
        $tz = (new SettingsRepository($this->pdo))->get('timezone', 'UTC') ?? 'UTC';
        $zone = new \DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC');

        return Time::fromDb($utc)->setTimezone($zone)->format('j. n. Y H:i');
    }
}
