<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

use DateTimeImmutable;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\CheckResultRepository;
use Nordvarr\Time;
use PDO;

/**
 * Zápis výsledků kontrol do historie a stavu, příjem heartbeatů.
 */
final class CheckService
{
    public readonly CheckRepository $checks;
    public readonly CheckResultRepository $results;

    public function __construct(private readonly PDO $pdo)
    {
        $this->checks = new CheckRepository($pdo);
        $this->results = new CheckResultRepository($pdo);
    }

    /**
     * Uloží výsledek do historie a promítne ho do stavu (pokud není starší než poslední).
     *
     * @param array<string, mixed> $check
     * @return array{applied: bool, from: string, to: string}
     */
    public function record(array $check, CheckResult $result): array
    {
        $this->pdo->beginTransaction();
        try {
            $id = (string) $check['id'];
            $this->results->insert($id, $result);
            $outcome = StateMachine::apply($this->checks->state($id), $result, (int) $check['failure_threshold']);
            if ($outcome['applied']) {
                $this->checks->saveState($id, $outcome['state']);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['applied' => $outcome['applied'], 'from' => $outcome['from'], 'to' => $outcome['to']];
    }

    /**
     * Příjem signálu heartbeatu: úspěšný výsledek a nový termín dalšího signálu.
     *
     * @param array<string, mixed> $check
     */
    public function heartbeat(array $check, ?DateTimeImmutable $now = null): void
    {
        $now ??= Time::now();
        $at = Time::toDb($now);
        $this->record($check, CheckResult::healthy($at, $at, null, 'signál přijat'));
        $state = $this->checks->state((string) $check['id']);
        $state['last_ping_at'] = $at;
        $this->checks->saveState((string) $check['id'], $state);
        $this->checks->setNextRun((string) $check['id'], Time::toDb($now->modify('+' . self::heartbeatWindow($check) . ' seconds')));
    }

    /**
     * Vyhodnocení heartbeatu workerem: když signál nepřišel do intervalu + tolerance,
     * vznikne výsledek unhealthy. Vrací null, když lhůta ještě neuplynula.
     *
     * @param array<string, mixed> $check
     */
    public function evaluateHeartbeat(array $check, DateTimeImmutable $now): ?CheckResult
    {
        $last = (string) ($check['last_ping_at'] ?? $check['created_at']);
        $deadline = Time::fromDb($last)->modify('+' . self::heartbeatWindow($check) . ' seconds');
        if ($now <= $deadline) {
            $this->checks->setNextRun((string) $check['id'], Time::toDb($deadline->modify('+1 second')));

            return null;
        }
        $at = Time::toDb($now);
        $detail = isset($check['last_ping_at']) && $check['last_ping_at'] !== null
            ? 'poslední signál ' . $check['last_ping_at']
            : 'signál zatím nepřišel';
        $this->checks->setNextRun((string) $check['id'], Time::toDb($now->modify('+' . (int) $check['interval_s'] . ' seconds')));

        return new CheckResult(Status::UNHEALTHY, Reason::HEARTBEAT_MISSED, $detail, null, $at, $at);
    }

    /**
     * @param array<string, mixed> $check
     */
    public static function heartbeatWindow(array $check): int
    {
        return (int) $check['interval_s'] + (int) ($check['config']['grace_s'] ?? 0);
    }
}
