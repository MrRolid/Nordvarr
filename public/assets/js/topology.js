// Nordvarr - mapa topologie (Cytoscape + dagre). ES modul bez buildu; knihovny jsou
// načtené jako běžné skripty z assets/vendor a dostupné jako globální proměnné.
// Tři pohledy: automatické rozvržení (dagre, u velkých grafů silové), patra (polohy spočítané
// serverem, viz Nordvarr\Topology\Floors, s pásy pater v pozadí) a 3D (topology3d.js, načte
// se až při prvním přepnutí, bez WebGL zůstane mapa v patrech).

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

    // Pruhy prostředků serveru s agentem (CPU, RAM, nejplnější disk) jako obrázek SVG v uzlu.
    // Barva podle úrovně ze serveru, zastaralé hlášení šedě. Varianta bez textu pro malé uzly.
    const RES_KEYS = { cpu: 'CPU', mem: 'RAM', disk: 'Disk' };
    const resourceImage = (resources, withText) => {
        const items = (resources?.items ?? []).filter((i) => RES_KEYS[i.key]);
        if (items.length === 0) return null;
        const color = (level) => css(resources.stale ? '--st-unknown'
            : { ok: '--st-healthy', warn: '--st-degraded', crit: '--st-unhealthy' }[level] ?? '--st-unknown');
        const track = css('--map-node-ring');
        const text = css('--map-text');
        const esc = (v) => String(v).replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
        const row = 18;
        const [x0, w] = withText ? [34, 52] : [0, 120];
        const parts = items.map((item, i) => {
            const y = i * row;
            const fill = Math.max(2, Math.round((w * Math.min(100, item.pct)) / 100));
            let out = `<rect x="${x0}" y="${y + 5}" width="${w}" height="8" rx="3" fill="${esc(track)}"/>`
                + `<rect x="${x0}" y="${y + 5}" width="${fill}" height="8" rx="3" fill="${esc(color(item.level))}"/>`;
            if (withText) {
                out += `<text x="0" y="${y + 13}" font-size="11" font-weight="600" fill="${esc(text)}">${RES_KEYS[item.key]}</text>`
                    + `<text x="120" y="${y + 13}" font-size="11" font-weight="600" text-anchor="end" fill="${esc(text)}">${item.pct}%</text>`;
            }
            return out;
        });
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="120" height="${items.length * row}" viewBox="0 0 120 ${items.length * row}" `
            + `font-family="system-ui, -apple-system, Segoe UI, Roboto, sans-serif">${parts.join('')}</svg>`;
        return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    };

    // Data uzlu doplněná o popisek a podíly stavů kontrol pro prstenec.
    const prepare = (graph) => {
        const nodes = graph.nodes.map((n) => {
            const d = { ...n.data };
            d.resImg = resourceImage(d.resources, true);
            d.resBars = resourceImage(d.resources, false);
            d.hasRes = d.resImg !== null;
            if (!d.hasRes) {
                delete d.resImg;
                delete d.resBars;
            }
            // U serveru s pruhy prostředků je v popisku jen název, hodnoty jsou v pruzích.
            d.label = d.subtitle && !d.hasRes ? `${d.name}\n${d.subtitle}` : d.name;
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
                'st-healthy', 'st-degraded', 'st-unhealthy', 'st-unknown', 'st-paused', 'map-bg', 'map-band']
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
            {
                selector: 'node[?hasRes]',
                style: compact ? {
                    'background-image': 'data(resBars)',
                    'background-fit': 'none',
                    'background-width': '62%',
                    'background-height': 'auto',
                    'background-clip': 'node',
                } : {
                    // Pruhy ve spodní polovině uvnitř prstence, název nad nimi.
                    'background-image': 'data(resImg)',
                    'background-fit': 'none',
                    'background-width': '62%',
                    'background-height': 'auto',
                    'background-position-y': '66%',
                    'background-clip': 'node',
                    'text-margin-y': -27,
                    'text-max-width': 70,
                },
            },
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
            {
                selector: 'node.band',
                style: {
                    shape: 'round-rectangle',
                    width: 'data(w)',
                    height: 'data(h)',
                    // Barvy Cytoscape neberou průhlednost z rgba(), proto zvlášť krytí.
                    'background-color': c['map-band'],
                    'background-opacity': 0.07,
                    'border-width': 1,
                    'border-color': c['map-band'],
                    'border-opacity': 0.3,
                    label: 'data(label)',
                    color: c['map-muted'],
                    'font-size': compact ? 18 : 14,
                    'font-weight': 600,
                    'text-wrap': 'wrap',
                    'text-max-width': compact ? 330 : 260,
                    'text-halign': 'left',
                    'text-valign': 'center',
                    'text-justification': 'right',
                    'text-margin-x': -12,
                    'min-zoomed-font-size': 0,
                    events: 'no',
                    'z-compound-depth': 'bottom',
                    'pie-size': 0,
                },
            },
        ];
    };

    const initial = JSON.parse(el.dataset.graph);
    compact = initial.nodes.length > 24;
    const MODES = ['auto', 'floors', '3d'];
    let mode = MODES.includes(el.dataset.layout) ? el.dataset.layout : 'auto';
    let floors = initial.floors ?? [];
    let lastGraph = initial;
    // Vypnutá patra (klíče pater), platí ve všech pohledech.
    const hiddenFloors = new Set();
    const objects = () => cy.elements().not('.band');
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

    // Patra: polohy uzlů ze serveru (jednotky = rozestup uzlů a řádek), pásy pater v pozadí.
    const floorsLayout = () => {
        cy.nodes('.band').remove();
        // Rozestupy podle velikosti uzlu a popisku (ve velkém grafu je popisek pod uzlem).
        // Řádky se roztáhnou, aby kresba využila výšku mapy (široký graf by jinak zůstal plochý).
        const gapX = compact ? 130 : 140;
        const baseY = compact ? 120 : 165;
        const nodes = cy.nodes();
        const fxs = nodes.map((n) => n.data('fx') ?? 0);
        const minX = Math.min(...fxs) * gapX;
        const maxX = Math.max(...fxs) * gapX;
        const rows = floors.reduce((sum, f) => sum + f.rows, 0) || 1;
        const aspect = el.clientWidth > 0 ? el.clientHeight / el.clientWidth : 0.6;
        const gapY = Math.min(baseY * 2, Math.max(baseY, ((maxX - minX + gapX + 200) * aspect) / rows));
        nodes.layout({
            name: 'preset',
            positions: (n) => ({ x: (n.data('fx') ?? 0) * gapX, y: (n.data('fy') ?? 0) * gapY }),
            fit: false,
            animate: false,
        }).run();
        cy.add(floors.map((f) => ({
            group: 'nodes',
            classes: 'band',
            grabbable: false,
            selectable: false,
            locked: true,
            data: { id: `floor:${f.floor}`, label: f.label, floor: f.floor, w: maxX - minX + gapX, h: f.rows * gapY - 16 },
            position: { x: (minX + maxX) / 2, y: (f.y + (f.rows - 1) / 2) * gapY },
        })));
        for (const b of cy.nodes('.band')) {
            if (hiddenFloors.has(b.data('floor'))) b.style('display', 'none');
        }
        cy.fit(undefined, 30);
    };

    // Rozložení po vrstvách shora dolů, nebo zleva doprava, pokud se tak graf do mapy vejde větší.
    const layout = () => {
        if (mode === '3d') return;
        if (mode === 'floors') {
            floorsLayout();
            if (cy.zoom() > 1.25) {
                cy.zoom(1.25);
                cy.center();
            }
            return;
        }
        cy.nodes('.band').remove();
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

    // 3D pohled (modul se načte při prvním použití).
    const box3d = document.getElementById('map3d');
    const tools3d = document.querySelectorAll('[data-3d-only]');
    const fallback3d = document.querySelector('[data-webgl-missing]');
    let map3d = null;
    const webgl = () => {
        try {
            const canvas = document.createElement('canvas');
            return !!(canvas.getContext('webgl2') || canvas.getContext('webgl'));
        } catch {
            return false;
        }
    };
    const show3d = (on) => {
        el.hidden = on;
        if (box3d) box3d.hidden = !on;
        for (const t of tools3d) t.hidden = !on;
    };

    // Zvýraznění okolí uzlu (při najetí a výběru).
    // Vybraný uzel: obarvené hrany. Najetí myší: navíc ztlumí zbytek grafu.
    const highlight = (node, fade = false) => {
        cy.elements().removeClass('faded hl');
        if (!node) return;
        const keep = node.closedNeighborhood();
        if (fade) objects().not(keep).addClass('faded');
        keep.edges().addClass('hl');
    };
    const restore = () => {
        if (causePath) showPath2d(causePath);
        else highlight(selected ? cy.getElementById(selected) : null);
    };
    // Režim problému: cesta od objektu k pravděpodobné příčině, zbytek grafu ztlumený.
    let causePath = null;
    const showPath2d = (path) => {
        cy.elements().removeClass('faded hl');
        objects().not(path).addClass('faded');
        path.edges().addClass('hl');
    };

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
    const select = (id, center = false, keepCause = false) => {
        const node = cy.getElementById(id);
        if (node.empty()) return;
        if (!keepCause) clearCause();
        selected = id;
        cy.$(':selected').unselect();
        node.select();
        if (causePath) showPath2d(causePath);
        else highlight(node);
        // Posunout mapu jen tehdy, když uzel není celý vidět.
        const ext = cy.extent();
        const bb = node.boundingBox();
        const visible = bb.x1 >= ext.x1 && bb.x2 <= ext.x2 && bb.y1 >= ext.y1 && bb.y2 <= ext.y2;
        if (center && !visible && mode !== '3d') cy.animate({ center: { eles: node }, duration: 250 });
        map3d?.select(id);
        if (center && mode === '3d') map3d?.focus(id);
        for (const item of listItems()) item.classList.toggle('active', item.dataset.node === id);
        loadCard(id);
    };

    cy.on('tap', 'node', (evt) => select(evt.target.id()));
    cy.on('dbltap', 'node', (evt) => { window.location.href = urlFor(el.dataset.objectUrl, evt.target.id()); });
    cy.on('mouseover', 'node', (evt) => { highlight(evt.target, true); el.classList.add('pointer'); });
    cy.on('mouseout', 'node', () => { restore(); el.classList.remove('pointer'); });
    cy.on('tap', (evt) => {
        if (evt.target === cy) {
            clearCause();
            selected = null;
            cy.$(':selected').unselect();
            highlight(null);
            for (const item of listItems()) item.classList.remove('active');
        }
    });
    for (const item of listItems()) {
        item.addEventListener('click', () => select(item.dataset.node, true));
    }
    document.querySelector('[data-cy="fit"]')?.addEventListener('click', () => {
        if (mode === '3d') map3d?.reset();
        else cy.fit(undefined, 30);
    });
    document.querySelector('[data-3d="top"]')?.addEventListener('click', () => map3d?.topView());
    const spreadButton = document.querySelector('[data-3d="spread"]');
    spreadButton?.addEventListener('click', () => {
        const on = spreadButton.getAttribute('aria-pressed') !== 'true';
        spreadButton.setAttribute('aria-pressed', String(on));
        map3d?.setSpread(on);
    });

    // Přepínač rozvržení: zapamatuje se v adrese a ve formuláři (pro další zobrazení).
    const layoutButtons = document.querySelectorAll('.topo-tools [data-layout]');
    const enter3d = async () => {
        if (!box3d || !webgl()) {
            // Bez WebGL zůstane mapa v patrech s upozorněním.
            if (fallback3d) fallback3d.hidden = false;
            setMode('floors');
            return;
        }
        show3d(true);
        if (!map3d) {
            const module = await import(box3d.dataset.module);
            if (mode !== '3d') return;
            map3d = module.createMap3d(box3d, lastGraph, {
                onSelect: (id) => select(id),
                onOpen: (id) => { window.location.href = urlFor(el.dataset.objectUrl, id); },
            });
            box3d.map3d = map3d;
            map3d.setHiddenFloors([...hiddenFloors]);
            if (causePath) map3d.setPath(causePath.nodes().map((n) => n.id()), causePath.edges().map((e) => e.id()), selected);
        } else {
            map3d.resize();
            map3d.update(lastGraph);
        }
        if (selected) map3d.select(selected);
    };
    const setMode = (next) => {
        const previous = mode;
        mode = MODES.includes(next) ? next : 'auto';
        el.dataset.layout = mode;
        for (const b of layoutButtons) b.setAttribute('aria-pressed', String(b.dataset.layout === mode));
        const input = document.querySelector('[data-layout-input]');
        if (input) input.value = mode;
        try {
            const url = new URL(window.location.href);
            if (mode === 'auto') url.searchParams.delete('layout');
            else url.searchParams.set('layout', mode);
            window.history.replaceState(null, '', url);
        } catch {
            // Bez změny adresy.
        }
        if (mode === '3d') {
            enter3d();
            return;
        }
        if (previous === '3d') {
            show3d(false);
            cy.resize();
        }
        layout();
        restore();
    };
    for (const b of layoutButtons) b.addEventListener('click', () => setMode(b.dataset.layout));

    // Hledání: filtruje seznam objektů, Enter vybere první shodu a ukáže ji v mapě, Esc hledání zruší.
    const search = document.getElementById('t-search');
    const searchEmpty = document.querySelector('[data-search-empty]');
    const fold = (text) => text.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const filterList = () => {
        const query = fold(search.value.trim());
        let shown = 0;
        for (const item of listItems()) {
            const match = query === '' || fold(item.textContent).includes(query);
            item.closest('li').hidden = !match;
            if (match) shown += 1;
        }
        if (searchEmpty) searchEmpty.hidden = shown > 0;
    };
    search?.addEventListener('input', filterList);
    search?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            const hit = [...listItems()].find((item) => !item.closest('li').hidden);
            if (hit) select(hit.dataset.node, true);
        } else if (event.key === 'Escape') {
            search.value = '';
            filterList();
        }
    });

    // Patra v mapě: zaškrtávátka podle pater v datech, vypnuté patro se skryje ve 2D i ve 3D.
    const floorFilter = document.querySelector('[data-test="floor-filter"]');
    const applyFloors = () => {
        cy.batch(() => {
            for (const n of cy.nodes()) n.style('display', hiddenFloors.has(n.data('floor')) ? 'none' : 'element');
        });
        map3d?.setHiddenFloors([...hiddenFloors]);
    };
    let floorKeys = '';
    const renderFloorFilter = () => {
        if (!floorFilter) return;
        const keys = floors.map((f) => f.floor).join(',');
        if (keys === floorKeys) return;
        floorKeys = keys;
        for (const key of [...hiddenFloors]) {
            if (!floors.some((f) => f.floor === key)) hiddenFloors.delete(key);
        }
        const boxes = floorFilter.querySelector('[data-floor-boxes]');
        boxes.replaceChildren(...floors.map((f) => {
            const label = document.createElement('label');
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = f.floor;
            input.checked = !hiddenFloors.has(f.floor);
            input.addEventListener('change', () => {
                if (input.checked) hiddenFloors.delete(f.floor);
                else hiddenFloors.add(f.floor);
                applyFloors();
            });
            label.append(input, ` ${f.label}`);
            return label;
        }));
        floorFilter.hidden = floors.length < 2;
    };
    renderFloorFilter();

    // Tlačítko "K příčině": vybraný objekt s pravděpodobnou příčinou, jinak první takový v seznamu
    // (seznam je seřazený od nejhoršího stavu).
    const causeButton = document.querySelector('[data-cy="cause"]');
    const causeSource = () => {
        const candidates = [selected, ...[...listItems()].map((item) => item.dataset.node)];
        for (const id of candidates) {
            const node = id ? cy.getElementById(id) : null;
            if (node && !node.empty() && node.data('cause') && !cy.getElementById(node.data('cause')).empty()) return node;
        }
        return null;
    };
    const updateCauseButton = () => {
        if (causeButton) causeButton.hidden = !causePath && causeSource() === null;
    };
    const clearCause = () => {
        if (!causePath) return;
        causePath = null;
        causeButton?.setAttribute('aria-pressed', 'false');
        map3d?.setPath(null);
        restore();
        updateCauseButton();
    };
    const showCause = () => {
        const from = causeSource();
        if (!from) return;
        const to = cy.getElementById(from.data('cause'));
        const found = objects().aStar({ root: from, goal: to, directed: false });
        const path = found.found ? found.path : from.union(to);
        select(to.id(), false, true);
        causePath = path;
        causeButton?.setAttribute('aria-pressed', 'true');
        showPath2d(path);
        el.dataset.causePath = path.nodes().map((n) => n.data('name')).join(' > ');
        if (mode === '3d') map3d?.setPath(path.nodes().map((n) => n.id()), path.edges().map((e) => e.id()), to.id());
        else cy.animate({ fit: { eles: path, padding: 80 }, duration: 300 });
    };
    causeButton?.addEventListener('click', () => (causePath ? clearCause() : showCause()));
    updateCauseButton();

    // Výchozí výběr: centrum okolí, v režimu filtru první uzel seznamu (seřazený od nejhoršího stavu).
    const first = el.dataset.center || document.querySelector('.node-item[data-node]')?.dataset.node;
    if (first) select(first);
    if (mode === '3d') enter3d();

    // Přepnutí světlého a tmavého režimu.
    window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
        // Pruhy prostředků mají barvy zapečené v obrázku, proto se vytvoří znovu.
        for (const e of prepare(lastGraph)) {
            const node = cy.getElementById(e.data.id);
            if (!node.empty()) node.data(e.data);
        }
        cy.style(style());
    });

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
                floors = graph.floors ?? [];
                lastGraph = graph;
                map3d?.update(graph);
                const elements = prepare(graph);
                const ids = new Set(elements.map((e) => e.data.id));
                const same = ids.size === objects().length && objects().every((e) => ids.has(e.id()));
                if (same) {
                    for (const e of elements) cy.getElementById(e.data.id).data(e.data);
                } else {
                    clearCause();
                    cy.elements().remove();
                    cy.add(elements);
                    compact = graph.nodes.length > 24;
                    cy.style(style());
                    layout();
                }
                renderFloorFilter();
                applyFloors();
                updateCauseButton();
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
