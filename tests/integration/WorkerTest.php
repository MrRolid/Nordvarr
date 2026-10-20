<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\CheckService;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Checks\CurlRunner;
use Nordvarr\Checks\HeartbeatEndpoint;
use Nordvarr\Checks\PingRunner;
use Nordvarr\Checks\RdapRunner;
use Nordvarr\Checks\Worker;
use Nordvarr\Http\Request;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\HttpCacheRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Tests\FixtureHttpClient;
use Nordvarr\Tests\FixtureServers;
use Nordvarr\Tests\TestDb;
use Nordvarr\Time;
use PDO;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    private static FixtureServers $servers;
    private static string $base;

    private PDO $pdo;
    private CheckService $service;
    private Inventory $inventory;
    private string $objectId;

    public static function setUpBeforeClass(): void
    {
        self::$servers = new FixtureServers();
        self::$base = self::$servers->http();
    }

    public static function tearDownAfterClass(): void
    {
        self::$servers->stop();
    }

    protected function setUp(): void
    {
        $this->pdo = TestDb::memory();
        $this->service = new CheckService($this->pdo);
        $this->inventory = new Inventory($this->pdo);
        $this->objectId = $this->inventory->objects->create(['type' => 'website', 'name' => 'www.example.com']);
    }

    private function worker(): Worker
    {
        return new Worker(
            $this->pdo,
            new CurlRunner('Nordvarr-test'),
            new PingRunner(dirname(__DIR__) . '/fixtures/fake-ping'),
            new RdapRunner(new FixtureHttpClient(), new HttpCacheRepository($this->pdo), 'https://bootstrap.example.net/dns.json'),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function addCheck(string $type, array $input, ?string $objectId = null): string
    {
        $valid = CheckTypes::validate($type, $input);
        self::assertSame([], $valid['errors']);

        return $this->service->checks->create($objectId ?? $this->objectId, $type, $valid['common'], $valid['config']);
    }

    /**
     * @return array<string, mixed>
     */
    private function check(string $id): array
    {
        $check = $this->service->checks->find($id);
        self::assertNotNull($check);

        return $check;
    }

    public function testRunsDueChecksAndSchedulesNextRun(): void
    {
        $ok = $this->addCheck('http', ['url' => self::$base . '/ok', 'failure_threshold' => '1']);
        $ping = $this->addCheck('ping', ['host' => 'ok.example.com']);
        $tcp = $this->addCheck('tcp', ['host' => '127.0.0.1', 'port' => (string) FixtureServers::freePort(), 'failure_threshold' => '1']);

        $stats = $this->worker()->run();
        self::assertSame(['checks' => 3, 'results' => 3], array_intersect_key($stats, ['checks' => 0, 'results' => 0]));
        self::assertSame('healthy', $this->check($ok)['status']);
        self::assertSame('healthy', $this->check($ping)['status']);
        self::assertSame(['unhealthy', 'tcp_refused'], [$this->check($tcp)['status'], $this->check($tcp)['reason_code']]);
        self::assertGreaterThan(Time::toDb(Time::now()), $this->check($ok)['next_run_at']);
        self::assertNotNull((new SettingsRepository($this->pdo))->get('worker_last_run'));

        $again = $this->worker()->run();
        self::assertSame(0, $again['checks'], 'Kontroly se spustí až po uplynutí intervalu.');
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM check_results')->fetchColumn());
    }

    public function testFailureThresholdAndRecovery(): void
    {
        $id = $this->addCheck('http', ['url' => self::$base . '/status/500']);
        $this->worker()->run();
        self::assertSame(['unknown', 1], [$this->check($id)['status'], (int) $this->check($id)['consecutive_failures']]);

        $this->service->checks->scheduleNow($id);
        $this->worker()->run();
        self::assertSame(['unhealthy', 'http_5xx'], [$this->check($id)['status'], $this->check($id)['reason_code']]);

        $valid = CheckTypes::validate('http', ['url' => self::$base . '/ok']);
        $this->service->checks->update($id, $valid['common'], $valid['config']);
        $this->worker()->run();
        self::assertSame(['healthy', null, 0], [$this->check($id)['status'], $this->check($id)['reason_code'], (int) $this->check($id)['consecutive_failures']]);
    }

    public function testPausedChecksDoNotRun(): void
    {
        $disabled = $this->addCheck('http', ['url' => self::$base . '/ok', 'enabled' => '0']);
        $archivedObject = $this->inventory->objects->create(['type' => 'server', 'name' => 'old.example.com', 'lifecycle' => 'archived']);
        $archived = $this->addCheck('http', ['url' => self::$base . '/ok'], $archivedObject);
        self::assertSame(0, $this->worker()->run()['checks']);
        self::assertNull($this->check($disabled)['last_run_at']);
        self::assertNull($this->check($archived)['last_run_at']);
    }

    public function testHeartbeatMissedAndReceived(): void
    {
        $id = $this->addCheck('heartbeat', ['interval_s' => '60', 'grace_s' => '0']);
        $this->worker()->run();
        self::assertSame('unknown', $this->check($id)['status'], 'Lhůta ještě neuplynula.');

        $this->pdo->prepare('UPDATE checks SET created_at = ? WHERE id = ?')->execute([Time::toDb(Time::now()->modify('-5 minutes')), $id]);
        $this->service->checks->scheduleNow($id);
        $this->worker()->run();
        self::assertSame(['unhealthy', 'heartbeat_missed'], [$this->check($id)['status'], $this->check($id)['reason_code']]);

        $token = (string) $this->check($id)['heartbeat_token'];
        $endpoint = new HeartbeatEndpoint($this->pdo);
        self::assertSame(200, $endpoint->handle(new Request('POST', '/hb/' . $token))->status);
        self::assertSame('healthy', $this->check($id)['status']);
        self::assertNotNull($this->check($id)['last_ping_at']);
        self::assertGreaterThan(Time::toDb(Time::now()->modify('+59 seconds')), $this->check($id)['next_run_at']);

        self::assertSame(404, $endpoint->handle(new Request('GET', '/hb/' . str_repeat('0', 32)))->status);
        self::assertSame(404, $endpoint->handle(new Request('GET', '/hb/nesmysl'))->status);
        self::assertSame(405, $endpoint->handle(new Request('DELETE', '/hb/' . $token))->status);
    }

    public function testLateResultIsStoredButDoesNotChangeState(): void
    {
        $id = $this->addCheck('http', ['url' => self::$base . '/ok', 'failure_threshold' => '1']);
        $check = $this->check($id);
        $this->service->record($check, CheckResult::healthy('2026-10-20T10:05:00Z', '2026-10-20T10:05:01Z'));
        $late = $this->service->record($check, new CheckResult('unhealthy', 'http_5xx', 'pozdě', 5, '2026-10-20T10:04:00Z', '2026-10-20T10:06:00Z'));
        self::assertFalse($late['applied']);
        self::assertSame('healthy', $this->check($id)['status']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM check_results')->fetchColumn());
    }

    public function testHourlyAggregationAndRetention(): void
    {
        $id = $this->addCheck('http', ['url' => self::$base . '/ok']);
        $check = $this->check($id);
        $hour = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $hour = $hour->setTime((int) $hour->format('H'), 0)->modify('-1 hour');
        foreach ([[5, 'healthy', 100], [10, 'healthy', 300], [20, 'unhealthy', null]] as [$minute, $status, $latency]) {
            $at = Time::toDb($hour->modify("+$minute minutes"));
            $this->service->results->insert($id, new CheckResult($status, $status === 'healthy' ? null : 'http_5xx', '', $latency, $at, $at));
        }
        $old = Time::toDb(Time::now()->modify('-15 days'));
        $this->service->results->insert($id, CheckResult::healthy($old, $old, 50));
        $this->pdo->prepare('INSERT INTO check_hourly (check_id, hour, count, failures) VALUES (?, ?, 1, 0)')
            ->execute([$id, Time::now()->modify('-401 days')->format('Y-m-d\TH:00:00\Z')]);

        self::assertTrue($this->worker()->maintenance());
        self::assertFalse($this->worker()->maintenance(), 'Údržba běží jednou za hodinu.');

        $rows = $this->service->results->hourly($id, $hour->format('Y-m-d\TH:00:00\Z'));
        self::assertCount(1, $rows);
        self::assertSame([3, 1, 200, 300], [(int) $rows[0]['count'], (int) $rows[0]['failures'], (int) $rows[0]['avg_latency_ms'], (int) $rows[0]['max_latency_ms']]);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM check_results')->fetchColumn(), 'Výsledky starší 14 dní se mažou.');
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM check_hourly WHERE hour < '" . Time::now()->modify('-400 days')->format('Y-m-d') . "'")->fetchColumn());

        $summary = $this->service->results->summary($id, Time::now()->modify('-24 hours')->format('Y-m-d\TH:00:00\Z'));
        self::assertSame(['count' => 3, 'failures' => 1, 'avg_latency_ms' => 200], $summary);
    }

    public function testDeadlineInThePastRunsNothingButStillRecordsRun(): void
    {
        $this->addCheck('http', ['url' => self::$base . '/ok']);
        $stats = $this->worker()->run(microtime(true) - 1);
        self::assertSame(0, $stats['results']);
        self::assertNotNull((new SettingsRepository($this->pdo))->get('worker_last_run'));
    }
}
