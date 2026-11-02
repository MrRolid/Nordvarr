// Nordvarr - 3D pohled na patra (three.js, ES modul bez buildu). Načítá se až po přepnutí na 3D.
// Patra jsou vodorovné disky nad sebou (hardware dole), objekty leží na obvodu disku.
// Úhel na obvodu odpovídá vodorovné poloze z rozvržení pater (Nordvarr\Topology\Floors),
// takže objekty spojené vztahem leží nad sebou ve stejném směru ("sloupec").
// Popisky jsou HTML nad plátnem (ostrý text), polohu nastavuje skript přes CSSOM, což CSP dovoluje.

import * as THREE from '../vendor/three-0.170.0/three.module.min.js';

const STATUS_VARS = { healthy: 'st-healthy', degraded: 'st-degraded', unhealthy: 'st-unhealthy', unknown: 'st-unknown', paused: 'st-paused' };
const NODE_R = 3.2;
const MANY = 60;

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
    const raycaster = new THREE.Raycaster();

    // Stav scény.
    let data = graph;
    let spread = false;
    let nodes = new Map(); // id -> { mesh, label, data, pos }
    let edges = []; // { line, source, target, data }
    let floorLabels = []; // { el, level, radius }
    let radius = 40;
    let levels = 1;
    let hovered = null;
    let selected = null;
    let dirty = true;
    let running = true;

    // Kamera kolem osy válce: azimut, sklon od svislice, vzdálenost, cíl.
    const view = { azimuth: -0.6, polar: 1.15, distance: 200, target: new THREE.Vector3() };
    let anim = null;

    // Rozestup pater podle poloměru, aby se disky při šikmém pohledu nepřekrývaly.
    const floorGap = () => Math.max(40, radius * 0.75) * (spread ? 1.8 : 1);

    const statusColor = (status) => color(STATUS_VARS[status] ?? 'map-node-ring');

    const clear = () => {
        for (const child of [...world.children]) {
            world.remove(child);
            if (child === marker) continue;
            if (child.geometry !== sphere) child.geometry?.dispose?.();
            child.material?.dispose?.();
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
            floorLabels.push({ el, y: y - NODE_R - 1 });
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
            const mesh = new THREE.Mesh(sphere, new THREE.MeshLambertMaterial({ color: statusColor(d.status), transparent: true, opacity: 1 }));
            mesh.position.copy(pos);
            if (d.center) mesh.scale.setScalar(1.35);
            mesh.userData.id = d.id;
            world.add(mesh);
            const label = document.createElement('div');
            label.className = `map3d-label st-${d.status}`;
            label.textContent = d.name;
            label.dataset.node = d.id;
            labelLayer.appendChild(label);
            nodes.set(d.id, { mesh, label, data: d, pos, many });
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
        world.add(marker);
        applyHighlight();
        dirty = true;
    };

    const marker = new THREE.Mesh(
        new THREE.SphereGeometry(NODE_R * 1.7, 20, 14),
        new THREE.MeshBasicMaterial({ color: 0x1f5fa8, wireframe: true, transparent: true, opacity: 0.7 }),
    );
    marker.visible = false;

    // Zvýraznění: vybraný nebo najetý uzel a jeho sousedé, ostatní ztlumené.
    const applyHighlight = () => {
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
            n.mesh.material.opacity = dim && !keep.has(id) ? 0.25 : 1;
        }
        const hl = color('map-center');
        for (const e of edges) {
            const touches = focus !== null && (e.source === focus || e.target === focus);
            e.line.material.color = touches && !e.data.causal ? hl : color(e.data.causal ? 'map-causal' : 'map-edge');
            e.line.material.opacity = dim && !touches ? 0.12 : 0.85;
        }
        const sel = selected ? nodes.get(selected) : null;
        marker.visible = !!sel;
        if (sel) marker.position.copy(sel.pos);
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
            n.label.hidden = !p.visible;
            n.label.style.transform = `translate(${Math.round(p.x)}px, ${Math.round(p.y + NODE_R * 2 + 4)}px) translateX(-50%)`;
        }
        // Popisek patra na levém okraji disku z pohledu kamery.
        const left = new THREE.Vector3(-Math.sin(view.azimuth), 0, Math.cos(view.azimuth)).multiplyScalar(radius + 16);
        for (const f of floorLabels) {
            const p = project(new THREE.Vector3(left.x, f.y, left.z));
            f.el.hidden = !p.visible;
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
        const hit = raycaster.intersectObjects([...nodes.values()].map((n) => n.mesh), false)[0];
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
            view.polar = Math.min(1.55, Math.max(0.02, view.polar - dy * 0.006));
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
                node.mesh.material.color = statusColor(n.data.status);
                node.label.className = `map3d-label st-${n.data.status}`;
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
        topView,
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
            return { nodes: nodes.size, edges: edges.length, floors: floorLabels.map((f) => f.el.textContent), selected };
        },
        dispose() {
            running = false;
            observer?.disconnect();
            theme?.removeEventListener?.('change', onTheme);
            clear();
            renderer.dispose();
            container.replaceChildren();
        },
    };
    return api;
}
