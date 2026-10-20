<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use DateTimeImmutable;
use Nordvarr\Alerts\AlertEngine;
use Nordvarr\Alerts\DeliveryQueue;
use Nordvarr\Alerts\NotificationBuilder;
use Nordvarr\Checks\CheckResult;
use Nordvarr\Checks\CheckService;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Http\Client\HttpResponse;
use Nordvarr\I18n;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Notify\Channel;
use Nordvarr\Notify\ChannelTypes;
use Nordvarr\Notify\SmtpClient;
use Nordvarr\Repository\AlertRepository;
use Nordvarr\Repository\ChannelRepository;
use Nordvarr\Repository\MaintenanceRepository;
use Nordvarr\Security\SecretBox;
use Nordvarr\Tests\FixtureHttpClient;
use Nordvarr\Tests\TestDb;
use Nordvarr\Time;
use PDO;
use PHPUnit\Framework\TestCase;

final class AlertEngineTest extends TestCase
{
    private const SECRET = 'tajemstvi-aplikace-pro-test-0123456789abcdef';

    private PDO $pdo;
    private Inventory $inventory;
    private CheckService $checks;
    private FixtureHttpClient $http;
    private SecretBox $box;

    protected function setUp(): void
    {
        $this->pdo = TestDb::memory();
        $this->inventory = new Inventory($this->pdo);
        $this->checks = new CheckService($this->pdo);
        $this->http = new FixtureHttpClient();
        $this->box = new SecretBox(self::SECRET);
    }

    private function engine(int $reminderHours = 24): AlertEngine
    {
        $builder = new NotificationBuilder(new I18n(dirname(__DIR__, 2) . '/lang', 'cs'), 'Europe/Prague', 'https://nordvarr.example.com');

        return new AlertEngine($this->pdo, $builder, $reminderHours);
    }

    private function queue(): DeliveryQueue
    {
        return new DeliveryQueue($this->pdo, fn (array $c): Channel => ChannelTypes::create($c, $this->box, $this->http, new SmtpClient(1)));
    }

    /**
     * @param list<string> $tags
     */
    private function webhook(string $name, array $tags = [], int $status = 200): string
    {
        $url = 'https://hooks.example.net/' . $name;
        $this->http->set($url, new HttpResponse($status, '{}'));
        $valid = ChannelTypes::validate('webhook', ['url' => $url, 'secret' => 'tajemstvi-webhooku-123'], null, $this->box);
        self::assertSame([], $valid['errors']);

        return (new ChannelRepository($this->pdo))->create($name, 'webhook', $valid['config'], true, $tags);
    }

    private function object(string $name, array $tags = []): string
    {
        $id = $this->inventory->objects->create(['type' => 'service', 'name' => $name]);
        foreach ($tags as $tag) {
            $this->inventory->tags->attach($id, $tag);
        }

        return $id;
    }

    private function check(string $objectId, string $type = 'tcp', array $input = []): string
    {
        $valid = CheckTypes::validate($type, $input + ['host' => '192.0.2.1', 'port' => '80', 'failure_threshold' => '1']);

        return $this->checks->checks->create($objectId, $type, $valid['common'], $valid['config']);
    }

    private function set(string $checkId, string $status, ?string $reason = null, string $at = 'now'): void
    {
        $time = Time::toDb(new DateTimeImmutable($at));
        $check = (array) $this->checks->checks->find($checkId);
        $this->checks->record($check, new CheckResult($status, $reason, 'detail', null, $time, $time));
    }

    /**
     * @return list<array<string, mixed>> odeslané požadavky webhooků (dekódované tělo)
     */
    private function sent(): array
    {
        $this->queue()->process();
        $bodies = array_map(static fn (array $p): array => json_decode($p['body'], true) + ['url' => $p['url']], $this->http->posts);
        $this->http->posts = [];

        return $bodies;
    }

    /**
     * @return array<string, mixed>
     */
    private function alertFor(string $checkId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM alerts WHERE check_id = ? ORDER BY opened_at DESC, id DESC LIMIT 1');
        $stmt->execute([$checkId]);
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }

