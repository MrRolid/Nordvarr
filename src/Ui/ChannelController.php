<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Alerts\Notifier;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Inventory\Vocabulary;
use Nordvarr\Notify\ChannelTypes;
use Nordvarr\Repository\ChannelRepository;
use Nordvarr\Repository\DeliveryRepository;
use Nordvarr\Security\SecretBox;
use Nordvarr\Time;

/**
 * Notifikační kanály: přidání, úprava, smazání, testovací odeslání.
 */
final class ChannelController
{
    private ChannelRepository $channels;

    public function __construct(private readonly Ctx $ctx, private readonly string $appRoot)
    {
        $this->channels = new ChannelRepository($ctx->pdo);
    }

    public function register(Router $router): void
    {
        $router->get('/settings/channels/new', fn (): Response => $this->form(null, $this->type($this->ctx->request->queryString('type', 'email')), ['enabled' => true], []));
        $router->post('/settings/channels', fn (): Response => $this->save(null));
        $router->get('/settings/channels/{id}', fn (array $p): Response => $this->withChannel($p['id'], fn (array $c): Response => $this->form($c, (string) $c['type'], self::values($c), [])));
        $router->post('/settings/channels/{id}', fn (array $p): Response => $this->withChannel($p['id'], fn (array $c): Response => $this->save($c)));
        $router->post('/settings/channels/{id}/delete', fn (array $p): Response => $this->withChannel($p['id'], fn (array $c): Response => $this->delete($c)));
        $router->post('/settings/channels/{id}/test', fn (array $p): Response => $this->withChannel($p['id'], fn (array $c): Response => $this->test($c)));
    }

    /**
     * @param callable(array<string, mixed>): Response $action
     */
    private function withChannel(string $id, callable $action): Response
    {
        $channel = $this->channels->find($id);

        return $channel === null ? $this->ctx->notFound() : $action($channel);
    }

    private function type(string $type): string
    {
        return in_array($type, ChannelTypes::TYPES, true) ? $type : 'email';
    }

    /**
     * Hodnoty formuláře z uloženého kanálu (bez tajných údajů).
     *
     * @param array<string, mixed> $channel
     * @return array<string, mixed>
     */
    private static function values(array $channel): array
    {
        $c = $channel['config'];
        $values = ['name' => $channel['name'], 'enabled' => $channel['enabled'], 'tags' => implode(', ', $channel['tags'])];
        $cfg = match ($channel['type']) {
            'email' => [
                'from' => $c['from'] ?? '',
                'to' => implode(', ', (array) ($c['to'] ?? [])),
                'verify_tls' => ($c['verify_tls'] ?? true) ? '1' : '0',
            ] + self::serverValues('primary', $c['primary'] ?? null) + self::serverValues('fallback', $c['fallback'] ?? null),
            'telegram' => ['chat_id' => $c['chat_id'] ?? ''],
            'webhook' => ['url' => $c['url'] ?? ''],
            default => [],
        };
        $values['cfg'] = [$channel['type'] => $cfg];

        return $values;
    }

