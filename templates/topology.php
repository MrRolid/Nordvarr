<?php
/** @var \Nordvarr\View\View $v */
/** @var array{mode: string, center: string, depth: int, type: string, tag: string, status: string, layout: string} $params */
/** @var array<string, mixed> $graph */
/** @var string $dataUrl */
/** @var string $nodeUrl */
/** @var string $objectUrl */
/** @var string $centerUrl */
/** @var array<string, string> $objectNames */
/** @var list<string> $allTags */
/** @var array<string, mixed>|null $center */
$nodes = $graph['nodes'];
usort($nodes, static fn (array $a, array $b): int => [\Nordvarr\Checks\Status::severity($b['data']['status']), $a['data']['name']]
    <=> [\Nordvarr\Checks\Status::severity($a['data']['status']), $b['data']['name']]);
$counts = [];
foreach ($graph['nodes'] as $n) {
    $counts[$n['data']['status']] = ($counts[$n['data']['status']] ?? 0) + 1;
}
$statusOrder = ['unhealthy', 'degraded', 'unknown', 'healthy', 'paused', 'none'];
?>
<script src="<?= $v->asset('vendor/cytoscape-3.34.2/cytoscape.min.js') ?>"></script>
<script src="<?= $v->asset('vendor/dagre-0.8.5/dagre.min.js') ?>"></script>
<script src="<?= $v->asset('vendor/cytoscape-dagre-2.5.0/cytoscape-dagre.js') ?>"></script>
<script type="module" src="<?= $v->asset('js/topology.js') ?>"></script>
<?php // Cytoscape vkládá vlastní <style>, pokud na stránce nenajde prvek s tímto id. Inline styly CSP blokuje, pravidlo je proto v app.css. ?>
<span id="__________cytoscape_stylesheet" hidden></span>

