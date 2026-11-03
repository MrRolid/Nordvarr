<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Agent\AgentService;
use Nordvarr\Api\ApiTokens;
use Nordvarr\Checks\ObjectStatus;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Repository\ApiTokenRepository;
use Nordvarr\Repository\CheckResultRepository;
use Nordvarr\Repository\CheckRepository;
use Nordvarr\Tests\AgentFixtures;
use Nordvarr\Tests\InstalledApp;
use Nordvarr\Time;
use PHPUnit\Framework\TestCase;

/**
 * Kontejnery Dockeru z hlášení agenta: objekty typu kontejner pod serverem, atributy,
 * archivace zmizelých, vlastní kontrola každého kontejneru a tabulka na kartě serveru.
 */
final class AgentContainersTest extends TestCase
{
    use InstalledApp;

    private string $token;

    protected function setUp(): void
    {
        $this->install();
        $tokens = new ApiTokens(new ApiTokenRepository($this->pdo()), $this->config()->appSecret());
        $this->token = $tokens->issue('Agent', 'agent', ['agent:write'], null)['token'];
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    /**
     * @param list<array<string, string>>|null $containers
     * @return array<string, mixed>
     */
    private function send(?array $containers, array $extra = []): array
    {
        $report = AgentFixtures::report(['disks' => [AgentFixtures::report()['disks'][0]]] + $extra);
        if ($containers !== null) {
            $report['containers'] = $containers;
        }
        $response = $this->api('POST', '/api/v1/agent/report', $report, $this->token);
        self::assertSame(200, $response->status, $response->body);

        return self::json($response);
    }

    /**
     * @return array<string, string>
     */
    private static function ct(string $name, string $state, string $status, string $labels = '', string $image = 'example/app:1'): array
    {
        return ['Names' => $name, 'Image' => $image, 'State' => $state, 'Status' => $status, 'Labels' => $labels, 'ID' => substr(md5($name), 0, 12)];
    }

    /**
     * @return array<string, array<string, mixed>> jméno => objekt kontejneru pod serverem (i archivované)
     */
    private function containersOf(string $hostId): array
    {
        $inventory = new Inventory($this->pdo());
        $result = [];
        foreach ($inventory->relationships->forObject($hostId)['outgoing'] as $rel) {
            if ($rel['kind'] === 'contains') {
                $object = (array) $inventory->objects->find((string) $rel['other_id']);
                $result[(string) $object['name']] = $object;
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function agentAttributes(string $objectId): array
    {
        $values = [];
        foreach ((new Inventory($this->pdo()))->attributes->forObject($objectId) as $row) {
            if ($row['source'] === 'agent') {
                $values[(string) $row['key']] = (string) $row['value'];
            }
        }
        ksort($values);

        return $values;
    }

    public function testContainersBecomeObjectsUnderServer(): void
    {
        $body = $this->send([
            self::ct('shop-api', 'running', 'Up 2 hours (healthy)', 'com.docker.compose.project=shop,com.docker.compose.service=api'),
            self::ct('shop-migrate', 'exited', 'Exited (0) 3 days ago'),
        ]);
        $host = $body['object_id'];
        self::assertSame('healthy', $body['status'], 'Kontejner ukončený s kódem 0 není problém.');

        $containers = $this->containersOf($host);
        self::assertSame(['shop-api', 'shop-migrate'], array_keys($containers));
        self::assertSame(['container', 'active'], [$containers['shop-api']['type'], $containers['shop-api']['lifecycle']]);
        self::assertSame(
            ['compose_project' => 'shop', 'docker_state' => 'running', 'health' => 'healthy', 'image' => 'example/app:1'],
            $this->agentAttributes((string) $containers['shop-api']['id'])
        );
        self::assertSame((string) $containers['shop-api']['id'], (new Inventory($this->pdo()))->externalIds->objectId('agent', 'docker:host:gpu1.example.com/shop-api'));

        // Opakované hlášení nic nezduplikuje; zmizelý kontejner se archivuje, při návratu obnoví.
        $this->send([self::ct('shop-api', 'running', 'Up 3 hours (healthy)', 'com.docker.compose.project=shop')]);
        $containers = $this->containersOf($host);
        self::assertCount(2, $containers);
        self::assertSame('archived', $containers['shop-migrate']['lifecycle']);
        $this->send([self::ct('shop-api', 'running', 'Up 3 hours'), self::ct('shop-migrate', 'created', 'Created')]);
        $containers = $this->containersOf($host);
        self::assertSame('active', $containers['shop-migrate']['lifecycle']);
        self::assertSame(['docker_state' => 'running', 'image' => 'example/app:1'], $this->agentAttributes((string) $containers['shop-api']['id']), 'Healthcheck a projekt zmizely, atributy taky.');

        // Hlášení bez seznamu kontejnerů (starší agent) nic nemění.
        $this->send(null);
        self::assertSame('active', $this->containersOf($host)['shop-migrate']['lifecycle']);
    }

    /**
     * @return array<string, mixed> kontrola typu container u objektu kontejneru
     */
    private function containerCheck(string $containerId): array
    {
        $checks = (new CheckRepository($this->pdo()))->forObject($containerId);
        self::assertSame(['container'], array_column($checks, 'type'), 'Kontejner má právě jednu kontrolu od agenta.');

        return $checks[0];
    }

    public function testEachContainerHasOwnCheck(): void
    {
        $containers = [
            self::ct('queue-worker', 'restarting', 'Restarting (1) 5 seconds ago'),
            self::ct('nightly-backup', 'exited', 'Exited (137) 10 minutes ago'),
            self::ct('grafana', 'running', 'Up 5 minutes (unhealthy)'),
            self::ct('shop-redis', 'running', 'Up 2 hours'),
            self::ct('shop-migrate', 'exited', 'Exited (0) 3 days ago'),
            self::ct('paused-one', 'paused', 'Up 1 hour (Paused)'),
        ];
        $body = $this->send($containers);
        self::assertSame('healthy', $body['status'], 'Problémy kontejnerů nejsou problémem serveru.');
        // Práh selhání kontroly kontejneru je 2 hlášení po sobě.
        $this->send($containers);

        $expected = [
            'grafana' => ['unhealthy', 'container_unhealthy'],
            'nightly-backup' => ['unhealthy', 'container_down'],
            'paused-one' => ['degraded', 'container_paused'],
            'queue-worker' => ['unhealthy', 'container_down'],
            'shop-migrate' => ['healthy', null],
            'shop-redis' => ['healthy', null],
        ];
        $objects = $this->containersOf($body['object_id']);
        foreach ($expected as $name => [$status, $reason]) {
            $check = $this->containerCheck((string) $objects[$name]['id']);
            self::assertSame($status, ObjectStatus::checkStatus($check), $name);
            self::assertSame($reason, $check['reason_code'], $name);
        }
        $last = (new CheckResultRepository($this->pdo()))->recent((string) $this->containerCheck((string) $objects['nightly-backup']['id'])['id'], 1)[0];
        self::assertSame('skončil s kódem 137', $last['reason_detail']);

        // Smazaný kontejner: archivace a jednou výsledek container_missing.
        $this->send(array_slice($containers, 1));
        $check = $this->containerCheck((string) $objects['queue-worker']['id']);
        self::assertSame('container_missing', (new CheckResultRepository($this->pdo()))->recent((string) $check['id'], 1)[0]['reason_code']);
        $this->send(array_slice($containers, 1));
        self::assertCount(3, (new CheckResultRepository($this->pdo()))->recent((string) $check['id'], 10), 'Při dalším hlášení se nic nepřidá.');

        // Kontrolu kontejneru nejde založit ručně.
        $this->loginAdmin();
        $form = $this->ui('GET', '/objects/' . $objects['grafana']['id'] . '/checks/new')->body;
        self::assertStringNotContainsString('value="container"', $form);
        $response = $this->post('/objects/' . $objects['grafana']['id'] . '/checks', ['type' => 'container', 'grace_s' => '60']);
        self::assertSame(422, $response->status, 'Formulář se vrátí s chybou.');
        self::assertCount(1, (new CheckRepository($this->pdo()))->forObject((string) $objects['grafana']['id']));

        // Karta serveru: tabulka kontejnerů.
        $page = $this->ui('GET', '/objects/' . $body['object_id'])->body;
        self::assertStringContainsString('Kontejnery Dockeru (5)', $page);
        self::assertStringContainsString('data-test="host-containers"', $page);
        self::assertStringContainsString('<td class="lvl-warn">Exited (137) 10 minutes ago</td>', $page);
    }

    public function testWorkerMarksContainersUnknownWhenServerStopsReporting(): void
    {
        $body = $this->send([self::ct('shop-api', 'running', 'Up 2 hours')]);
        $container = (string) $this->containersOf($body['object_id'])['shop-api']['id'];
        $check = $this->containerCheck($container);
        $service = new AgentService($this->pdo());
        self::assertNull($service->evaluateContainerMissing($check, Time::now()->modify('+2 minutes')), 'V toleranci se nic nehlásí.');
        $result = $service->evaluateContainerMissing($check, Time::now()->modify('+10 minutes'));
        self::assertNotNull($result);
        self::assertSame(['unknown', 'agent_missing'], [$result->status, $result->reasonCode]);
    }

    public function testInvalidContainersAreDroppedAndErrorIsShown(): void
    {
        $body = $this->send([
            self::ct('ok-1', 'running', 'Up 1 minute'),
            ['Names' => '-oProxy', 'State' => 'running'],
            ['Names' => 'ok-1', 'State' => 'exited', 'Status' => 'Exited (0) now'],
            'nesmysl',
            self::ct('weird', 'zombie', 'Up'),
        ]);
        $containers = $this->containersOf($body['object_id']);
        self::assertSame(['ok-1', 'weird'], array_keys($containers));
        self::assertSame('unknown', $this->agentAttributes((string) $containers['weird']['id'])['docker_state']);

        $this->send(null, ['containers_error' => 'docker_unavailable']);
        $this->loginAdmin();
        self::assertStringContainsString('data-test="host-containers-error"', $this->ui('GET', '/objects/' . $body['object_id'])->body);
    }
}
