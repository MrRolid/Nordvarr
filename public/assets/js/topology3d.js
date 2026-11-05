// Nordvarr - 3D pohled na patra (three.js, ES modul bez buildu). Načítá se až po přepnutí na 3D.
// Patra jsou vodorovné disky nad sebou (hardware dole), objekty leží na obvodu disku.
// Úhel na obvodu odpovídá vodorovné poloze z rozvržení pater (Nordvarr\Topology\Floors),
// takže objekty spojené vztahem leží nad sebou ve stejném směru ("sloupec").
// Popisky jsou HTML nad plátnem (ostrý text), polohu nastavuje skript přes CSSOM, což CSP dovoluje.

import * as THREE from '../vendor/three-0.170.0/three.module.min.js';

const STATUS_VARS = { healthy: 'st-healthy', degraded: 'st-degraded', unhealthy: 'st-unhealthy', unknown: 'st-unknown', paused: 'st-paused' };
const NODE_R = 3.2;
const MANY = 60;
// Kostka serveru s agentem: hrana a rozlišení textury jedné stěny.
const CUBE = NODE_R * 3.6;
const FACE_PX = 320;
const LEVEL_VARS = { ok: 'st-healthy', warn: 'st-degraded', crit: 'st-unhealthy', none: 'map-muted' };

// Přenosová rychlost čitelně (bity za sekundu).
const rate = (bps) => {
    if (bps === null || bps === undefined) return '-';
    const bits = bps * 8;
    if (bits >= 1e9) return `${(bits / 1e9).toFixed(1)} Gb/s`;
    if (bits >= 1e6) return `${(bits / 1e6).toFixed(1)} Mb/s`;
    if (bits >= 1e3) return `${Math.round(bits / 1e3)} kb/s`;
    return `${Math.round(bits)} b/s`;
};

