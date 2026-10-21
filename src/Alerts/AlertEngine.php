<?php

declare(strict_types=1);

namespace Nordvarr\Alerts;

use DateTimeImmutable;
use Nordvarr\Checks\Status;
use Nordvarr\Notify\Notification;
use Nordvarr\Repository\AlertRepository;
use Nordvarr\Repository\ChannelRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Repository\DeliveryRepository;
use Nordvarr\Repository\RelationshipRepository;
use Nordvarr\Repository\TagRepository;
use Nordvarr\Time;
use PDO;

/**
 * Alerty podle aktuálního stavu kontrol a rozhodnutí, komu a kdy poslat notifikaci.
 *
 * - Alert se otevře při stavu unhealthy, u degraded jen se zapnutým notify_on_degraded.
 *   Na kontrolu je otevřený nejvýš jeden alert, zavře se při návratu do healthy.
 * - Potlačení o jeden krok: když objekt X závisí na Y (depends_on) nebo je v Y obsažen
 *   (contains) a Y má otevřený nepotlačený alert, notifikace X se nepošle. Při více
 *   kandidátech rozhoduje depends_on před contains, dřív otevřený alert, menší id.
 * - Údržbové okno potlačí notifikace; po jeho konci odejde za každý stále otevřený
 *   alert nejvýš jedna notifikace.
 * - Připomínka otevřeného alertu po nastaveném počtu hodin (0 = vypnuto).
 */
final class AlertEngine
{
    private AlertRepository $alerts;
    private CheckRepository $checks;
    private ChannelRepository $channels;
    private DeliveryRepository $deliveries;