    public function testOpenNotifyAndRecover(): void
    {
        $this->webhook('vse');
        $web = $this->object('shop.example.com');
        $check = $this->check($web);

        $this->set($check, 'unhealthy', 'tcp_refused');
        self::assertSame(['opened' => 1, 'resolved' => 0, 'notifications' => 1], $this->engine()->run());
        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertSame('alert.opened', $sent[0]['event']);
        self::assertSame('[Nordvarr] NEFUNGUJE: shop.example.com (TCP: tcp_refused)', $sent[0]['subject']);
        self::assertStringContainsString('https://nordvarr.example.com/objects/' . $web, $sent[0]['text']);

        // Na kontrolu jen jeden otevřený alert, opakovaný běh nic nepošle.
        self::assertSame(['opened' => 0, 'resolved' => 0, 'notifications' => 0], $this->engine()->run());
        self::assertSame([], $this->sent());

        // Neznámý stav alert nezavře.
        $this->set($check, 'unknown', 'tool_missing');
        $this->engine()->run();
        self::assertSame('open', $this->alertFor($check)['status']);

        $this->set($check, 'healthy');
        self::assertSame(1, $this->engine()->run()['resolved']);
        $sent = $this->sent();
        self::assertSame('alert.resolved', $sent[0]['event']);
        self::assertStringStartsWith('[Nordvarr] OBNOVENO: shop.example.com', $sent[0]['subject']);
        self::assertSame(['resolved', 'recovered'], [$this->alertFor($check)['status'], $this->alertFor($check)['resolved_reason']]);

        $this->set($check, 'unhealthy', 'tcp_timeout');
        $this->engine()->run();
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM alerts')->fetchColumn());
    }

    public function testDegradedOnlyWithNotifyOnDegraded(): void
    {
        $this->webhook('vse');
        $object = $this->object('www.example.com');
        $plain = $this->check($object, 'tcp');
        $tls = $this->check($object, 'tls_expiry', ['host' => 'www.example.com']);
        $this->set($plain, 'degraded', 'latency_degraded');
        $this->set($tls, 'degraded', 'cert_expiring');
        self::assertSame(1, $this->engine()->run()['opened']);
        self::assertSame('degraded', $this->alertFor($tls)['level']);
        self::assertSame([], $this->alertFor($plain));
        self::assertStringStartsWith('[Nordvarr] OMEZENĚ: www.example.com', $this->sent()[0]['subject']);
    }

    public function testOneStepSuppressionByDependency(): void
    {
        $this->webhook('vse');
        $db = $this->object('db1.example.com');
        $hv = $this->object('hv1.example.net');
        $web = $this->object('shop.example.com');
        $this->inventory->relate($web, $db, 'depends_on', '', 'manual');
        $this->inventory->relate($hv, $web, 'contains', '', 'manual');
        $dbCheck = $this->check($db);
        $webCheck = $this->check($web);
        $hvCheck = $this->check($hv);

        // Web a databáze spadnou ve stejném běhu (web má dřívější kontrolu i alert).
        $this->set($webCheck, 'unhealthy', 'http_5xx');
        $this->set($dbCheck, 'unhealthy', 'tcp_refused');
        $this->set($hvCheck, 'unhealthy', 'check_timeout');
        $this->engine()->run();

        $webAlert = $this->alertFor($webCheck);
        // depends_on má přednost před contains.
        self::assertSame($this->alertFor($dbCheck)['id'], $webAlert['suppressed_by_alert_id']);
        self::assertSame($db, $webAlert['probable_cause_object_id']);
        $subjects = array_column($this->sent(), 'subject');
        sort($subjects);
        self::assertCount(2, $subjects);
        self::assertStringContainsString('db1.example.com', $subjects[0]);
        self::assertStringContainsString('hv1.example.net', $subjects[1]);

        // Potlačený alert nedostává připomínky.
        $this->pdo->exec("UPDATE alerts SET last_notified_at = '2020-01-01T00:00:00Z'");
        $this->engine()->run();
        $reminders = array_column($this->sent(), 'subject');
        self::assertCount(2, $reminders);
        self::assertStringNotContainsString('shop.example.com', implode(' ', $reminders));

        // Příčina vyřešená, web dál nefunguje: přijde jedna notifikace o webu.
        $this->set($dbCheck, 'healthy');
        $this->engine()->run();
        $events = array_column($this->sent(), 'event', 'subject');
        self::assertContains('alert.unsuppressed', $events);
        self::assertContains('alert.resolved', $events);
        self::assertNull($this->alertFor($webCheck)['suppressed_by_alert_id']);
        self::assertSame($db, $this->alertFor($webCheck)['probable_cause_object_id'], 'Pravděpodobná příčina zůstává viditelná.');
    }

