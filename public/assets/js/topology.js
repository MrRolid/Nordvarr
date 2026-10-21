// Nordvarr - mapa topologie (Cytoscape + dagre). ES modul bez buildu; knihovny jsou
// načtené jako běžné skripty z assets/vendor a dostupné jako globální proměnné.

const el = document.getElementById('cy');
const panel = document.getElementById('node-panel');

// Formulář: zobrazit jen pole zvoleného režimu.
const form = document.querySelector('form.topo-form');
if (form) {
    const sync = () => {
        const mode = form.querySelector('input[name="mode"]:checked')?.value ?? 'neighborhood';
        for (const box of form.querySelectorAll('[data-mode-fields]')) {
            const active = box.dataset.modeFields === mode;
            box.hidden = !active;
            for (const input of box.querySelectorAll('select, input')) input.disabled = !active;
        }
    };
    form.addEventListener('change', sync);
    sync();
}

if (el && window.cytoscape) {
    if (window.cytoscapeDagre) {
        try {
            window.cytoscape.use(window.cytoscapeDagre);
        } catch {
            // Rozšíření už je zaregistrované.
        }
    }

    const STATUSES = ['healthy', 'degraded', 'unhealthy', 'unknown', 'paused'];
    const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const urlFor = (template, id) => template.replace('__ID__', encodeURIComponent(id));

    // Data uzlu doplněná o popisek a podíly stavů kontrol pro prstenec.
    const prepare = (graph) => {
        const nodes = graph.nodes.map((n) => {
            const d = { ...n.data };
            d.label = d.subtitle ? `${d.name}\n${d.subtitle}` : d.name;
            const total = STATUSES.reduce((sum, s) => sum + (d.ring?.[s] ?? 0), 0);
            for (const s of STATUSES) d[`p_${s}`] = total > 0 ? (100 * (d.ring?.[s] ?? 0)) / total : 0;
            d.hasChecks = total > 0;
            return { group: 'nodes', data: d };
        });
        const edges = graph.edges.map((e) => ({ group: 'edges', data: { ...e.data } }));
        return [...nodes, ...edges];
    };

    // Velký graf (typicky režim filtru): menší uzly s popiskem pod nimi, popisky hran jen u zvýraznění.
    let compact = false;

    const style = () => {
        const size = compact ? 46 : 100;
        const c = Object.fromEntries(
            ['map-node', 'map-node-ring', 'map-text', 'map-muted', 'map-edge', 'map-causal', 'map-center',
                'st-healthy', 'st-degraded', 'st-unhealthy', 'st-unknown', 'st-paused', 'map-bg']
                .map((k) => [k, css(`--${k}`)])
        );
        return [
            {
                selector: 'node',
                style: {
                    shape: 'ellipse',
                    width: size,
                    height: size,
                    'background-color': c['map-node'],
                    'border-width': 2,
                    'border-color': c['map-node-ring'],
                    label: 'data(label)',
                    color: c['map-text'],
                    'font-family': 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                    'font-size': compact ? 13 : 12,
                    'font-weight': 600,
                    'text-wrap': 'wrap',
                    'text-max-width': compact ? 120 : 84,
                    'text-overflow-wrap': 'anywhere',
                    'text-valign': compact ? 'bottom' : 'center',
                    'text-margin-y': compact ? 5 : 0,
                    'text-halign': 'center',
                    'line-height': 1.3,
                    'min-zoomed-font-size': compact ? 0 : 6,
                    'pie-size': '100%',
                    'pie-hole': compact ? '74%' : '84%',
                    'pie-1-background-color': c['st-unhealthy'],
                    'pie-1-background-size': 'data(p_unhealthy)',
                    'pie-2-background-color': c['st-degraded'],
                    'pie-2-background-size': 'data(p_degraded)',
                    'pie-3-background-color': c['st-unknown'],
                    'pie-3-background-size': 'data(p_unknown)',
                    'pie-4-background-color': c['st-paused'],
                    'pie-4-background-size': 'data(p_paused)',
                    'pie-5-background-color': c['st-healthy'],
                    'pie-5-background-size': 'data(p_healthy)',
                    'transition-property': 'opacity',
                    'transition-duration': '150ms',
                },
            },
            { selector: 'node[?hasChecks]', style: { 'border-width': 0 } },
            { selector: 'node[status = "unhealthy"]', style: { 'underlay-color': c['st-unhealthy'], 'underlay-opacity': 0.16, 'underlay-padding': 10, 'underlay-shape': 'ellipse' } },
            { selector: 'node[status = "degraded"]', style: { 'underlay-color': c['st-degraded'], 'underlay-opacity': 0.12, 'underlay-padding': 8, 'underlay-shape': 'ellipse' } },
            { selector: 'node[status = "paused"]', style: { 'border-style': 'dashed', 'border-width': 2, 'border-color': c['st-paused'] } },
            { selector: 'node[?maintenance]', style: { 'border-style': 'dashed', 'border-width': 2, 'border-color': c['st-degraded'] } },
            { selector: 'node[?center]', style: { width: size * 1.18, height: size * 1.18, 'font-size': 12, 'border-width': 3, 'border-color': c['map-center'] } },
            { selector: 'node:selected', style: { 'overlay-color': c['map-center'], 'overlay-opacity': 0.16, 'overlay-padding': 8, 'overlay-shape': 'ellipse' } },
            {
                selector: 'edge',
                style: {
                    width: 1.6,
                    'curve-style': 'bezier',
                    'line-color': c['map-edge'],
                    'target-arrow-color': c['map-edge'],
                    'target-arrow-shape': 'triangle',
                    'arrow-scale': 0.9,
                    label: compact ? '' : 'data(label)',
                    'font-size': 10,
                    color: c['map-muted'],
                    'text-background-color': c['map-bg'],
                    'text-background-opacity': 0.85,
                    'text-background-padding': 2,
                    'min-zoomed-font-size': 8,
                    'transition-property': 'opacity',
                    'transition-duration': '150ms',
                },
            },
            { selector: 'edge[kind = "contains"]', style: { 'line-style': 'dashed', 'target-arrow-shape': 'none', 'source-arrow-shape': 'circle', 'source-arrow-color': c['map-edge'], 'arrow-scale': 0.6 } },
            { selector: 'edge[?causal]', style: { width: 3.2, 'line-color': c['map-causal'], 'target-arrow-color': c['map-causal'], 'source-arrow-color': c['map-causal'], color: c['map-causal'], 'z-index': 10, 'font-weight': 700 } },
            { selector: 'edge.hl', style: { label: 'data(label)', width: 2.4, 'line-color': c['map-center'], 'target-arrow-color': c['map-center'], 'source-arrow-color': c['map-center'], color: c['map-text'] } },
            { selector: 'edge.hl[?causal]', style: { 'line-color': c['map-causal'], 'target-arrow-color': c['map-causal'], 'source-arrow-color': c['map-causal'], width: 3.2 } },
            { selector: '.faded', style: { opacity: 0.3 } },
        ];
    };

    const initial = JSON.parse(el.dataset.graph);
    compact = initial.nodes.length > 24;
    const cy = window.cytoscape({
        container: el,
        elements: prepare(initial),
        style: style(),
        wheelSensitivity: 0.25,
        minZoom: 0.2,
        maxZoom: 2.5,
        boxSelectionEnabled: false,
        autoungrabify: false,
    });

    // Rozložení po vrstvách shora dolů, nebo zleva doprava, pokud se tak graf do mapy vejde větší.
    const layout = () => {
        const run = (rankDir) => {
            cy.layout({
                name: window.cytoscapeDagre ? 'dagre' : 'breadthfirst',
                rankDir,
                nodeSep: compact ? 56 : 46,
                rankSep: compact ? 70 : 84,
                edgeSep: 18,
                padding: 30,
                fit: true,
                animate: false,
            }).run();
            return cy.zoom();
        };
        if (compact) {
            // Mnoho uzlů v málo vrstvách by dagre roztáhl do šířky; silové rozložení vyplní mapu rovnoměrněji.
            cy.layout({ name: 'cose', animate: false, fit: true, padding: 30, randomize: false, nodeRepulsion: () => 16000, idealEdgeLength: () => 90, nodeOverlap: 40, componentSpacing: 60 }).run();
        } else if (window.cytoscapeDagre && run('LR') > run('TB') * 1.15) {
            run('LR');
        }
        if (cy.zoom() > 1.25) {
            cy.zoom(1.25);
            cy.center();
        }
    };
    layout();

    // Zvýraznění okolí uzlu (při najetí a výběru).
    // Vybraný uzel: obarvené hrany. Najetí myší: navíc ztlumí zbytek grafu.
    const highlight = (node, fade = false) => {
        cy.elements().removeClass('faded hl');
        if (!node) return;
        const keep = node.closedNeighborhood();
        if (fade) cy.elements().not(keep).addClass('faded');
        keep.edges().addClass('hl');
    };
    const restore = () => highlight(selected ? cy.getElementById(selected) : null);

    let selected = null;
    const listItems = () => document.querySelectorAll('.node-item[data-node]');
    const loadCard = async (id) => {
        if (!panel) return;
        panel.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(urlFor(el.dataset.nodeUrl, id), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            panel.innerHTML = (await response.json()).html;
        } catch {
            panel.querySelector('.np')?.classList.add('stale');
        } finally {
            panel.removeAttribute('aria-busy');
        }
    };
    const select = (id, center = false) => {
        const node = cy.getElementById(id);
        if (node.empty()) return;
        selected = id;
        cy.$(':selected').unselect();
        node.select();
        highlight(node);
        // Posunout mapu jen tehdy, když uzel není celý vidět.
        const ext = cy.extent();
        const bb = node.boundingBox();
        const visible = bb.x1 >= ext.x1 && bb.x2 <= ext.x2 && bb.y1 >= ext.y1 && bb.y2 <= ext.y2;
        if (center && !visible) cy.animate({ center: { eles: node }, duration: 250 });
        for (const item of listItems()) item.classList.toggle('active', item.dataset.node === id);
        loadCard(id);
    };

    cy.on('tap', 'node', (evt) => select(evt.target.id()));
    cy.on('dbltap', 'node', (evt) => { window.location.href = urlFor(el.dataset.objectUrl, evt.target.id()); });
    cy.on('mouseover', 'node', (evt) => { highlight(evt.target, true); el.classList.add('pointer'); });
    cy.on('mouseout', 'node', () => { restore(); el.classList.remove('pointer'); });
    cy.on('tap', (evt) => {
        if (evt.target === cy) {
            selected = null;
            cy.$(':selected').unselect();
            highlight(null);
            for (const item of listItems()) item.classList.remove('active');
        }
    });
    for (const item of listItems()) {
        item.addEventListener('click', () => select(item.dataset.node, true));
    }
    document.querySelector('[data-cy="fit"]')?.addEventListener('click', () => cy.fit(undefined, 30));

    // Výchozí výběr: centrum okolí, v režimu filtru první uzel seznamu (seřazený od nejhoršího stavu).
    const first = el.dataset.center || document.querySelector('.node-item[data-node]')?.dataset.node;
    if (first) select(first);

    // Přepnutí světlého a tmavého režimu.
    window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => cy.style(style()));

    // Obnovování dat grafu s ETag: aktivní karta 15 s, na pozadí 60 s, při chybě zpomalit.
    let etag = null;
    let failures = 0;
    let timer = null;
    const delay = () => Math.min(300000, (document.hidden ? 60000 : 15000) * 2 ** failures);
    const schedule = (ms = delay()) => { clearTimeout(timer); timer = setTimeout(refresh, ms); };
    const refresh = async () => {
        try {
            const headers = { Accept: 'application/json' };
            if (etag) headers['If-None-Match'] = etag;
            const response = await fetch(el.dataset.refreshGraph, { headers, credentials: 'same-origin', cache: 'no-store' });
            if (response.status === 200) {
                const graph = await response.json();
                etag = response.headers.get('ETag');
                const elements = prepare(graph);
                const ids = new Set(elements.map((e) => e.data.id));
                const same = ids.size === cy.elements().length && cy.elements().every((e) => ids.has(e.id()));
                if (same) {
                    for (const e of elements) cy.getElementById(e.data.id).data(e.data);
                } else {
                    cy.elements().remove();
                    cy.add(elements);
                    compact = graph.nodes.length > 24;
                    cy.style(style());
                    layout();
                }
                for (const item of listItems()) {
                    const node = cy.getElementById(item.dataset.node);
                    const dot = item.querySelector('.dot');
                    if (!node.empty() && dot) dot.className = `dot dot-${node.data('status')}`;
                }
                if (selected) {
                    cy.getElementById(selected).select();
                    loadCard(selected);
                }
            } else if (response.status !== 304) {
                throw new Error(`HTTP ${response.status}`);
            }
            failures = 0;
            el.dataset.refreshedAt = String(Date.now());
        } catch {
            failures += 1;
        }
        schedule();
    };
    document.addEventListener('visibilitychange', () => schedule(document.hidden ? delay() : 0));
    schedule();

    // Pro testy a ladění.
    el.cy = cy;
}