// Stěny kostky jako plátna: souhrn (dvakrát, protilehlé stěny), CPU, RAM, disky a síť.
// Pořadí materiálů BoxGeometry: +x, -x, +y (horní), -y (spodní), +z, -z.
function drawFaces(d, css, labels) {
    const res = d.resources ?? { stale: false, items: [] };
    const det = d.hostDetails ?? {};
    const item = (key) => res.items.find((i) => i.key === key) ?? null;
    const levelColor = (level) => (res.stale ? css('st-unknown') : css(LEVEL_VARS[level] ?? 'map-muted'));
    const face = (draw) => {
        const canvas = document.createElement('canvas');
        canvas.width = FACE_PX;
        canvas.height = FACE_PX;
        const g = canvas.getContext('2d');
        g.fillStyle = css('surface');
        g.fillRect(0, 0, FACE_PX, FACE_PX);
        // Okraj ve stavu objektu.
        g.strokeStyle = css(STATUS_VARS[d.status] ?? 'map-node-ring');
        g.lineWidth = 14;
        g.strokeRect(7, 7, FACE_PX - 14, FACE_PX - 14);
        g.fillStyle = css('text');
        g.textBaseline = 'alphabetic';
        draw(g);
        return canvas;
    };
    const font = (px, weight = 700) => `${weight} ${px}px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`;
    const fit = (g, text, max) => {
        let t = String(text);
        while (t.length > 1 && g.measureText(t).width > max) t = t.slice(0, -1);
        return t === String(text) ? t : `${t.slice(0, -1)}...`;
    };
    const bar = (g, x, y, w, h, pct, fill) => {
        g.fillStyle = css('map-node-ring');
        g.fillRect(x, y, w, h);
        g.fillStyle = fill;
        g.fillRect(x, y, Math.max(3, (w * Math.min(100, pct ?? 0)) / 100), h);
    };
    // Malý graf posledních hodnot (pevné měřítko 0-100 %, nebo podle maxima).
    const spark = (g, series, x, y, w, h, stroke, max = 100) => {
        const values = (series ?? []).map((v) => (v === null ? null : Number(v)));
        g.strokeStyle = css('map-node-ring');
        g.lineWidth = 2;
        g.strokeRect(x, y, w, h);
        const top = max ?? Math.max(1, ...values.filter((v) => v !== null));
        if (values.filter((v) => v !== null).length < 2) return;
        g.strokeStyle = stroke;
        g.lineWidth = 5;
        g.beginPath();
        let started = false;
        values.forEach((v, i) => {
            if (v === null) { started = false; return; }
            const px = x + (w * i) / (values.length - 1);
            const py = y + h - (h * Math.min(top, v)) / top;
            if (started) g.lineTo(px, py); else g.moveTo(px, py);
            started = true;
        });
        g.stroke();
    };
    const title = (g, text) => {
        g.font = font(40);
        g.fillStyle = css('text');
        g.fillText(text, 30, 66);
    };
    const big = (g, it) => {
        g.font = font(92);
        g.fillStyle = it ? levelColor(it.level) : css('text');
        g.fillText(it ? `${it.pct} %` : '-', 30, 168);
    };
    const summary = face((g) => {
        g.font = font(36);
        g.fillText(fit(g, d.name, FACE_PX - 60), 30, 62);
        const rows = [['cpu', 'CPU'], ['mem', 'RAM'], ['disk', 'Disk']];
        rows.forEach(([key, name], i) => {
            const it = item(key);
            const y = 112 + i * 54;
            g.font = font(32);
            g.fillStyle = css('text');
            g.fillText(name, 30, y + 22);
            bar(g, 118, y, 108, 26, it?.pct, it ? levelColor(it.level) : css('st-unknown'));
            g.fillStyle = css('text');
            g.textAlign = 'right';
            g.fillText(it ? `${it.pct}%` : '-', FACE_PX - 28, y + 22);
            g.textAlign = 'left';
        });
        g.font = font(26, 600);
        g.fillStyle = css('text');
        g.fillText(fit(g, `${labels.net}: RX ${rate(det.rx_bps)}  TX ${rate(det.tx_bps)}`, FACE_PX - 60), 30, 290);
        if (res.stale) {
            g.fillStyle = css('st-unhealthy');
            g.fillText(labels.stale, 30, 96 - 4);
        }
    });
    const cpu = face((g) => {
        title(g, 'CPU');
        big(g, item('cpu'));
        spark(g, det.history?.cpu, 30, 196, FACE_PX - 60, 72, levelColor(item('cpu')?.level ?? 'none'));
        g.font = font(26, 600);
        g.fillStyle = css('text');
        if (det.load !== null && det.load !== undefined) g.fillText(`load ${det.load}${det.cores ? ` / ${det.cores}` : ''}`, 30, 300);
    });
    const mem = face((g) => {
        title(g, 'RAM');
        big(g, item('mem'));
        spark(g, det.history?.mem, 30, 196, FACE_PX - 60, 72, levelColor(item('mem')?.level ?? 'none'));
    });
    const disks = face((g) => {
        title(g, labels.disks);
        (det.disks ?? []).slice(0, 4).forEach((disk, i) => {
            const y = 98 + i * 54;
            g.font = font(26, 600);
            g.fillStyle = css('text');
            g.fillText(fit(g, disk.detail || '/', 140), 30, y + 20);
            bar(g, 176, y, 70, 24, disk.pct, levelColor(disk.level));
            g.fillStyle = css('text');
            g.textAlign = 'right';
            g.fillText(`${disk.pct}%`, FACE_PX - 26, y + 20);
            g.textAlign = 'left';
        });
    });
    const net = face((g) => {
        title(g, labels.net);
        g.font = font(32);
        g.fillStyle = css('st-healthy');
        g.fillText(`RX ${rate(det.rx_bps)}`, 30, 118);
        g.fillStyle = css('map-center');
        g.fillText(`TX ${rate(det.tx_bps)}`, 30, 162);
        const rx = det.history?.rx ?? [];
        const tx = det.history?.tx ?? [];
        const max = Math.max(1, ...rx.filter((v) => v !== null), ...tx.filter((v) => v !== null));
        spark(g, rx, 30, 190, FACE_PX - 60, 90, css('st-healthy'), max);
        spark(g, tx, 30, 190, FACE_PX - 60, 90, css('map-center'), max);
    });
    return [cpu, mem, disks, net, summary, summary];
}

