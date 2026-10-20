<?php

declare(strict_types=1);

namespace Nordvarr\Ui;

use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Inventory\Inventory;
use Nordvarr\Inventory\Vocabulary;

/**
 * Seznam inventáře s filtrem a hromadným přidáním tagu.
 */
final class InventoryController
{
    private Inventory $inventory;

    public function __construct(private readonly Ctx $ctx)
    {
        $this->inventory = new Inventory($ctx->pdo);
    }

    public function register(Router $router): void
    {
        $router->get('/inventory', fn (): Response => $this->index());
        $router->post('/inventory/tags', fn (): Response => $this->bulkTag());
    }

    private function index(): Response
    {
        $r = $this->ctx->request;
        $filter = [
            'type' => in_array($r->queryString('type'), Vocabulary::OBJECT_TYPES, true) ? $r->queryString('type') : '',
            'tag' => Vocabulary::normalizeTag($r->queryString('tag')) ?? '',
            'q' => mb_substr(trim($r->queryString('q')), 0, 100),
            'lifecycle' => in_array($r->queryString('lifecycle', 'active'), ['active', 'archived', 'all'], true)
                ? $r->queryString('lifecycle', 'active') : 'active',
        ];
        $limit = 500;
        $objects = $this->inventory->objects->search(
            ['lifecycle' => $filter['lifecycle'] === 'all' ? '' : $filter['lifecycle']] + $filter,
            $limit + 1
        );
        $truncated = count($objects) > $limit;
        $objects = array_slice($objects, 0, $limit);

        return $this->ctx->page('inventory', [
            'title' => 'nav.inventory',
            'filter' => $filter,
            'objects' => $objects,
            'tags' => $this->inventory->tags->forObjects(array_map(static fn (array $o): string => (string) $o['id'], $objects)),
            'allTags' => $this->inventory->tags->all(),
            'truncated' => $truncated,
        ]);
    }

    private function bulkTag(): Response
    {
        $r = $this->ctx->request;
        $tag = Vocabulary::normalizeTag($r->input('tag'));
        $ids = $r->post['ids'] ?? [];
        $ids = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
        $back = $r->input('back');
        $query = [];
        parse_str(is_string($back) ? $back : '', $query);
        $query = array_filter($query, static fn ($v, $k): bool => in_array($k, ['type', 'tag', 'q', 'lifecycle'], true) && is_string($v), ARRAY_FILTER_USE_BOTH);

        if ($tag === null) {
            $this->ctx->flash('inventory.bulk_err_tag');

            return $this->ctx->redirect('/inventory', $query);
        }
        if ($ids === []) {
            $this->ctx->flash('inventory.bulk_err_none');

            return $this->ctx->redirect('/inventory', $query);
        }
        $count = $this->inventory->transaction(function () use ($ids, $tag): int {
            $count = 0;
            foreach ($this->inventory->objects->findMany($ids) as $id => $object) {
                if ($this->inventory->tags->attach($id, $tag)) {
                    $this->inventory->objects->touch($id);
                    $count++;
                }
            }

            return $count;
        });
        $this->ctx->flash('inventory.bulk_done', ['tag' => $tag, 'count' => $count]);

        return $this->ctx->redirect('/inventory', $query);
    }
}
