<?php

declare(strict_types=1);

namespace Nordvarr\Agent;

use Nordvarr\Checks\Reason;
use Nordvarr\Checks\Status;

/**
 * Stav kontejneru Dockeru pro kontrolu typu "container" podle posledního hlášení agenta.
 */
final class ContainerEvaluator
{
    /**
     * @param array{state: string, health: string, exit_code: ?int, status: string} $ct kontejner z Report::normalize
     * @return array{status: string, reason: ?string, detail: string}
     */
    public static function evaluate(array $ct): array
    {
        $result = static fn (string $status, ?string $reason, string $detail): array => ['status' => $status, 'reason' => $reason, 'detail' => $detail];
        $status = $ct['status'] !== '' ? $ct['status'] : $ct['state'];

        return match (true) {
            $ct['state'] === 'running' && $ct['health'] === 'unhealthy' => $result(Status::UNHEALTHY, Reason::CONTAINER_UNHEALTHY, 'healthcheck hlásí unhealthy (' . $status . ')'),
            $ct['state'] === 'running' => $result(Status::HEALTHY, null, $status),
            $ct['state'] === 'restarting' => $result(Status::UNHEALTHY, Reason::CONTAINER_DOWN, 'opakovaně se restartuje (' . $status . ')'),
            $ct['state'] === 'dead' => $result(Status::UNHEALTHY, Reason::CONTAINER_DOWN, 've stavu dead'),
            // Ukončení s kódem 0 je běžné (jednorázové úlohy, migrace), problém je jen nenulový kód.
            $ct['state'] === 'exited' && $ct['exit_code'] === 0 => $result(Status::HEALTHY, null, $status),
            $ct['state'] === 'exited' && $ct['exit_code'] !== null => $result(Status::UNHEALTHY, Reason::CONTAINER_DOWN, sprintf('skončil s kódem %d', $ct['exit_code'])),
            $ct['state'] === 'paused' => $result(Status::DEGRADED, Reason::CONTAINER_PAUSED, 'pozastavený (docker pause)'),
            $ct['state'] === 'created' => $result(Status::DEGRADED, Reason::CONTAINER_DOWN, 'vytvořený, ale nespuštěný'),
            default => $result(Status::UNKNOWN, Reason::UNKNOWN_ERROR, 'stav ' . $ct['state']),
        };
    }
}
