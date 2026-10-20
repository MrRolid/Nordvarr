<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

/**
 * Promítnutí výsledku kontroly do jejího aktuálního stavu.
 *
 * - Návrat do healthy hned po prvním úspěchu.
 * - Do horšího stavu (i z unknown) až po failure_threshold po sobě jdoucích selháních;
 *   pak stav sleduje poslední výsledek (degraded <-> unhealthy).
 * - Výsledek starší než poslední započítaný se uloží jen do historie, stav nemění.
 */
final class StateMachine
{
    /**
     * @param array<string, mixed> $state řádek check_state
     * @return array{state: array<string, mixed>, applied: bool, from: string, to: string}
     */
    public static function apply(array $state, CheckResult $result, int $threshold): array
    {
        $from = (string) $state['status'];
        $lastResultAt = $state['last_result_at'] ?? null;
        if (is_string($lastResultAt) && strcmp($result->startedAt, $lastResultAt) < 0) {
            return ['state' => $state, 'applied' => false, 'from' => $from, 'to' => $from];
        }

        $state['last_run_at'] = max((string) ($state['last_run_at'] ?? ''), $result->finishedAt);
        $state['last_result_at'] = $result->startedAt;
        $state['last_result_status'] = $result->status;
        $state['last_latency_ms'] = $result->latencyMs;
        if ($result->expiresAt !== null) {
            $state['expires_at'] = $result->expiresAt;
        }

        if ($result->status === Status::HEALTHY) {
            $state['consecutive_failures'] = 0;
            $to = Status::HEALTHY;
            $state['reason_code'] = null;
            $state['reason_detail'] = $result->detail;
        } else {
            $state['consecutive_failures'] = (int) ($state['consecutive_failures'] ?? 0) + 1;
            $to = $from;
            if ($state['consecutive_failures'] >= max(1, $threshold)) {
                $to = $result->status;
                $state['reason_code'] = $result->reasonCode;
                $state['reason_detail'] = $result->detail;
            }
        }

        if ($to !== $from) {
            $state['since'] = $result->startedAt;
        }
        $state['status'] = $to;

        return ['state' => $state, 'applied' => true, 'from' => $from, 'to' => $to];
    }
}
