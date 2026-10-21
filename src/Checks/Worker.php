<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

use Nordvarr\Alerts\MaintenanceState;
use Nordvarr\Alerts\Notifier;
use Nordvarr\Repository\DeliveryRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Time;
use PDO;

/**
 * Worker spouštěný cronem každou minutu: kontroly na řadě, jednou za hodinu
 * agregace a retence, nakonec zápis času posledního běhu.
 */
final class Worker
{
    public const MAX_RUNTIME = 50;
    public const RESULTS_RETENTION_DAYS = 14;
    public const HOURLY_RETENTION_DAYS = 400;

    private CheckService $service;
    private SettingsRepository $settings;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CurlRunner $curl,
        private readonly PingRunner $ping,
        private readonly RdapRunner $rdap,
        private readonly ?Notifier $notifier = null,
    ) {
        $this->service = new CheckService($pdo);
        $this->settings = new SettingsRepository($pdo);
    }

    /**
     * @return array{checks: int, results: int, maintenance: bool, notifications: array<string, int>|null}
     */
    public function run(?float $deadline = null): array
    {
        $deadline ??= microtime(true) + self::MAX_RUNTIME;
        $now = Time::now();
        $due = $this->service->checks->due(Time::toDb($now));
        // Údržbové okno s pozastavením: kontroly objektu neběží (a po konci okna jsou hned na řadě).
        $paused = MaintenanceState::load($this->pdo, Time::toDb($now))->pausedObjects();
        $due = array_values(array_filter($due, static fn (array $c): bool => !isset($paused[(string) $c['object_id']])));

        // Termín dalšího běhu se posune hned, aby se kontrola při pádu workeru neopakovala každou minutu.
        foreach ($due as $check) {
            if ($check['type'] !== 'heartbeat') {
                $this->service->checks->setNextRun((string) $check['id'], Time::toDb($now->modify('+' . (int) $check['interval_s'] . ' seconds')));
            }
        }

        $byId = [];
        $groups = ['curl' => [], 'ping' => [], 'rdap' => []];
        foreach ($due as $check) {
            $byId[(string) $check['id']] = $check;
            match (true) {
                $check['type'] === 'heartbeat' => null,
                in_array($check['type'], CheckTypes::CURL_TYPES, true) => $groups['curl'][] = $check,
                $check['type'] === 'ping' => $groups['ping'][] = $check,
                $check['type'] === 'domain_expiry' => $groups['rdap'][] = $check,
                default => null,
            };
        }

        $count = 0;
        $onResult = function (string $checkId, CheckResult $result) use (&$count, $byId): void {
            $this->service->record($byId[$checkId], $result);
            $count++;
        };

        foreach ($due as $check) {
            if ($check['type'] === 'heartbeat') {
                $result = $this->service->evaluateHeartbeat($check, $now);
                if ($result !== null) {
                    $onResult((string) $check['id'], $result);
                }
            }
        }
        $this->curl->run($groups['curl'], $onResult, $deadline);
        $this->ping->run($groups['ping'], $onResult, $deadline);
        $this->rdap->run($groups['rdap'], $onResult, $deadline);

        $notifications = $this->notifier?->run($deadline);
        $maintenance = $this->maintenance();
        $this->settings->set('worker_last_run', Time::toDb(Time::now()));

        return ['checks' => count($due), 'results' => $count, 'maintenance' => $maintenance, 'notifications' => $notifications];
    }

    /**
     * Jednou za hodinu: přepočet agregací od hodiny před poslední údržbou (pozdní výsledky,
     * výpadek workeru) a promazání podle retence.
     */
    public function maintenance(bool $force = false): bool
    {
        $now = Time::now();
        $hour = $now->format('Y-m-d\TH:00:00\Z');
        $previous = $this->settings->get('maintenance_hour');
        if (!$force && $previous === $hour) {
            return false;
        }
        $from = $previous === null ? '0000-01-01T00:00:00Z'
            : min(Time::fromDb($previous)->modify('-1 hour')->format('Y-m-d\TH:00:00\Z'), $now->modify('-1 hour')->format('Y-m-d\TH:00:00\Z'));
        $this->service->results->aggregate($from, '9999-12-31T00:00:00Z');
        $this->service->results->purgeResults(Time::toDb($now->modify('-' . self::RESULTS_RETENTION_DAYS . ' days')));
        $this->service->results->purgeHourly($now->modify('-' . self::HOURLY_RETENTION_DAYS . ' days')->format('Y-m-d\TH:00:00\Z'));
        (new DeliveryRepository($this->pdo))->purgeOlderThan(Time::toDb($now->modify('-' . self::HOURLY_RETENTION_DAYS . ' days')));
        $this->settings->set('maintenance_hour', $hour);

        return true;
    }
}
