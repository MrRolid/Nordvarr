<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Inventory\Inventory;
use Nordvarr\Time;
use Nordvarr\Tests\InstalledApp;
use PHPUnit\Framework\TestCase;

/**
 * Novinky pro zvukové upozornění v prohlížeči a výchozí pohled topologie z navigace.
 */
final class PulseTest extends TestCase
{
    use InstalledApp;

    protected function setUp(): void
    {
        $this->install();
        $this->loginAdmin();
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    /**
     * @return array<string, mixed>
     */
    private function pulse(?string $since): array
    {
        $response = $this->ui('GET', '/notify/pulse', [], $since === null ? [] : ['since' => $since]);
        self::assertSame(200, $response->status);
        self::assertStringStartsWith('application/json', $response->headers['Content-Type']);

        return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testPulseLevelsAndSuppression(): void
    {
        $first = $this->pulse(null);
        self::assertSame([[], null], [$first['items'], $first['level']], 'První dotaz jen zjistí čas serveru.');

        $inventory = new Inventory($this->pdo());
        $id = $inventory->objects->create(['type' => 'website', 'name' => 'shop.example.com']);
        // Časy relativně k hodinám serveru (budoucí položky se nehlásí).
        $at = static fn (int $seconds): string => Time::toDb(Time::now()->modify(sprintf('%+d seconds', $seconds - 120)));
        $alert = $this->pdo()->prepare("INSERT INTO alerts (id, object_id, status, level, check_type, reason_code, opened_at, resolved_at, suppressed_by_alert_id, maintenance_suppressed)
            VALUES (?, ?, ?, ?, 'http', ?, ?, ?, ?, ?)");
        $alert->execute(['a1', $id, 'open', 'degraded', 'http_latency', $at(10), null, null, 0]);
        $alert->execute(['a2', $id, 'open', 'unhealthy', 'http_status', $at(20), null, 'a1', 0]);
        $alert->execute(['a3', $id, 'open', 'unhealthy', 'tcp_refused', $at(30), null, null, 1]);
        $alert->execute(['a4', $id, 'resolved', 'unhealthy', 'http_status', $at(-3600), $at(40), null, 0]);
        $inventory->events->create(['source' => 'agent', 'source_event_id' => 'e1', 'type' => 'security', 'severity' => 'critical',
            'occurred_at' => '2026-11-01T00:00:00Z', 'summary' => 'CrowdSec zablokoval 25 adres', 'details' => ''], [$id], null, $at(50));
        $inventory->events->create(['source' => 'agent', 'source_event_id' => 'e0', 'type' => 'note', 'severity' => 'critical',
            'occurred_at' => '2026-11-05T10:00:00Z', 'summary' => 'stará', 'details' => ''], [$id], null, $at(-60));

        $data = $this->pulse($at(0));
        self::assertSame('critical', $data['level']);
        self::assertSame(
            [['critical', 'event', 'CrowdSec zablokoval 25 adres'], ['info', 'resolved', 'shop.example.com: http'], ['warning', 'alert', 'shop.example.com: http http_latency']],
            array_map(static fn (array $i): array => [$i['level'], $i['kind'], $i['text']], $data['items']),
            'Potlačené alerty (příčina jinde, údržba) a starší položky se nehlásí; událost podle času zápisu.'
        );
        self::assertSame([], $this->pulse($at(60))['items']);
        self::assertSame([], $this->pulse('nesmysl')['items']);
    }

    public function testSoundMenuAndTopologyDefault(): void
    {
        $page = $this->ui('GET', '/')->body;
        self::assertStringContainsString('data-test="sound-menu"', $page);
        self::assertStringContainsString('js/sounds.js', $page);
        self::assertStringContainsString('data-sound-level="critical" data-default="siren"', $page);

        // Topologie z navigace: všechny objekty ve 3D; s parametry beze změny.
        (new Inventory($this->pdo()))->objects->create(['type' => 'server', 'name' => 'hv1.example.net']);
        $topology = $this->ui('GET', '/topology')->body;
        self::assertStringContainsString('data-layout="3d" aria-pressed="true"', $topology);
        self::assertMatchesRegularExpression('/value="filter"[^>]*checked/', $topology);
        $neighborhood = $this->ui('GET', '/topology', [], ['mode' => 'neighborhood'])->body;
        self::assertStringContainsString('data-layout="auto" aria-pressed="true"', $neighborhood);
    }
}