<div class="topo" data-test="topology">
    <div class="topo-head">
        <h1><?= $v->t('nav.topology') ?><?php if ($params['mode'] === 'neighborhood' && $center !== null): ?> <span class="topo-sub"><?= $v->t('topology.around', ['name' => (string) $center['name']]) ?></span><?php endif; ?></h1>
        <ul class="topo-counts" data-test="topo-counts">
            <?php foreach ($statusOrder as $st): ?>
            <?php if (($counts[$st] ?? 0) > 0): ?><li><?= $v->raw($v->render('partials/status', ['status' => $st])) ?> <strong><?= $v->e($counts[$st]) ?></strong></li><?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="topo-grid">
        <aside class="topo-panel topo-left">
            <form method="get" action="<?= $v->url('/topology') ?>" class="topo-form" data-test="topology-form">
                <?php if ($v->shared('pretty_urls') !== true): ?><input type="hidden" name="r" value="/topology"><?php endif; ?>
                <input type="hidden" name="layout" value="<?= $v->e($params['layout']) ?>" data-layout-input>
                <div class="seg" role="radiogroup" aria-label="<?= $v->t('topology.mode') ?>">
                    <label><input type="radio" name="mode" value="neighborhood"<?= $params['mode'] === 'neighborhood' ? ' checked' : '' ?>> <?= $v->t('topology.mode_neighborhood') ?></label>
                    <label><input type="radio" name="mode" value="filter"<?= $params['mode'] === 'filter' ? ' checked' : '' ?>> <?= $v->t('topology.mode_filter') ?></label>
                </div>
                <div class="topo-fields" data-mode-fields="neighborhood">
                    <label for="t-center"><?= $v->t('topology.center') ?></label>
                    <select id="t-center" name="center">
                        <?php foreach ($objectNames as $oid => $name): ?>
                        <option value="<?= $v->e($oid) ?>"<?= $params['center'] === $oid ? ' selected' : '' ?>><?= $v->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="t-depth"><?= $v->t('topology.depth') ?></label>
                    <select id="t-depth" name="depth">
                        <option value="1"<?= $params['depth'] === 1 ? ' selected' : '' ?>><?= $v->t('topology.depth_1') ?></option>
                        <option value="2"<?= $params['depth'] === 2 ? ' selected' : '' ?>><?= $v->t('topology.depth_2') ?></option>
                    </select>
                </div>
                <div class="topo-fields" data-mode-fields="filter">
                    <label for="t-type"><?= $v->t('inventory.type') ?></label>
                    <select id="t-type" name="type">
                        <option value=""><?= $v->t('inventory.all') ?></option>
                        <?php foreach (\Nordvarr\Inventory\Vocabulary::OBJECT_TYPES as $type): ?>
                        <option value="<?= $v->e($type) ?>"<?= $params['type'] === $type ? ' selected' : '' ?>><?= $v->t('type.' . $type) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="t-tag"><?= $v->t('inventory.tag') ?></label>
                    <select id="t-tag" name="tag">
                        <option value=""><?= $v->t('inventory.all') ?></option>
                        <?php foreach ($allTags as $tag): ?>
                        <option value="<?= $v->e($tag) ?>"<?= $params['tag'] === $tag ? ' selected' : '' ?>><?= $v->e($tag) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="t-status"><?= $v->t('inventory.status') ?></label>
                    <select id="t-status" name="status">
                        <option value=""><?= $v->t('inventory.all') ?></option>
                        <?php foreach ($statusOrder as $st): ?>
                        <option value="<?= $v->e($st) ?>"<?= $params['status'] === $st ? ' selected' : '' ?>><?= $v->t('status.' . $st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit"><?= $v->t('topology.show_view') ?></button>
            </form>

            <h2 class="topo-h"><?= $v->t('topology.in_view', ['count' => count($nodes)]) ?></h2>
            <?php if ($nodes !== []): ?>
            <label for="t-search" class="visually-hidden"><?= $v->t('topology.search') ?></label>
            <input type="search" id="t-search" class="topo-search" placeholder="<?= $v->t('topology.search') ?>" autocomplete="off" data-test="map-search">
            <p class="small-text muted" data-search-empty hidden><?= $v->t('topology.search_empty') ?></p>
            <fieldset class="topo-floor-filter" data-test="floor-filter" hidden>
                <legend><?= $v->t('topology.floors_shown') ?></legend>
                <div data-floor-boxes></div>
            </fieldset>
            <?php endif; ?>
            <?php if ($graph['truncated'] && $params['mode'] === 'neighborhood'): ?>
            <p class="small-text muted" data-test="truncated"><?= $v->t('topology.truncated', ['max' => \Nordvarr\Topology\Topology::MAX_NODES, 'count' => (int) $graph['total']]) ?></p>
            <?php endif; ?>
            <ul class="node-list" data-test="node-list">
                <?php foreach ($nodes as $n): ?>
                <?php $d = $n['data']; ?>
                <li>
                    <button type="button" class="node-item" data-node="<?= $v->e($d['id']) ?>">
                        <span class="dot dot-<?= $v->e($d['status']) ?>" aria-hidden="true"></span>
                        <span class="node-name"><?= $v->e($d['name']) ?></span>
                        <span class="node-meta"><?= $d['reason'] !== '' ? $v->e($d['reason']) : $v->e($d['typeLabel']) ?></span>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <section class="topo-map" aria-label="<?= $v->t('topology.graph') ?>">
            <?php if ($graph['truncated'] && $params['mode'] === 'filter'): ?>
            <div class="topo-empty" data-test="too-many"><?= $v->t('topology.too_many', ['count' => (int) $graph['total'], 'max' => \Nordvarr\Topology\Topology::MAX_NODES]) ?></div>
            <?php elseif ($nodes === []): ?>
            <div class="topo-empty"><?= $v->t('topology.empty') ?></div>
            <?php else: ?>
            <div id="cy" class="cy" data-test="graph"
                 data-graph="<?= $v->e(json_encode(['nodes' => $graph['nodes'], 'edges' => $graph['edges'], 'floors' => $graph['floors']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                 data-layout="<?= $v->e($params['layout']) ?>"
                 data-refresh-graph="<?= $v->e($dataUrl) ?>"
                 data-node-url="<?= $v->e($nodeUrl) ?>"
                 data-object-url="<?= $v->e($objectUrl) ?>"
                 data-center-url="<?= $v->e($centerUrl) ?>"
                 data-center="<?= $v->e($params['mode'] === 'neighborhood' ? $params['center'] : '') ?>"></div>
            <div class="topo-legend" aria-hidden="true">
                <span><i class="leg-line"></i><?= $v->t('rel.out.depends_on') ?></span>
                <span><i class="leg-line dashed"></i><?= $v->t('rel.out.contains') ?></span>
                <span><i class="leg-line causal"></i><?= $v->t('topology.causal') ?></span>
                <span><i class="leg-ring"></i><?= $v->t('topology.ring') ?></span>
            </div>
            <div class="topo-tools">
                <div class="seg seg-small" role="group" aria-label="<?= $v->t('topology.layout') ?>" data-test="layout-switch">
                    <button type="button" class="small" data-layout="auto" aria-pressed="<?= $params['layout'] === 'auto' ? 'true' : 'false' ?>"><?= $v->t('topology.layout_auto') ?></button>
                    <button type="button" class="small" data-layout="floors" aria-pressed="<?= $params['layout'] === 'floors' ? 'true' : 'false' ?>"><?= $v->t('topology.layout_floors') ?></button>
                    <button type="button" class="small" data-layout="3d" aria-pressed="<?= $params['layout'] === '3d' ? 'true' : 'false' ?>"><?= $v->t('topology.layout_3d') ?></button>
                </div>
                <button type="button" class="small" data-cy="cause" data-test="cause-mode" hidden aria-pressed="false"><?= $v->t('topology.to_cause') ?></button>
                <button type="button" class="small" data-3d="top" data-3d-only hidden><?= $v->t('topology.top_view') ?></button>
                <button type="button" class="small" data-3d="spread" data-3d-only hidden aria-pressed="false"><?= $v->t('topology.spread') ?></button>
                <button type="button" class="small" data-cy="fit"><?= $v->t('topology.fit') ?></button>
            </div>
            <div id="map3d" class="map3d" data-test="map3d" hidden data-module="<?= $v->asset('js/topology3d.js') ?>" aria-label="<?= $v->t('topology.map3d') ?>"></div>
            <p class="map3d-missing" data-webgl-missing data-test="webgl-missing" hidden><?= $v->t('topology.webgl_missing') ?></p>
            <?php endif; ?>
        </section>

        <aside class="topo-panel topo-right" id="node-panel" aria-live="polite" data-test="node-panel">
            <p class="muted"><?= $v->t('topology.pick_node') ?></p>
        </aside>
    </div>
</div>