export function createMap3d(container, graph, options = {}) {
    const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(`--${name}`).trim() || '#888888';
    const color = (name) => new THREE.Color(css(name));

    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    container.appendChild(renderer.domElement);
    const labelLayer = document.createElement('div');
    labelLayer.className = 'map3d-labels';
    container.appendChild(labelLayer);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, 1, 1, 5000);
    scene.add(new THREE.AmbientLight(0xffffff, 1.6));
    const sun = new THREE.DirectionalLight(0xffffff, 1.4);
    sun.position.set(80, 200, 120);
    scene.add(sun);

    const world = new THREE.Group();
    scene.add(world);
    const sphere = new THREE.SphereGeometry(NODE_R, 24, 16);
    const cubeGeometry = new THREE.BoxGeometry(CUBE, CUBE, CUBE);
    const labels = { net: 'Net', disks: 'Disks', stale: 'Old data', ...(options.labels ?? {}) };
    const raycaster = new THREE.Raycaster();

    // Stav scény.
    let data = graph;
    let spread = false;
    // Servery s agentem jako kostky s hodnotami na stěnách.
    let cubes = !!options.cubes;
    let nodes = new Map(); // id -> { mesh, label, data, pos }
    let edges = []; // { line, source, target, data }
    let floorLabels = []; // { el, level, radius }
    let radius = 40;
    let levels = 1;
    let hovered = null;
    let selected = null;
    // Režim problému: zvýrazněná cesta (uzly a hrany), ostatní ztlumené.
    let path = null;
    // Vypnutá patra (klíče pater).
    let hiddenFloors = new Set();
    let dirty = true;
    let running = true;

    // Kamera kolem osy válce: azimut, sklon od svislice, vzdálenost, cíl.
    const view = { azimuth: -0.6, polar: 1.15, distance: 200, target: new THREE.Vector3() };
    let anim = null;

    // Rozestup pater podle poloměru, aby se disky při šikmém pohledu nepřekrývaly.
    const floorGap = () => Math.max(40, radius * 0.75) * (spread ? 1.8 : 1);

    const statusColor = (status) => color(STATUS_VARS[status] ?? 'map-node-ring');

    const disposeMaterial = (material) => {
        for (const m of Array.isArray(material) ? material : [material]) {
            m?.map?.dispose?.();
            m?.dispose?.();
        }
    };
    const setOpacity = (mesh, opacity) => {
        for (const m of Array.isArray(mesh.material) ? mesh.material : [mesh.material]) {
            m.opacity = opacity;
            // Stěny kostky jsou neprůhledné (hrany grafu nesmí prosvítat přes text), průhledné jen ztlumené.
            if (m.map && m.transparent !== opacity < 1) {
                m.transparent = opacity < 1;
                m.needsUpdate = true;
            }
        }
    };
    const isCube = (d) => cubes && d.host && d.resources && d.hostDetails;
    // Materiály kostky z pláten stěn (bez stínování, aby byl text čitelný).
    const cubeMaterials = (d) => {
        const textures = new Map();
        return drawFaces(d, css, labels).map((canvas) => {
            if (!textures.has(canvas)) {
                const texture = new THREE.CanvasTexture(canvas);
                texture.colorSpace = THREE.SRGBColorSpace;
                texture.anisotropy = 4;
                textures.set(canvas, texture);
            }
            return new THREE.MeshBasicMaterial({ map: textures.get(canvas), transparent: false, opacity: 1 });
        });
    };

    // Popisek uzlu: název, u serveru s agentem pod ním vytížení (CPU, RAM, disk, GPU) z posledního hlášení.
    const fillLabel = (label, d) => {
        label.className = `map3d-label st-${d.status}`;
        const name = document.createElement('span');
        name.textContent = d.name;
        name.dataset.node = d.id;
        label.replaceChildren(name);
        const items = d.resources?.items ?? [];
        if (d.host && items.length > 0) {
            // Každá hodnota zvlášť s barvou podle úrovně (ok, blíží se prahu, překročen), zastaralé šedě.
            const metrics = document.createElement('span');
            metrics.className = `map3d-metrics${d.resources.stale ? ' stale' : ''}`;
            metrics.dataset.node = d.id;
            const names = { cpu: 'CPU', mem: 'RAM', disk: 'Disk', gpu: 'GPU' };
            items.forEach((item, i) => {
                if (i > 0) metrics.append(', ');
                const part = document.createElement('span');
                part.className = `res res-${item.level}`;
                part.dataset.node = d.id;
                part.dataset.res = item.key;
                part.textContent = `${names[item.key] ?? item.key} ${item.pct} %`;
                if (item.detail) part.title = item.detail;
                metrics.append(part);
            });
            label.append(metrics);
        } else if (d.host && d.subtitle) {
            const metrics = document.createElement('span');
            metrics.className = 'map3d-metrics';
            metrics.dataset.node = d.id;
            metrics.textContent = d.subtitle.split('\n').join(', ');
            label.append(metrics);
        }
    };

    const clear = () => {
        for (const child of [...world.children]) {
            world.remove(child);
            if (child === marker || child === cubeMarker) continue;
            if (child.geometry !== sphere && child.geometry !== cubeGeometry) child.geometry?.dispose?.();
            disposeMaterial(child.material);
        }
        labelLayer.replaceChildren();
        nodes = new Map();
        edges = [];
        floorLabels = [];
    };

    const build = () => {
        clear();
        const floors = data.floors ?? [];
        levels = Math.max(1, floors.length);
        // Patro -> úroveň zdola (seznam pater je shora).
        const levelOf = new Map(floors.map((f, i) => [f.floor, floors.length - 1 - i]));
        const floorByName = new Map(floors.map((f) => [f.floor, f]));
        const xs = data.nodes.map((n) => n.data.fx ?? 0);
        const minX = Math.min(...xs, 0);
        const maxX = Math.max(...xs, 0);
        const span = maxX - minX + 1;
        // Na obvodu zhruba 20 jednotek na sloupec (uzel má průměr 6,4), ať se popisky tolik nepřekrývají.
        radius = Math.max(40, (span * 20) / (2 * Math.PI));

        // Disky pater a osa.
        const band = color('map-band');
        for (const f of floors) {
            const y = levelOf.get(f.floor) * floorGap();
            const disc = new THREE.Mesh(
                new THREE.CircleGeometry(radius + 14, 96),
                new THREE.MeshBasicMaterial({ color: band, transparent: true, opacity: 0.09, side: THREE.DoubleSide, depthWrite: false }),
            );
            disc.rotation.x = -Math.PI / 2;
            disc.position.y = y - NODE_R - 1;
            disc.renderOrder = -1;
            world.add(disc);
            const rim = new THREE.LineLoop(
                new THREE.BufferGeometry().setFromPoints(Array.from({ length: 96 }, (_, i) => {
                    const a = (i / 96) * Math.PI * 2;
                    return new THREE.Vector3(Math.cos(a) * (radius + 14), y - NODE_R - 1, Math.sin(a) * (radius + 14));
                })),
                new THREE.LineBasicMaterial({ color: band, transparent: true, opacity: 0.45 }),
            );
            world.add(rim);
            const el = document.createElement('div');
            el.className = 'map3d-floor';
            el.textContent = f.label;
            labelLayer.appendChild(el);
            floorLabels.push({ el, y: y - NODE_R - 1, floor: f.floor, disc, rim });
        }
        const axis = new THREE.Line(
            new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(0, -NODE_R, 0), new THREE.Vector3(0, (levels - 1) * floorGap() + NODE_R, 0)]),
            new THREE.LineBasicMaterial({ color: band, transparent: true, opacity: 0.35 }),
        );
        world.add(axis);

        // Uzly.
        const many = data.nodes.length > MANY;
        for (const n of data.nodes) {
            const d = n.data;
            const f = floorByName.get(d.floor);
            const level = levelOf.get(d.floor) ?? 0;
            const sub = f ? Math.max(0, (d.fy ?? f.y) - f.y) : 0;
            const angle = ((d.fx ?? 0) - minX) / span * Math.PI * 2;
            const r = radius * (1 - 0.22 * sub);
            const pos = new THREE.Vector3(Math.cos(angle) * r, level * floorGap(), Math.sin(angle) * r);
            const cube = isCube(d);
            const mesh = cube
                ? new THREE.Mesh(cubeGeometry, cubeMaterials(d))
                : new THREE.Mesh(sphere, new THREE.MeshLambertMaterial({ color: statusColor(d.status), transparent: true, opacity: 1 }));
            mesh.position.copy(pos);
            // Stěna se souhrnem (+z) míří ven z kruhu, protilehlý souhrn dovnitř.
            if (cube) mesh.rotation.y = Math.PI / 2 - angle;
            if (d.center) mesh.scale.setScalar(cube ? 1.15 : 1.35);
            mesh.userData.id = d.id;
            world.add(mesh);
            const label = document.createElement('div');
            label.dataset.node = d.id;
            fillLabel(label, d);
            labelLayer.appendChild(label);
            nodes.set(d.id, { mesh, label, data: d, pos, many, cube });
        }

        // Hrany.
        for (const e of data.edges) {
            const a = nodes.get(e.data.source);
            const b = nodes.get(e.data.target);
            if (!a || !b) continue;
            const geometry = new THREE.BufferGeometry().setFromPoints([a.pos, b.pos]);
            const tint = color(e.data.causal ? 'map-causal' : 'map-edge');
            const material = e.data.kind === 'contains'
                ? new THREE.LineDashedMaterial({ color: tint, dashSize: 2.5, gapSize: 2, transparent: true, opacity: 0.85 })
                : new THREE.LineBasicMaterial({ color: tint, transparent: true, opacity: e.data.causal ? 1 : 0.85 });
            const line = new THREE.Line(geometry, material);
            if (e.data.kind === 'contains') line.computeLineDistances();
            world.add(line);
            edges.push({ line, source: e.data.source, target: e.data.target, data: e.data });
        }

        // Výběr: obrys kolem vybraného uzlu.
        marker.material.color = color('map-center');
        cubeMarker.material.color = color('map-center');
        world.add(marker);
        world.add(cubeMarker);
        applyHighlight();
        dirty = true;
    };

    const marker = new THREE.Mesh(
        new THREE.SphereGeometry(NODE_R * 1.7, 20, 14),
        new THREE.MeshBasicMaterial({ color: 0x1f5fa8, wireframe: true, transparent: true, opacity: 0.7 }),
    );
    marker.visible = false;
    // Výběr kostky: obrys hran (drátěná koule by zakryla text na stěnách).
    const cubeMarker = new THREE.LineSegments(
        new THREE.EdgesGeometry(new THREE.BoxGeometry(CUBE * 1.14, CUBE * 1.14, CUBE * 1.14)),
        new THREE.LineBasicMaterial({ color: 0x1f5fa8 }),
    );
    cubeMarker.visible = false;
    const placeMarker = (n) => {
        marker.position.copy(n.pos);
        cubeMarker.position.copy(n.pos);
        cubeMarker.rotation.copy(n.mesh.rotation);
        cubeMarker.scale.copy(n.mesh.scale);
        cubeMarker.visible = marker.visible && n.cube;
        marker.visible = marker.visible && !n.cube;
    };

    // Zvýraznění: vybraný nebo najetý uzel a jeho sousedé, ostatní ztlumené.
    // V režimu problému má přednost cesta k příčině. Vypnutá patra se nekreslí.
    const applyHighlight = () => {
        for (const [, n] of nodes) {
            n.off = hiddenFloors.has(n.data.floor);
            n.mesh.visible = !n.off;
        }
        for (const f of floorLabels) {
            f.off = hiddenFloors.has(f.floor);
            f.disc.visible = !f.off;
            f.rim.visible = !f.off;
        }
        for (const e of edges) {
            e.line.visible = !nodes.get(e.source).off && !nodes.get(e.target).off;
        }
        if (path) {
            for (const [id, n] of nodes) {
                setOpacity(n.mesh, path.nodes.has(id) ? 1 : 0.15);
                n.label.classList.toggle('dim', !path.nodes.has(id));
                n.label.classList.toggle('hidden-far', false);
                n.label.classList.toggle('active', id === selected);
            }
            for (const e of edges) {
                const on = path.edges.has(e.data.id);
                e.line.material.color = color(on ? 'map-causal' : 'map-edge');
                e.line.material.opacity = on ? 1 : 0.08;
            }
            const sel = selected ? nodes.get(selected) : null;
            marker.visible = !!sel && !sel.off;
            cubeMarker.visible = false;
            if (sel) placeMarker(sel);
            dirty = true;
            return;
        }
        const focus = hovered ?? selected;
        const keep = new Set();
        if (focus && nodes.has(focus)) {
            keep.add(focus);
            for (const e of edges) {
                if (e.source === focus) keep.add(e.target);
                if (e.target === focus) keep.add(e.source);
            }
        }
        const dim = hovered !== null && keep.size > 0;
        for (const [id, n] of nodes) {
            setOpacity(n.mesh, dim && !keep.has(id) ? 0.25 : 1);
        }
        const hl = color('map-center');
        for (const e of edges) {
            const touches = focus !== null && (e.source === focus || e.target === focus);
            e.line.material.color = touches && !e.data.causal ? hl : color(e.data.causal ? 'map-causal' : 'map-edge');
            e.line.material.opacity = dim && !touches ? 0.12 : 0.85;
        }
        const sel = selected ? nodes.get(selected) : null;
        marker.visible = !!sel && !sel.off;
        cubeMarker.visible = false;
        if (sel) placeMarker(sel);
        for (const [id, n] of nodes) {
            const important = keep.has(id) || !['healthy', 'none', 'paused'].includes(n.data.status) || n.data.center;
            n.label.classList.toggle('hidden-far', n.many && !important);
            n.label.classList.toggle('active', id === selected);
            n.label.classList.toggle('dim', dim && !keep.has(id));
        }
        dirty = true;
    };

    // Kamera.
    const center = () => new THREE.Vector3(0, ((levels - 1) * floorGap()) / 2, 0);
    // Vzdálenost, ze které je celý válec (koule kolem něj) vidět ve svislém i vodorovném směru.
    const fitDistance = () => {
        const half = ((levels - 1) * floorGap()) / 2 + NODE_R * 2;
        const bound = Math.hypot(half, radius + 16);
        const vertical = (camera.fov * Math.PI) / 360;
        const horizontal = Math.atan(Math.tan(vertical) * camera.aspect);
        return (bound / Math.sin(Math.min(vertical, horizontal))) * 1.05;
    };
    const placeCamera = () => {
        const { azimuth, polar, distance, target } = view;
        camera.position.set(
            target.x + distance * Math.sin(polar) * Math.cos(azimuth),
            target.y + distance * Math.cos(polar),
            target.z + distance * Math.sin(polar) * Math.sin(azimuth),
        );
        camera.lookAt(target);
    };
    const animateTo = (to, ms = 450) => {
        const from = { azimuth: view.azimuth, polar: view.polar, distance: view.distance, target: view.target.clone() };
        const start = performance.now();
        anim = (now) => {
            const t = Math.min(1, (now - start) / ms);
            const k = t < 0.5 ? 2 * t * t : 1 - (-2 * t + 2) ** 2 / 2;
            view.azimuth = from.azimuth + ((to.azimuth ?? from.azimuth) - from.azimuth) * k;
            view.polar = from.polar + ((to.polar ?? from.polar) - from.polar) * k;
            view.distance = from.distance + ((to.distance ?? from.distance) - from.distance) * k;
            view.target.lerpVectors(from.target, to.target ?? from.target, k);
            if (t >= 1) anim = null;
        };
    };
    const reset = (instant = false) => {
        const to = { azimuth: -0.6, polar: 1.15, distance: fitDistance(), target: center() };
        if (instant) {
            Object.assign(view, to);
        } else {
            animateTo(to);
        }
        dirty = true;
    };
    const topView = () => animateTo({ polar: 0.02, distance: fitDistance() * 0.95, target: center() });
    const focusNode = (id) => {
        const n = nodes.get(id);
        if (!n) return;
        // Natočit tak, aby uzel byl na přivrácené straně válce.
        const azimuth = Math.atan2(n.pos.z, n.pos.x);
        let delta = azimuth - view.azimuth;
        delta = Math.atan2(Math.sin(delta), Math.cos(delta));
        animateTo({ azimuth: view.azimuth + delta, polar: 1.0, distance: Math.min(view.distance, radius * 2.4 + 60), target: n.pos.clone().multiplyScalar(0.5).setY(n.pos.y) });
    };

    // Velikost.
    const resize = () => {
        const w = container.clientWidth || 1;
        const h = container.clientHeight || 1;
        renderer.setSize(w, h, false);
        renderer.domElement.style.width = `${w}px`;
        renderer.domElement.style.height = `${h}px`;
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        dirty = true;
    };
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(resize) : null;
    observer?.observe(container);

    // Poloha uzlu na obrazovce (pro popisky, klikání a testy).
    const project = (v) => {
        const p = v.clone().project(camera);
        return { x: ((p.x + 1) / 2) * container.clientWidth, y: ((1 - p.y) / 2) * container.clientHeight, visible: p.z < 1 };
    };
    const placeLabels = () => {
        for (const n of nodes.values()) {
            const p = project(n.pos);
            // Popisek pod uzlem; u kostky pod nejnižším rohem na obrazovce, aby nezakrýval stěny.
            let below = p.y + NODE_R * 2;
            if (n.cube) {
                n.mesh.updateMatrixWorld();
                const h = CUBE / 2;
                for (const [x, y, z] of [[-h, -h, -h], [h, -h, -h], [-h, -h, h], [h, -h, h], [-h, h, h], [h, h, h], [-h, h, -h], [h, h, -h]]) {
                    below = Math.max(below, project(new THREE.Vector3(x, y, z).applyMatrix4(n.mesh.matrixWorld)).y);
                }
            }
            n.label.hidden = !p.visible || n.off;
            n.label.style.transform = `translate(${Math.round(p.x)}px, ${Math.round(below + 6)}px) translateX(-50%)`;
        }
        // Popisek patra na levém okraji disku z pohledu kamery.
        const left = new THREE.Vector3(-Math.sin(view.azimuth), 0, Math.cos(view.azimuth)).multiplyScalar(radius + 16);
        for (const f of floorLabels) {
            const p = project(new THREE.Vector3(left.x, f.y, left.z));
            f.el.hidden = !p.visible || f.off;
            // Popisek vlevo od okraje disku, ale ne přes levý okraj mapy.
            const x = Math.max(f.el.offsetWidth + 4, p.x);
            f.el.style.transform = `translate(${Math.round(x)}px, ${Math.round(p.y)}px) translate(-100%, -50%)`;
        }
    };

    const loop = (now) => {
        if (!running) return;
        if (anim) {
            anim(now);
            dirty = true;
        }
        if (dirty) {
            placeCamera();
            renderer.render(scene, camera);
            placeLabels();
            dirty = false;
            container.dataset.frames = String(Number(container.dataset.frames ?? 0) + 1);
        }
        requestAnimationFrame(loop);
    };

    // Ovládání: tažení otáčí, kolečko a dva prsty přibližují, klik vybere, dvojklik otevře kartu.
    const pointers = new Map();
    let moved = 0;
    let pinch = null;
    const pick = (event) => {
        const rect = renderer.domElement.getBoundingClientRect();
        const ndc = new THREE.Vector2(((event.clientX - rect.left) / rect.width) * 2 - 1, -((event.clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(ndc, camera);
        const hit = raycaster.intersectObjects([...nodes.values()].filter((n) => !n.off).map((n) => n.mesh), false)[0];
        return hit ? hit.object.userData.id : null;
    };
    const canvas = renderer.domElement;
    canvas.addEventListener('pointerdown', (event) => {
        canvas.setPointerCapture?.(event.pointerId);
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        moved = 0;
        anim = null;
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), distance: view.distance };
        }
    });
    canvas.addEventListener('pointermove', (event) => {
        const prev = pointers.get(event.pointerId);
        if (!prev) {
            const id = pick(event);
            if (id !== hovered) {
                hovered = id;
                canvas.classList.toggle('pointer', id !== null);
                applyHighlight();
            }
            return;
        }
        const dx = event.clientX - prev.x;
        const dy = event.clientY - prev.y;
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        moved += Math.abs(dx) + Math.abs(dy);
        if (pointers.size === 2 && pinch) {
            const [a, b] = [...pointers.values()];
            const d = Math.hypot(a.x - b.x, a.y - b.y);
            if (d > 0) view.distance = Math.min(3000, Math.max(30, pinch.distance * (pinch.d / d)));
        } else {
            view.azimuth += dx * 0.006;
            // Pod vodorovnou rovinu jen s kostkami (spodní stěna se sítí).
            view.polar = Math.min(cubes ? 2.3 : 1.55, Math.max(0.02, view.polar - dy * 0.006));
        }
        dirty = true;
    });
    const release = (event) => {
        const was = pointers.get(event.pointerId);
        pointers.delete(event.pointerId);
        if (pointers.size < 2) pinch = null;
        if (was && moved < 6 && pointers.size === 0) {
            const id = pick(event);
            if (id) options.onSelect?.(id);
        }
    };
    canvas.addEventListener('pointerup', release);
    canvas.addEventListener('pointercancel', (event) => { pointers.delete(event.pointerId); pinch = null; });
    canvas.addEventListener('pointerleave', () => {
        if (hovered !== null) {
            hovered = null;
            canvas.classList.remove('pointer');
            applyHighlight();
        }
    });
    canvas.addEventListener('dblclick', (event) => {
        const id = pick(event);
        if (id) options.onOpen?.(id);
    });
    canvas.addEventListener('wheel', (event) => {
        event.preventDefault();
        anim = null;
        view.distance = Math.min(3000, Math.max(30, view.distance * Math.exp(event.deltaY * 0.0012)));
        dirty = true;
    }, { passive: false });
    labelLayer.addEventListener('click', (event) => {
        const id = event.target?.dataset?.node;
        if (id) options.onSelect?.(id);
    });

    const theme = window.matchMedia?.('(prefers-color-scheme: dark)');
    const onTheme = () => build();
    theme?.addEventListener?.('change', onTheme);

    resize();
    build();
    reset(true);
    requestAnimationFrame(loop);

    const api = {
        select(id) {
            selected = nodes.has(id) ? id : null;
            applyHighlight();
        },
        focus(id) {
            focusNode(id);
        },
        update(next) {
            const same = next.nodes.length === data.nodes.length
                && next.edges.length === data.edges.length
                && next.nodes.every((n) => nodes.has(n.data.id))
                && next.edges.every((e, i) => e.data.id === data.edges[i].data.id);
            data = next;
            if (!same) {
                build();
                return;
            }
            for (const n of next.nodes) {
                const node = nodes.get(n.data.id);
                node.data = n.data;
                if (node.cube !== Boolean(isCube(n.data))) {
                    build();
                    return;
                }
                if (node.cube) {
                    const opacity = node.mesh.material[0].opacity;
                    disposeMaterial(node.mesh.material);
                    node.mesh.material = cubeMaterials(n.data);
                    setOpacity(node.mesh, opacity);
                } else {
                    node.mesh.material.color = statusColor(n.data.status);
                }
                fillLabel(node.label, n.data);
            }
            for (const e of edges) {
                const fresh = next.edges.find((x) => x.data.id === e.data.id);
                if (fresh) e.data = fresh.data;
            }
            applyHighlight();
        },
        reset() {
            reset();
        },
        // Režim problému: cesta (id uzlů a hran) se zvýrazní, kamera se natočí k příčině.
        setPath(nodeIds, edgeIds, focusId) {
            path = nodeIds ? { nodes: new Set(nodeIds), edges: new Set(edgeIds ?? []) } : null;
            applyHighlight();
            if (path && focusId) focusNode(focusId);
        },
        setHiddenFloors(keys) {
            hiddenFloors = new Set(keys);
            applyHighlight();
        },
        topView,
        setCubes(on) {
            cubes = !!on;
            build();
        },
        setSpread(on) {
            spread = !!on;
            build();
            reset();
        },
        resize,
        // Pro testy: poloha uzlu na obrazovce a počty prvků scény.
        screenPosition(id) {
            const n = nodes.get(id);
            return n ? project(n.pos) : null;
        },
        stats() {
            return {
                nodes: nodes.size,
                edges: edges.length,
                floors: floorLabels.map((f) => f.el.textContent),
                selected,
                visibleNodes: [...nodes.values()].filter((n) => !n.off).length,
                path: path ? [...path.nodes].sort() : null,
                cubes: [...nodes.values()].filter((n) => n.cube).map((n) => n.data.id),
            };
        },
        dispose() {
            running = false;
            observer?.disconnect();
            theme?.removeEventListener?.('change', onTheme);
            clear();
            cubeGeometry.dispose();
            cubeMarker.geometry.dispose();
            renderer.dispose();
            container.replaceChildren();
        },
    };
    return api;
}
