<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Inventory\EventIngest;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\Upsert;
use Nordvarr\Inventory\ValidationException;
use Nordvarr\Tests\TestDb;
use PHPUnit\Framework\TestCase;

final class EventIngestTest extends TestCase
{
    private Inventory $inventory;
    private EventIngest $ingest;
    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->inventory = new Inventory(TestDb::memory());
        $this->ingest = new EventIngest($this->inventory);
        $upsert = new Upsert($this->inventory);
        $this->ids['db'] = $upsert->run(['external_id' => 'db:shop', 'type' => 'database', 'name' => 'shop db'], 'ci')['id'];
        $this->ids['api'] = $upsert->run(['external_id' => 'svc:api', 'type' => 'service', 'name' => 'api',
            'relationships' => [['kind' => 'depends_on', 'to_external_id' => 'db:shop']]], 'ci')['id'];
        $this->ids['web'] = $upsert->run(['external_id' => 'web:shop.example.com', 'type' => 'website', 'name' => 'shop.example.com',
            'relationships' => [['kind' => 'depends_on', 'to_external_id' => 'svc:api']]], 'ci')['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function deploy(): array
    {
        return ['event_id' => 'deploy-2026-10-20-1', 'type' => 'deploy', 'occurred_at' => '2026-10-20T15:58:00+02:00',
            'summary' => 'release 42', 'objects' => [['external_id' => 'svc:api']]];
    }

    public function testIdempotentByEventId(): void
    {
        $first = $this->ingest->run($this->deploy(), 'ci');
        self::assertTrue($first['created']);
        $second = $this->ingest->run($this->deploy(), 'ci');
        self::assertSame(['id' => $first['id'], 'created' => false], $second);
        self::assertCount(1, $this->inventory->events->all());
        self::assertSame('2026-10-20T13:58:00Z', $this->inventory->events->all()[0]['occurred_at']);

        // Stejné event_id z jiného zdroje je jiná událost.
        $input = $this->deploy();
        $input['objects'] = [['id' => $this->ids['api']]];
        self::assertTrue($this->ingest->run($input, 'jiny-zdroj')['created']);
        self::assertCount(2, $this->inventory->events->all());
    }

    public function testEventShowsOnObjectAndItsNeighbours(): void
    {
        $this->ingest->run($this->deploy(), 'ci');
        foreach (['api', 'web', 'db'] as $key) {
            $timeline = $this->inventory->timeline($this->ids[$key]);
            self::assertCount(1, $timeline, "Časová osa objektu $key");
            self::assertSame('release 42', $timeline[0]['summary']);
            self::assertSame([$this->ids['api']], $timeline[0]['object_ids']);
        }
    }

    public function testMissingObjectsAndValidation(): void
    {
        $input = $this->deploy();
        $input['objects'][] = ['external_id' => 'svc:neni'];
        $input['objects'][] = ['id' => '00000000-0000-7000-8000-000000000000'];
        try {
            $this->ingest->run($input, 'ci');
            self::fail('Měl selhat.');
        } catch (ValidationException $e) {
            self::assertSame(['svc:neni', '00000000-0000-7000-8000-000000000000'], $e->missing);
        }
        self::assertSame([], $this->inventory->events->all());

        foreach ([
            ['occurred_at' => 'včera'],
            ['occurred_at' => '2026-10-20 13:58'],
            ['summary' => str_repeat('x', 301)],
            ['details' => str_repeat('x', 4001)],
            ['type' => 'party'],
            ['objects' => []],
        ] as $override) {
            try {
                $this->ingest->run($override + $this->deploy(), 'ci');
                self::fail('Měl selhat vstup ' . json_encode($override));
            } catch (ValidationException $e) {
                self::assertArrayHasKey((string) array_key_first($override), $e->errors);
            }
        }
    }

    public function testObjectReferenceByInternalId(): void
    {
        $input = $this->deploy();
        $input['objects'] = [['id' => $this->ids['db']]];
        self::assertTrue($this->ingest->run($input, 'ci')['created']);
        self::assertCount(1, $this->inventory->timeline($this->ids['db']));
    }
}