    public function testSuppressionByContainerAndOnlyOneStep(): void
    {
        $this->webhook('vse');
        $hv = $this->object('hv1.example.net');
        $vm = $this->object('vm1.example.com');
        $svc = $this->object('svc.example.com');
        $this->inventory->relate($hv, $vm, 'contains', '', 'manual');
        $this->inventory->relate($vm, $svc, 'contains', '', 'manual');
        $hvCheck = $this->check($hv);
        $vmCheck = $this->check($vm);
        $svcCheck = $this->check($svc);

        $this->set($svcCheck, 'unhealthy', 'tcp_refused');
        $this->set($vmCheck, 'unhealthy', 'check_timeout');
        $this->set($hvCheck, 'unhealthy', 'check_timeout');
        $this->engine()->run();

        // vm je potlačená hypervizorem; svc nesmí potlačit potlačený alert vm (jen o jeden krok).
        self::assertSame($this->alertFor($hvCheck)['id'], $this->alertFor($vmCheck)['suppressed_by_alert_id']);
        self::assertNull($this->alertFor($svcCheck)['suppressed_by_alert_id']);
        $subjects = implode(' ', array_column($this->sent(), 'subject'));
        self::assertStringContainsString('hv1.example.net', $subjects);
        self::assertStringContainsString('svc.example.com', $subjects);
        self::assertStringNotContainsString('vm1.example.com', $subjects);
    }

    public function testEarlierAlertWinsAmongCandidates(): void
    {
        $this->webhook('vse');
        $a = $this->object('a.example.com');
        $b = $this->object('b.example.com');
        $web = $this->object('web.example.com');
        $this->inventory->relate($web, $a, 'depends_on', '', 'manual');
        $this->inventory->relate($web, $b, 'depends_on', '', 'manual');
        $aCheck = $this->check($a);
        $bCheck = $this->check($b);
        $this->set($bCheck, 'unhealthy', 'tcp_refused');
        $this->engine()->run(new DateTimeImmutable('-10 minutes'));
        $this->set($aCheck, 'unhealthy', 'tcp_refused');
        $this->engine()->run(new DateTimeImmutable('-5 minutes'));
        $webCheck = $this->check($web);
        $this->set($webCheck, 'unhealthy', 'http_5xx');
        $this->engine()->run();
        self::assertSame($this->alertFor($bCheck)['id'], $this->alertFor($webCheck)['suppressed_by_alert_id']);
    }

    public function testCyclesDoNotBreakSuppression(): void
    {
        $this->webhook('vse');
        $a = $this->object('a.example.com');
        $b = $this->object('b.example.com');
        $this->inventory->relate($a, $b, 'depends_on', '', 'manual');
        $this->inventory->relate($b, $a, 'depends_on', '', 'manual');
        $aCheck = $this->check($a);
        $bCheck = $this->check($b);
        $this->set($aCheck, 'unhealthy', 'tcp_refused');
        $this->set($bCheck, 'unhealthy', 'tcp_refused');
        $this->engine()->run();
        $suppressed = array_filter([$this->alertFor($aCheck)['suppressed_by_alert_id'], $this->alertFor($bCheck)['suppressed_by_alert_id']]);
        self::assertCount(1, $suppressed, 'Jeden z dvojice se hlásí, druhý je potlačený.');
        self::assertCount(1, $this->sent());
    }

    public function testRemindersEvery24HoursOrOff(): void
    {
        $this->webhook('vse');
        $check = $this->check($this->object('a.example.com'));
        $this->set($check, 'unhealthy', 'tcp_refused');
        $this->engine()->run();
        $this->sent();

        $this->pdo->exec("UPDATE alerts SET last_notified_at = '" . Time::toDb(Time::now()->modify('-23 hours')) . "'");
        $this->engine()->run();
        self::assertSame([], $this->sent());

        $this->pdo->exec("UPDATE alerts SET last_notified_at = '" . Time::toDb(Time::now()->modify('-25 hours')) . "'");
        $this->engine(0)->run();
        self::assertSame([], $this->sent(), 'Připomínky vypnuté (0).');
        $this->engine()->run();
        $sent = $this->sent();
        self::assertSame('alert.reminder', $sent[0]['event']);
        self::assertStringStartsWith('[Nordvarr] STÁLE NEFUNGUJE', $sent[0]['subject']);
    }

    public function testMaintenanceWindowSuppressesAndSendsOneNotificationAfter(): void
    {
        $this->webhook('vse');
        $object = $this->object('db1.example.com', ['databaze']);
        $check = $this->check($object);
        $windows = new MaintenanceRepository($this->pdo);
        $window = $windows->create(null, 'databaze', Time::toDb(Time::now()->modify('-5 minutes')), Time::toDb(Time::now()->modify('+1 hour')), false, 'upgrade', null);

        $this->set($check, 'unhealthy', 'tcp_refused');
        $this->engine()->run();
        self::assertSame([], $this->sent());
        self::assertSame(1, (int) $this->alertFor($check)['maintenance_suppressed']);

        // Ani připomínka během údržby.
        $this->engine()->run();
        self::assertSame([], $this->sent());

        $windows->end($window, Time::toDb(Time::now()->modify('-1 second')));
        $this->engine()->run();
        $sent = $this->sent();
        self::assertCount(1, $sent);
        self::assertSame('alert.after_maintenance', $sent[0]['event']);
        $this->engine()->run();
        self::assertSame([], $this->sent(), 'Po skončení okna nejvýš jedna notifikace.');
    }

