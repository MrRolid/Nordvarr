<?php

declare(strict_types=1);

namespace Nordvarr\Inventory;

use DateInterval;
use Nordvarr\Checks\CheckTypes;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Time;

/**
 * Ukázková fiktivní infrastruktura (40 objektů) pro vyzkoušení a testy.
 * Jména jen example.com / example.net, adresy jen z dokumentačních rozsahů.
 * Nahrává se přes upsert se zdrojem "demo", takže opakované spuštění nic nezduplikuje.
 */
final class DemoData
{
    public const SOURCE = 'demo';

    public function __construct(private readonly Inventory $inventory)
    {
    }

    /**
     * @return array{objects: int, events: int}
     */
    public function load(): array
    {
        $upsert = new Upsert($this->inventory);
        $objects = self::objects();

        return $this->inventory->transaction(function () use ($upsert, $objects): array {
            // 1. průchod: objekty bez vztahů, 2. průchod: vztahy (cíle už existují).
            foreach ($objects as $object) {
                $upsert->run(array_diff_key($object, ['relationships' => true]), self::SOURCE);
            }
            foreach ($objects as $object) {
                $upsert->run($object, self::SOURCE);
            }

            // Ukázka rozporu: ručně zadaná hodnota se liší od zdroje demo.
            $db1 = $this->inventory->externalIds->objectId(self::SOURCE, 'vm:db1');
            if ($db1 !== null) {
                $this->inventory->attributes->set($db1, 'ram', '32 GB', false, Vocabulary::MANUAL_SOURCE);
            }

            // Ukázková kontrola bez síťových volání: heartbeat noční zálohy.
            $backup = $this->inventory->externalIds->objectId(self::SOURCE, 'svc:backup-job');
            $checks = new CheckRepository($this->inventory->pdo);
            if ($backup !== null && $checks->forObject($backup) === []) {
                $valid = CheckTypes::validate('heartbeat', ['name' => 'noční záloha', 'interval_s' => '86400', 'grace_s' => '3600']);
                $checks->create($backup, 'heartbeat', $valid['common'], $valid['config']);
            }

            $ingest = new EventIngest($this->inventory);
            $events = self::events();
            foreach ($events as $event) {
                $ingest->run($event, self::SOURCE);
            }

            return ['objects' => count($objects), 'events' => count($events)];
        });
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $tags
     * @param list<array{0: string, 1: string}> $rels [druh, cílové external_id]
     * @return array<string, mixed>
     */
    private static function obj(string $externalId, string $type, string $name, array $attributes = [], array $tags = [], array $rels = [], ?string $docs = null): array
    {
        $object = [
            'external_id' => $externalId,
            'type' => $type,
            'name' => $name,
            'attributes' => $attributes,
            'tags' => $tags,
            'relationships' => array_map(static fn (array $r): array => ['kind' => $r[0], 'to_external_id' => $r[1]], $rels),
        ];
        if ($docs !== null) {
            $object['docs_markdown'] = $docs;
        }

        return $object;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function objects(): array
    {
        $vm = static fn (string $name, string $ip, array $tags, array $contains, string $ram = '8 GB'): array => self::obj(
            'vm:' . $name,
            'vm',
            $name . '.example.com',
            ['ip' => $ip, 'os' => 'Debian 13', 'ram' => $ram, 'vcpu' => 4],
            $tags,
            array_map(static fn (string $x): array => ['contains', $x], $contains),
        );

        return [
            // Domény
            self::obj('domain:example.com', 'domain', 'example.com', ['registrar' => 'Fiktivní registrátor s.r.o.', 'expires' => '2027-03-14'], ['dns']),
            self::obj('domain:example.net', 'domain', 'example.net', ['registrar' => 'Fiktivní registrátor s.r.o.', 'expires' => '2026-12-01'], ['dns']),

            // Síť
            self::obj('net:fw1', 'network_device', 'fw1.example.net', ['ip' => '198.51.100.1', 'model' => 'Firewall FX-200'], ['sit', 'kriticke']),
            self::obj('net:sw1', 'network_device', 'sw1.example.net', ['ip' => '192.0.2.2', 'ports' => 48], ['sit'], [['depends_on', 'net:fw1']]),
            self::obj('net:sw2', 'network_device', 'sw2.example.net', ['ip' => '192.0.2.3', 'ports' => 24], ['sit'], [['depends_on', 'net:fw1']]),
            self::obj('net:nas1', 'network_device', 'nas1.example.net', ['ip' => '192.0.2.40', 'capacity' => '24 TB'], ['zalohy'], [['depends_on', 'net:sw2']]),
            self::obj('other:ups1', 'other', 'UPS serverovna', ['model' => 'UPS 3000 VA', 'runtime_min' => 25], ['napajeni']),

            // Fyzické servery (hypervizory) a jejich VM
            self::obj('srv:hv1', 'server', 'hv1.example.net', ['ip' => '192.0.2.10', 'cpu' => '2x 16 jader', 'ram' => '256 GB'], ['hypervizor', 'kriticke'],
                [['depends_on', 'net:sw1'], ['depends_on', 'other:ups1'], ['contains', 'vm:web1'], ['contains', 'vm:web2'], ['contains', 'vm:app1'],
                 ['contains', 'vm:db1'], ['contains', 'vm:mail1']],
                "# hv1\n\nHypervizor v racku A.\n\n## Správa\n\n- konzole: https://hv1.example.net:8006\n- údržba vždy po migraci VM na **hv2**\n"),
            self::obj('srv:hv2', 'server', 'hv2.example.net', ['ip' => '192.0.2.11', 'cpu' => '2x 16 jader', 'ram' => '256 GB'], ['hypervizor', 'kriticke'],
                [['depends_on', 'net:sw1'], ['depends_on', 'other:ups1'], ['contains', 'vm:app2'], ['contains', 'vm:db2'],
                 ['contains', 'vm:mon1'], ['contains', 'vm:ci1'], ['contains', 'vm:files1']]),
            self::obj('srv:backup1', 'server', 'backup1.example.net', ['ip' => '192.0.2.20', 'disk' => '12 TB'], ['zalohy'],
                [['depends_on', 'net:sw2'], ['depends_on', 'net:nas1']]),

            $vm('web1', '192.0.2.31', ['web', 'eshop'], ['svc:nginx-web1']),
            $vm('web2', '192.0.2.32', ['web', 'eshop'], ['svc:nginx-web2']),
            $vm('app1', '192.0.2.33', ['eshop'], ['svc:shop-api', 'svc:redis']),
            $vm('app2', '192.0.2.34', ['crm'], ['svc:crm-app', 'svc:wiki']),
            $vm('db1', '192.0.2.35', ['databaze', 'eshop'], ['db:shop', 'db:wiki'], '16 GB'),
            $vm('db2', '192.0.2.36', ['databaze', 'crm'], ['db:crm', 'db:mail'], '16 GB'),
            $vm('mail1', '192.0.2.37', ['posta'], ['svc:postfix']),
            $vm('mon1', '192.0.2.38', ['monitoring'], ['svc:monitoring']),
            $vm('ci1', '192.0.2.39', ['ci'], ['svc:ci-runner']),
            $vm('files1', '192.0.2.41', ['soubory'], ['svc:backup-job']),

            // Databáze
            self::obj('db:shop', 'database', 'shop (PostgreSQL)', ['engine' => 'PostgreSQL 17', 'port' => 5432, 'size' => '42 GB'], ['eshop', 'kriticke'], [],
                "## Obnova ze zálohy\n\n1. zastavit `shop-api`\n2. obnovit poslední dump z **backup1**\n3. spustit `shop-api` a zkontrolovat objednávky\n"),
            self::obj('db:wiki', 'database', 'wiki (MariaDB)', ['engine' => 'MariaDB 11', 'port' => 3306], ['interni']),
            self::obj('db:crm', 'database', 'crm (PostgreSQL)', ['engine' => 'PostgreSQL 17', 'port' => 5432], ['crm']),
            self::obj('db:mail', 'database', 'mail (MariaDB)', ['engine' => 'MariaDB 11', 'port' => 3306], ['posta']),

            // Služby
            self::obj('svc:nginx-web1', 'service', 'nginx na web1', ['port' => 443], ['web'], [['depends_on', 'svc:shop-api']]),
            self::obj('svc:nginx-web2', 'service', 'nginx na web2', ['port' => 443], ['web'], [['depends_on', 'svc:shop-api']]),
            self::obj('svc:shop-api', 'service', 'shop-api', ['port' => 8080, 'runtime' => 'PHP 8.4'], ['eshop', 'kriticke'],
                [['depends_on', 'db:shop'], ['depends_on', 'svc:redis']]),
            self::obj('svc:redis', 'service', 'redis cache', ['port' => 6379], ['eshop']),
            self::obj('svc:crm-app', 'service', 'crm-app', ['port' => 8081], ['crm'], [['depends_on', 'db:crm'], ['depends_on', 'svc:postfix']]),
            self::obj('svc:wiki', 'service', 'interní wiki', ['port' => 8082], ['interni'], [['depends_on', 'db:wiki']]),
            self::obj('svc:postfix', 'service', 'postfix', ['port' => 25], ['posta'], [['depends_on', 'db:mail'], ['depends_on', 'domain:example.net']]),
            self::obj('svc:monitoring', 'service', 'monitoring', ['port' => 9090], ['monitoring']),
            self::obj('svc:ci-runner', 'service', 'CI runner', ['concurrency' => 4], ['ci']),
            self::obj('svc:backup-job', 'service', 'noční záloha', ['schedule' => '02:30', 'target' => 'nas1.example.net'], ['zalohy'],
                [['depends_on', 'srv:backup1'], ['depends_on', 'db:shop'], ['depends_on', 'db:crm']]),

            // Weby
            self::obj('web:shop.example.com', 'website', 'shop.example.com', ['url' => 'https://shop.example.com/', 'document_root' => '/srv/www/shop'], ['eshop', 'kriticke'],
                [['depends_on', 'svc:nginx-web1'], ['depends_on', 'svc:nginx-web2'], ['depends_on', 'domain:example.com']],
                "# E-shop\n\nVeřejný e-shop. Provoz zajišťují **web1** a **web2** za balancerem.\n\n| Prostředí | Adresa |\n|---|---|\n| produkce | https://shop.example.com/ |\n| test | https://test-shop.example.com/ |\n"),
            self::obj('web:www.example.com', 'website', 'www.example.com', ['url' => 'https://www.example.com/'], ['web'],
                [['depends_on', 'svc:nginx-web1'], ['depends_on', 'domain:example.com']]),
            self::obj('web:api.example.com', 'website', 'api.example.com', ['url' => 'https://api.example.com/'], ['eshop'],
                [['depends_on', 'svc:shop-api'], ['depends_on', 'domain:example.com']]),
            self::obj('web:crm.example.com', 'website', 'crm.example.com', ['url' => 'https://crm.example.com/'], ['crm'],
                [['depends_on', 'svc:crm-app'], ['depends_on', 'domain:example.com']]),
            self::obj('web:wiki.example.net', 'website', 'wiki.example.net', ['url' => 'https://wiki.example.net/'], ['interni'],
                [['depends_on', 'svc:wiki'], ['depends_on', 'domain:example.net']]),
            self::obj('web:status.example.net', 'website', 'status.example.net', ['url' => 'https://status.example.net/'], ['monitoring'],
                [['depends_on', 'svc:monitoring'], ['depends_on', 'domain:example.net']]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function events(): array
    {
        $now = Time::now();
        $at = static fn (string $ago): string => Time::toDb($now->sub(new DateInterval($ago)));

        return [
            ['event_id' => 'demo-deploy-shop-api-41', 'type' => 'deploy', 'occurred_at' => $at('P3D'), 'summary' => 'shop-api release 41',
                'objects' => [['external_id' => 'svc:shop-api']]],
            ['event_id' => 'demo-deploy-shop-api-42', 'type' => 'deploy', 'occurred_at' => $at('PT5H'), 'summary' => 'shop-api release 42',
                'details' => "Změny:\n- nový výpočet dopravy\n- aktualizace knihoven", 'objects' => [['external_id' => 'svc:shop-api']]],
            ['event_id' => 'demo-config-db1-pg', 'type' => 'config', 'occurred_at' => $at('PT20H'), 'summary' => 'shared_buffers zvýšeno na 4 GB',
                'objects' => [['external_id' => 'db:shop'], ['external_id' => 'vm:db1']]],
            ['event_id' => 'demo-maint-hv2', 'type' => 'maintenance', 'occurred_at' => $at('P2D'), 'summary' => 'aktualizace firmware hv2',
                'objects' => [['external_id' => 'srv:hv2']]],
            ['event_id' => 'demo-restart-postfix', 'type' => 'restart', 'occurred_at' => $at('PT2H'), 'summary' => 'restart postfixu po změně certifikátu',
                'objects' => [['external_id' => 'svc:postfix']]],
            ['event_id' => 'demo-incident-redis', 'type' => 'incident', 'occurred_at' => $at('P1DT3H'), 'summary' => 'redis došla paměť, e-shop zpomalil',
                'objects' => [['external_id' => 'svc:redis'], ['external_id' => 'svc:shop-api']]],
            ['event_id' => 'demo-note-fw1', 'type' => 'note', 'occurred_at' => $at('P5D'), 'summary' => 'nová pravidla pro VPN',
                'objects' => [['external_id' => 'net:fw1']]],
            ['event_id' => 'demo-deploy-crm', 'type' => 'deploy', 'occurred_at' => $at('PT8H'), 'summary' => 'crm-app 2.3.0',
                'objects' => [['external_id' => 'svc:crm-app']]],
        ];
    }
}