    /**
     * @param array<string, mixed>|null $server
     * @return array<string, string>
     */
    private static function serverValues(string $prefix, ?array $server): array
    {
        return [
            $prefix . '_host' => (string) ($server['host'] ?? ''),
            $prefix . '_port' => $server === null ? '' : (string) $server['port'],
            $prefix . '_security' => (string) ($server['security'] ?? 'starttls'),
            $prefix . '_username' => (string) ($server['username'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $channel
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function form(?array $channel, string $type, array $values, array $errors): Response
    {
        $deliveries = [];
        $attempts = [];
        if ($channel !== null) {
            $repo = new DeliveryRepository($this->ctx->pdo);
            $deliveries = $repo->recent((string) $channel['id'], 20);
            foreach ($deliveries as $d) {
                $attempts[(string) $d['id']] = $repo->attempts((string) $d['id']);
            }
        }

        return $this->ctx->page('channel_form', [
            'title' => $channel === null ? 'channel.new' : 'channel.edit',
            'channel' => $channel,
            'type' => $type,
            'values' => $values,
            'errors' => $errors,
            'deliveries' => $deliveries,
            'attempts' => $attempts,
            'secrets' => $channel === null ? [] : self::secretState($channel),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Které tajné položky jsou nastavené (pro text "ponechat stávající").
     *
     * @param array<string, mixed> $channel
     * @return array<string, bool>
     */
    private static function secretState(array $channel): array
    {
        $c = $channel['config'];

        return [
            'primary_password' => ($c['primary']['password'] ?? '') !== '',
            'fallback_password' => ($c['fallback']['password'] ?? '') !== '',
            'bot_token' => ($c['bot_token'] ?? '') !== '',
            'secret' => ($c['secret'] ?? '') !== '',
        ];
    }

    /**
     * @param array<string, mixed>|null $channel
     */
    private function save(?array $channel): Response
    {
        $r = $this->ctx->request;
        $type = $channel === null ? $this->type($r->input('type')) : (string) $channel['type'];
        $cfgInput = is_array($r->post['cfg'] ?? null) && is_array($r->post['cfg'][$type] ?? null) ? array_filter($r->post['cfg'][$type], 'is_string') : [];
        $name = trim($r->input('name'));
        $tagsInput = $r->input('tags');
        $tags = [];
        $errors = [];
        foreach (array_filter(array_map('trim', preg_split('/[\s,]+/', $tagsInput) ?: [])) as $raw) {
            $tag = Vocabulary::normalizeTag($raw);
            if ($tag === null) {
                $errors['tags'] = 'invalid_tag';
            } else {
                $tags[] = $tag;
            }
        }
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'invalid_channel_name';
        }
        $valid = ChannelTypes::validate($type, $cfgInput, $channel['config'] ?? null, new SecretBox($this->ctx->config->appSecret()));
        $errors += $valid['errors'];
        $enabled = $r->input('enabled') === '1';
        if ($errors !== []) {
            // Tajné položky se do formuláře nevracejí.
            $values = ['name' => $name, 'enabled' => $enabled, 'tags' => $tagsInput, 'cfg' => [$type => array_diff_key($cfgInput, array_flip(['primary_password', 'fallback_password', 'bot_token', 'secret']))]];

            return $this->form($channel, $type, $values, $errors);
        }
        if ($channel === null) {
            $id = $this->channels->create($name, $type, $valid['config'], $enabled, $tags);
            $this->ctx->audit('channel.create', ['id' => $id, 'name' => $name, 'type' => $type, 'tags' => $tags]);
            $this->ctx->flash('channel.created');
        } else {
            $id = (string) $channel['id'];
            $this->channels->update($id, $name, $valid['config'], $enabled, $tags);
            $this->ctx->audit('channel.update', ['id' => $id, 'name' => $name, 'type' => $type, 'enabled' => $enabled, 'tags' => $tags]);
            $this->ctx->flash('channel.saved');
        }

        return $this->ctx->redirect('/settings/channels/' . $id);
    }

    /**
     * @param array<string, mixed> $channel
     */
    private function delete(array $channel): Response
    {
        if ($this->ctx->request->input('confirm') !== '1') {
            $this->ctx->flash('channel.delete_confirm_missing');

            return $this->ctx->redirect('/settings/channels/' . $channel['id']);
        }
        $this->channels->delete((string) $channel['id']);
        $this->ctx->audit('channel.delete', ['id' => $channel['id'], 'name' => $channel['name'], 'type' => $channel['type']]);
        $this->ctx->flash('channel.deleted', ['name' => (string) $channel['name']]);

        return $this->ctx->redirect('/settings');
    }

    /**
     * Testovací odeslání hned (bez čekání na worker), výsledek se ukáže ve zprávě.
     *
     * @param array<string, mixed> $channel
     */
    private function test(array $channel): Response
    {
        $notifier = Notifier::fromConfig($this->ctx->pdo, $this->appRoot, $this->ctx->config);
        $deliveries = new DeliveryRepository($this->ctx->pdo);
        $id = $deliveries->enqueue((string) $channel['id'], null, $notifier->builder()->test((string) $channel['name']), Time::toDb(Time::now()));
        $ok = $notifier->queue()->attempt((array) $deliveries->find($id));
        $result = (array) $deliveries->find($id);
        $this->ctx->audit('channel.test', ['id' => $channel['id'], 'ok' => $ok]);
        $this->ctx->flash($ok ? 'channel.test_ok' : 'channel.test_failed', ['error' => (string) $result['last_error']]);

        return $this->ctx->redirect('/settings/channels/' . $channel['id']);
    }
}
