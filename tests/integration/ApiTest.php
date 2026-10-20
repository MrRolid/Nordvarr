<?php

declare(strict_types=1);

namespace Nordvarr\Tests\Integration;

use Nordvarr\Api\ApiTokens;
use Nordvarr\Repository\ApiTokenRepository;
use Nordvarr\Tests\InstalledApp;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    use InstalledApp;

    private string $token;

    protected function setUp(): void
    {
        $this->install();
        $tokens = new ApiTokens(new ApiTokenRepository($this->pdo()), $this->config()->appSecret());
        $this->token = $tokens->issue('Ansible', 'ansible', ['inventory:write', 'events:write'], null)['token'];
    }

    protected function tearDown(): void
    {
        $this->uninstall();
    }

    /**
     * @return array<string, mixed>
     */
    private function shop(): array
    {
        return ['external_id' => 'web:shop.example.com', 'type' => 'website', 'name' => 'shop.example.com',
            'attributes' => ['document_root' => '/srv/www/shop'], 'tags' => ['eshop'],
            'relationships' => [['kind' => 'depends_on', 'to_external_id' => 'vm:app1']],
            'docs_markdown' => 'Volitelný text dokumentace'];
    }

    public function testUpsertCreatesThenIsIdempotent(): void
    {
        $vm = $this->api('POST', '/api/v1/objects/upsert', ['external_id' => 'vm:app1', 'type' => 'vm', 'name' => 'app1'], $this->token);
        self::assertSame(201, $vm->status, $vm->body);
        self::assertSame('application/json; charset=utf-8', $vm->headers['Content-Type']);

        $first = $this->api('POST', '/api/v1/objects/upsert', $this->shop(), $this->token);
        self::assertSame(201, $first->status, $first->body);
        $body = self::json($first);
        self::assertTrue($body['created']);

        $second = $this->api('POST', '/api/v1/objects/upsert', $this->shop(), $this->token);
        self::assertSame(200, $second->status);
        self::assertSame($body['id'], self::json($second)['id']);
        self::assertFalse(self::json($second)['changed']);
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM objects')->fetchColumn());
    }

    public function testMissingReferencesReturn422WithList(): void
    {
        $response = $this->api('POST', '/api/v1/objects/upsert', $this->shop(), $this->token);
        self::assertSame(422, $response->status);
        self::assertSame(['vm:app1'], self::json($response)['missing_external_ids']);
        self::assertSame('missing_objects', self::json($response)['error']);

        $invalid = $this->api('POST', '/api/v1/objects/upsert', ['external_id' => 'x', 'type' => 'toaster', 'name' => 'x'], $this->token);
        self::assertSame(422, $invalid->status);
        self::assertSame('invalid_type', self::json($invalid)['fields']['type']);
    }

    public function testEvents(): void
    {
        $this->api('POST', '/api/v1/objects/upsert', ['external_id' => 'vm:app1', 'type' => 'vm', 'name' => 'app1'], $this->token);
        $event = ['event_id' => 'deploy-2026-10-20-1', 'type' => 'deploy', 'occurred_at' => '2026-10-20T13:58:00Z',
            'summary' => 'release 42', 'objects' => [['external_id' => 'vm:app1']]];
        self::assertSame(201, $this->api('POST', '/api/v1/events', $event, $this->token)->status);
        self::assertSame(200, $this->api('POST', '/api/v1/events', $event, $this->token)->status);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM events')->fetchColumn());

        $event['event_id'] = 'x';
        $event['objects'] = [['external_id' => 'vm:neni']];
        $missing = $this->api('POST', '/api/v1/events', $event, $this->token);
        self::assertSame(422, $missing->status);
        self::assertSame(['vm:neni'], self::json($missing)['missing_external_ids']);
    }

    public function testAuthentication(): void
    {
        $vm = ['external_id' => 'vm:1', 'type' => 'vm', 'name' => 'vm1'];
        self::assertSame(401, $this->api('POST', '/api/v1/objects/upsert', $vm)->status);
        self::assertSame(401, $this->api('POST', '/api/v1/objects/upsert', $vm, 'nv_000000000000_' . str_repeat('0', 48))->status);
        // Session cookie API nepřijímá.
        self::assertSame(401, $this->api('POST', '/api/v1/objects/upsert', $vm, null, ['cookie' => 'NORDVARRSID=abc'])->status);
        self::assertSame('Bearer', $this->api('POST', '/api/v1/events', $vm)->headers['WWW-Authenticate']);

        $tokens = new ApiTokens(new ApiTokenRepository($this->pdo()), $this->config()->appSecret());
        $eventsOnly = $tokens->issue('CI', 'ci', ['events:write'], null)['token'];
        $forbidden = $this->api('POST', '/api/v1/objects/upsert', $vm, $eventsOnly);
        self::assertSame(403, $forbidden->status);
        self::assertSame('inventory:write', self::json($forbidden)['required']);
    }

    public function testRequestFormat(): void
    {
        self::assertSame(415, $this->api('POST', '/api/v1/objects/upsert', null, $this->token)->status);
        self::assertSame(400, $this->api('POST', '/api/v1/objects/upsert', '{nejson', $this->token)->status);
        self::assertSame(400, $this->api('POST', '/api/v1/objects/upsert', '[1,2]', $this->token)->status);
        self::assertSame(413, $this->api('POST', '/api/v1/objects/upsert', str_repeat(' ', 1048577), $this->token)->status);
        self::assertSame(404, $this->api('GET', '/api/v1/nic', null, $this->token)->status);
        self::assertSame(405, $this->api('GET', '/api/v1/events', null, $this->token)->status);
    }

    public function testRateLimit(): void
    {
        $vm = ['external_id' => 'vm:1', 'type' => 'vm', 'name' => 'vm1'];
        // Simulace 120 požadavků v aktuální i následující minutě (kdyby test přešel přes hranici minuty).
        $tokenId = $this->pdo()->query('SELECT id FROM api_tokens')->fetchColumn();
        $insert = $this->pdo()->prepare('INSERT INTO api_rate_limits (token_id, window_start, count) VALUES (?, ?, 120)');
        $insert->execute([$tokenId, gmdate('Y-m-d\TH:i')]);
        $insert->execute([$tokenId, gmdate('Y-m-d\TH:i', time() + 60)]);
        $response = $this->api('POST', '/api/v1/objects/upsert', $vm, $this->token);
        self::assertSame(429, $response->status);
        self::assertArrayHasKey('Retry-After', $response->headers);
    }
}
