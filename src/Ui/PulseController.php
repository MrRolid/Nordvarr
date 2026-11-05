<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Repository\EventRepository;
use Nordvarr\Time;

/**
 * Co se stalo od daného času (pro zvukové upozornění v prohlížeči): nově otevřené
 * a vyřešené alerty a nové události, každá položka s úrovní info, warning nebo critical.
 */
final class PulseController
{
    public const MAX_ITEMS = 20;
    private const LEVELS = ['info' => 0, 'warning' => 1, 'critical' => 2];

    public function __construct(private readonly Ctx $ctx)
    {
    }

    public function register(Router $router): void
    {
        $router->get('/notify/pulse', fn (): Response => $this->pulse());
    }

    private function pulse(): Response
    {
        $now = Time::toDb(Time::now());
        $since = EventRepository::normalizeTime($this->ctx->request->queryString('since'));
        $items = $since === null ? [] : $this->items($since, $now);
        $level = null;
        foreach ($items as $item) {
            if ($level === null || self::LEVELS[$item['level']] > self::LEVELS[$level]) {
                $level = $item['level'];
            }
        }

        return new Response(
            (string) json_encode(['now' => $now, 'level' => $level, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            200,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    /**
     * @return list<array{level: string, kind: string, text: string, at: string}>
     */
    private function items(string $since, string $now): array
    {
        $pdo = $this->ctx->pdo;
        $items = [];
        // Potlačené alerty (příčina jinde, údržba) zvuk nespouští, stejně jako nechodí notifikace.
        $opened = $pdo->prepare(
            'SELECT a.level, a.opened_at, a.check_type, a.reason_code, o.name FROM alerts a JOIN objects o ON o.id = a.object_id
             WHERE a.opened_at > ? AND a.opened_at <= ? AND a.suppressed_by_alert_id IS NULL AND a.maintenance_suppressed = 0
             ORDER BY a.opened_at DESC LIMIT ' . self::MAX_ITEMS
        );
        $opened->execute([$since, $now]);
        foreach ($opened->fetchAll() as $a) {
            $items[] = [
                'level' => $a['level'] === 'unhealthy' ? 'critical' : 'warning',
                'kind' => 'alert',
                'text' => $a['name'] . ': ' . $a['check_type'] . ($a['reason_code'] !== null ? ' ' . $a['reason_code'] : ''),
                'at' => (string) $a['opened_at'],
            ];
        }
        $resolved = $pdo->prepare(
            'SELECT a.resolved_at, a.check_type, o.name FROM alerts a JOIN objects o ON o.id = a.object_id
             WHERE a.resolved_at > ? AND a.resolved_at <= ? AND a.suppressed_by_alert_id IS NULL ORDER BY a.resolved_at DESC LIMIT ' . self::MAX_ITEMS
        );
        $resolved->execute([$since, $now]);
        foreach ($resolved->fetchAll() as $a) {
            $items[] = ['level' => 'info', 'kind' => 'resolved', 'text' => $a['name'] . ': ' . $a['check_type'], 'at' => (string) $a['resolved_at']];
        }
        $events = $pdo->prepare('SELECT severity, summary, created_at FROM events WHERE created_at > ? AND created_at <= ? ORDER BY created_at DESC LIMIT ' . self::MAX_ITEMS);
        $events->execute([$since, $now]);
        foreach ($events->fetchAll() as $e) {
            $items[] = [
                'level' => match ((string) $e['severity']) {
                    'critical' => 'critical',
                    'warning' => 'warning',
                    default => 'info',
                },
                'kind' => 'event',
                'text' => (string) $e['summary'],
                'at' => (string) $e['created_at'],
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_slice($items, 0, self::MAX_ITEMS);
    }
}