    public function testRecoveryDuringMaintenanceIsSilent(): void
    {
        $this->webhook('vse');
        $object = $this->object('db1.example.com');
        $check = $this->check($object);
        $this->set($check, 'unhealthy', 'tcp_refused');
        $this->engine()->run();
        $this->sent();
        (new MaintenanceRepository($this->pdo))->create($object, null, Time::toDb(Time::now()->modify('-1 minute')), Time::toDb(Time::now()->modify('+1 hour')), true, '', null);
        $this->set($check, 'healthy');
        $this->engine()->run();
        // Kontroly jsou pozastavené: alert zůstává otevřený, nic se neposílá.
        self::assertSame('open', $this->alertFor($check)['status']);
        self::assertSame([], $this->sent());
    }

    public function testChannelRulesByTagAndDisabledCheck(): void
    {
        $this->webhook('vse');
        $this->webhook('jen-eshop', ['eshop']);
        $shop = $this->object('shop.example.com', ['eshop']);
        $wiki = $this->object('wiki.example.net');
        $shopCheck = $this->check($shop);
        $wikiCheck = $this->check($wiki);
        $this->set($shopCheck, 'unhealthy', 'tcp_refused');
        $this->set($wikiCheck, 'unhealthy', 'tcp_refused');
        $this->engine()->run();
        $byUrl = [];
        foreach ($this->sent() as $s) {
            $byUrl[$s['url']][] = $s['object']['name'];
        }
        self::assertCount(2, $byUrl['https://hooks.example.net/vse']);
        self::assertSame(['shop.example.com'], $byUrl['https://hooks.example.net/jen-eshop']);

        // Vypnutá kontrola: alert se tiše uzavře.
        $this->checks->checks->setEnabled($wikiCheck, false);
        $this->engine()->run();
        self::assertSame(['resolved', 'paused'], [$this->alertFor($wikiCheck)['status'], $this->alertFor($wikiCheck)['resolved_reason']]);
        self::assertSame([], $this->sent());
    }

    public function testDeliveryRetriesThenFails(): void
    {
        $this->webhook('rozbity', [], 500);
        $check = $this->check($this->object('a.example.com'));
        $this->set($check, 'unhealthy', 'tcp_refused');
        $this->engine()->run();
        $queue = $this->queue();
        $now = new DateTimeImmutable();
        $offsets = [0, 60, 360, 1260, 4860];
        foreach ($offsets as $i => $offset) {
            $stats = $queue->process(INF, $now->modify('+' . ($offset + 1) . ' seconds'));
            self::assertSame(['sent' => 0, 'failed' => 1], $stats, "Pokus $i");
            // Před uplynutím prodlevy se nic neopakuje.
            if ($i < 4) {
                self::assertSame(['sent' => 0, 'failed' => 0], $queue->process(INF, $now->modify('+' . ($offset + 2) . ' seconds')));
            }
        }
        $delivery = $this->pdo->query('SELECT * FROM deliveries')->fetch();
        self::assertSame(['failed', 5], [$delivery['status'], (int) $delivery['attempts']]);
        self::assertStringContainsString('HTTP 500', $delivery['last_error']);
        self::assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM delivery_attempts WHERE ok = 0')->fetchColumn());
        self::assertSame(['sent' => 0, 'failed' => 0], $queue->process(INF, $now->modify('+1 day')));
    }

    public function testDeletedCheckClosesAlertSilently(): void
    {
        $this->webhook('vse');
        $check = $this->check($this->object('a.example.com'));
        $this->set($check, 'unhealthy', 'tcp_refused');
        $this->engine()->run();
        $this->sent();
        $alertId = $this->alertFor($check)['id'];
        $this->checks->checks->delete($check);
        $this->engine()->run();
        $alert = (new AlertRepository($this->pdo))->find($alertId);
        self::assertSame(['resolved', 'check_deleted'], [$alert['status'], $alert['resolved_reason']]);
        self::assertSame([], $this->sent());
    }

    public function testChannelSecretsAreEncrypted(): void
    {
        $this->webhook('vse');
        $raw = (string) $this->pdo->query('SELECT config FROM channels')->fetchColumn();
        self::assertStringNotContainsString('tajemstvi-webhooku-123', $raw);
        self::assertStringContainsString('enc:v1:', $raw);
        $config = json_decode($raw, true);
        self::assertSame('tajemstvi-webhooku-123', $this->box->decrypt($config['secret']));
        $this->expectException(\RuntimeException::class);
        (new SecretBox('jine-tajemstvi'))->decrypt($config['secret']);
    }
}