    /** @var array<string, list<string>>|null id objektu => tagy */
    private ?array $objectTags = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly NotificationBuilder $builder,
        private readonly int $reminderHours = 24,
    ) {
        $this->alerts = new AlertRepository($pdo);
        $this->checks = new CheckRepository($pdo);
        $this->channels = new ChannelRepository($pdo);
        $this->deliveries = new DeliveryRepository($pdo);
    }

    /**
     * @return array{opened: int, resolved: int, notifications: int}
     */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now ??= Time::now();
        $at = Time::toDb($now);
        $maintenance = MaintenanceState::load($this->pdo, $at);
        $stats = ['opened' => 0, 'resolved' => 0, 'notifications' => 0];

        $this->pdo->beginTransaction();
        try {
            $open = [];
            foreach ($this->alerts->openAlerts() as $alert) {
                if ($alert['check_id'] === null) {
                    // Kontrola byla smazána: alert se tiše uzavře.
                    $this->alerts->resolve((string) $alert['id'], $at, 'check_deleted');
                    continue;
                }
                $open[(string) $alert['check_id']] = $alert;
            }

            $new = [];
            $resolved = [];
            foreach ($this->checks->all() as $check) {
                $checkId = (string) $check['id'];
                $alert = $open[$checkId] ?? null;
                if (!$check['enabled'] || $check['object_lifecycle'] !== 'active') {
                    if ($alert !== null) {
                        $this->alerts->resolve((string) $alert['id'], $at, 'paused');
                        unset($open[$checkId]);
                    }
                    continue;
                }
                if ($maintenance->checksPaused((string) $check['object_id'])) {
                    continue;
                }
                $status = (string) $check['status'];
                $wanted = $status === Status::UNHEALTHY || ($status === Status::DEGRADED && $check['notify_on_degraded']);
                if ($wanted && $alert === null) {
                    $id = $this->alerts->open($check, $status, $at);
                    $new[] = $id;
                    $stats['opened']++;
                } elseif ($status === Status::HEALTHY && $alert !== null) {
                    $this->alerts->resolve((string) $alert['id'], $at, 'recovered');
                    $resolved[] = (string) $alert['id'];
                    unset($open[$checkId]);
                    $stats['resolved']++;
                } elseif ($alert !== null) {
                    $level = in_array($status, [Status::UNHEALTHY, Status::DEGRADED], true) ? $status : (string) $alert['level'];
                    $this->alerts->updateSnapshot((string) $alert['id'], $level, $check['reason_code'], (string) $check['reason_detail']);
                }
            }

            // Nové alerty: potlačení o jeden krok se rozhoduje v pořadí otevření. Když nový
            // alert potlačil jiný nový alert, který je nakonec sám potlačený, rozhodne se znovu
            // (potlačit smí jen nepotlačený alert).
            foreach ($new as $id) {
                $this->decideSuppression($id);
            }
            for ($round = 0; $round < count($new); $round++) {
                $changed = false;
                foreach ($new as $id) {
                    $by = $this->alerts->find($id)['suppressed_by_alert_id'] ?? null;
                    if ($by !== null && ($this->alerts->find((string) $by)['suppressed_by_alert_id'] ?? null) !== null) {
                        $this->alerts->setSuppression($id, null, null);
                        $this->decideSuppression($id);
                        $changed = true;
                    }
                }
                if (!$changed) {
                    break;
                }
            }
            foreach ($new as $id) {
                $alert = (array) $this->alerts->find($id);
                if ($maintenance->inMaintenance((string) $alert['object_id'])) {
                    $this->alerts->setMaintenanceSuppressed($id, true);
                } elseif ($alert['suppressed_by_alert_id'] === null) {
                    $stats['notifications'] += $this->notify($alert, 'opened', $at);
                }
            }

            foreach ($resolved as $id) {
                $alert = (array) $this->alerts->find($id);
                if ($alert['last_notified_at'] !== null && !$maintenance->inMaintenance((string) $alert['object_id'])) {
                    $stats['notifications'] += $this->notify($alert, 'resolved', $at);
                }
            }

            $stats['notifications'] += $this->followUps($new, $maintenance, $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * Otevřené alerty z dřívějška: konec potlačení, konec údržby, připomínky.
     *
     * @param list<string> $new alerty otevřené v tomto běhu
     */
    private function followUps(array $new, MaintenanceState $maintenance, DateTimeImmutable $now): int
    {
        $at = Time::toDb($now);
        $sent = 0;
        $openIds = [];
        $alerts = $this->alerts->openAlerts();
        foreach ($alerts as $alert) {
            $openIds[(string) $alert['id']] = true;
        }
        foreach ($alerts as $alert) {
            $id = (string) $alert['id'];
            if (in_array($id, $new, true)) {
                continue;
            }
            $inMaintenance = $maintenance->inMaintenance((string) $alert['object_id']);
            $suppressed = $alert['suppressed_by_alert_id'] !== null;

            if ($suppressed && !isset($openIds[(string) $alert['suppressed_by_alert_id']])) {
                // Příčina je vyřešená, ale tento alert trvá: od teď se hlásí sám.
                $this->alerts->clearSuppressedBy($id);
                if ($inMaintenance) {
                    $this->alerts->setMaintenanceSuppressed($id, true);
                } else {
                    $sent += $this->notify((array) $this->alerts->find($id), 'unsuppressed', $at);
                }
                continue;
            }
            if ($suppressed) {
                continue;
            }
            if ($inMaintenance) {
                if ((int) $alert['maintenance_suppressed'] === 0) {
                    $this->alerts->setMaintenanceSuppressed($id, true);
                }
                continue;
            }
            if ((int) $alert['maintenance_suppressed'] === 1) {
                $this->alerts->setMaintenanceSuppressed($id, false);
                $sent += $this->notify((array) $this->alerts->find($id), 'after_maintenance', $at);
                continue;
            }
            if ($this->reminderHours > 0 && $alert['last_notified_at'] !== null
                && $now->getTimestamp() - Time::fromDb((string) $alert['last_notified_at'])->getTimestamp() >= $this->reminderHours * 3600) {
                $sent += $this->notify($alert, 'reminder', $at);
            }
        }

        return $sent;
    }

    /**
     * Potlačení o jeden krok: hledá otevřený nepotlačený alert u objektu, na kterém
     * objekt alertu závisí nebo ve kterém je obsažen.
     */
    private function decideSuppression(string $alertId): void
    {
        $alert = (array) $this->alerts->find($alertId);
        $relationships = (new RelationshipRepository($this->pdo))->forObject((string) $alert['object_id']);
        $candidates = [];
        foreach ($relationships['outgoing'] as $rel) {
            if ($rel['kind'] === 'depends_on') {
                $candidates[(string) $rel['to_id']] = min($candidates[(string) $rel['to_id']] ?? 1, 0);
            }
        }
        foreach ($relationships['incoming'] as $rel) {
            if ($rel['kind'] === 'contains') {
                $candidates[(string) $rel['from_id']] = min($candidates[(string) $rel['from_id']] ?? 1, 1);
            }
        }
        $best = null;
        foreach ($this->alerts->openAlerts() as $other) {
            $objectId = (string) $other['object_id'];
            if ($other['id'] === $alertId || !isset($candidates[$objectId]) || $other['suppressed_by_alert_id'] !== null) {
                continue;
            }
            $key = [$candidates[$objectId], (string) $other['opened_at'], (string) $other['id']];
            if ($best === null || $key < $best[0]) {
                $best = [$key, $other];
            }
        }
        if ($best !== null) {
            $this->alerts->setSuppression($alertId, (string) $best[1]['id'], (string) $best[1]['object_id']);
        }
    }

    /**
     * Zařadí notifikaci do fronty pro všechny zapnuté kanály, které objekt pokrývají.
     *
     * @param array<string, mixed> $alert
     * @return int počet zařazených doručení
     */
    private function notify(array $alert, string $kind, string $at): int
    {
        $check = $alert['check_id'] !== null ? $this->checks->find((string) $alert['check_id']) : null;
        $notification = $this->builder->forAlert($kind, $alert, $check);
        $count = $this->enqueue($notification, (string) $alert['object_id'], (string) $alert['id'], $at);
        $this->alerts->setNotified((string) $alert['id'], $at);

        return $count;
    }

    private function enqueue(Notification $notification, string $objectId, ?string $alertId, string $at): int
    {
        $tags = $this->tagsOf($objectId);
        $count = 0;
        foreach ($this->channels->all() as $channel) {
            if (!$channel['enabled']) {
                continue;
            }
            if ($channel['tags'] !== [] && array_intersect($channel['tags'], $tags) === []) {
                continue;
            }
            $this->deliveries->enqueue((string) $channel['id'], $alertId, $notification, $at);
            $count++;
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function tagsOf(string $objectId): array
    {
        $this->objectTags ??= [];
        if (!isset($this->objectTags[$objectId])) {
            $this->objectTags[$objectId] = (new TagRepository($this->pdo))->forObject($objectId);
        }

        return $this->objectTags[$objectId];
    }
}
