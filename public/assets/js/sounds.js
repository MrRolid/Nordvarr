// Nordvarr - zvukové upozornění v prohlížeči (ES modul, žádné inline skripty).
// Každých 30 s (na pozadí 60 s) se zeptá, co se stalo od posledního dotazu (nové a vyřešené
// alerty, nové události), a přehraje zvuk nejvyšší úrovně: info, warning (oranžová), critical (červená).
// Zvuky se generují přes Web Audio, nastavení a ztlumení platí pro tento prohlížeč (localStorage).

const menu = document.querySelector('[data-sound]');

if (menu) {
    const KEY = 'nordvarr.sound.';
    const store = {
        get(name, fallback = null) {
            try {
                return window.localStorage.getItem(KEY + name) ?? fallback;
            } catch {
                return fallback;
            }
        },
        set(name, value) {
            try {
                window.localStorage.setItem(KEY + name, value);
            } catch {
                // Bez úložiště platí nastavení jen do obnovení stránky.
            }
        },
    };
    const memory = {};
    const read = (name, fallback = null) => memory[name] ?? store.get(name, fallback);
    const write = (name, value) => {
        memory[name] = value;
        store.set(name, value);
    };

    // --- Zvuky ---------------------------------------------------------------
    let audio = null;
    const context = () => {
        if (!audio) {
            const Ctor = window.AudioContext || window.webkitAudioContext;
            if (!Ctor) return null;
            audio = new Ctor();
        }
        if (audio.state === 'suspended') audio.resume().catch(() => {});
        return audio;
    };
    // Prohlížeč pustí zvuk až po interakci uživatele: první klik na stránku zvuk odemkne.
    document.addEventListener('pointerdown', () => context(), { once: true });

    const tone = (ctx, { type = 'sine', freq, to = null, start = 0, length, gain = 0.25, attack = 0.01 }) => {
        const osc = ctx.createOscillator();
        const amp = ctx.createGain();
        const t0 = ctx.currentTime + start;
        osc.type = type;
        osc.frequency.setValueAtTime(freq, t0);
        if (to !== null) osc.frequency.linearRampToValueAtTime(to, t0 + length);
        amp.gain.setValueAtTime(0.0001, t0);
        amp.gain.exponentialRampToValueAtTime(gain, t0 + attack);
        amp.gain.exponentialRampToValueAtTime(0.0001, t0 + length);
        osc.connect(amp).connect(ctx.destination);
        osc.start(t0);
        osc.stop(t0 + length + 0.05);
    };
    const SOUNDS = {
        // Dva stoupající tóny: klidné "dobré vědět".
        chime: (ctx) => {
            tone(ctx, { freq: 660, length: 0.35, gain: 0.2 });
            tone(ctx, { freq: 990, start: 0.18, length: 0.5, gain: 0.2 });
        },
        // Zvonek: základní tón s vyšší harmonickou, dlouhý dozvuk.
        bell: (ctx) => {
            tone(ctx, { freq: 880, length: 1.4, gain: 0.25 });
            tone(ctx, { freq: 1760, length: 0.8, gain: 0.08 });
        },
        // Dvojité pípnutí: upozornění.
        beep2: (ctx) => {
            tone(ctx, { type: 'square', freq: 1000, length: 0.13, gain: 0.12 });
            tone(ctx, { type: 'square', freq: 1000, start: 0.2, length: 0.13, gain: 0.12 });
        },
        // Gong: hluboký tón.
        gong: (ctx) => {
            tone(ctx, { type: 'triangle', freq: 196, length: 2.2, gain: 0.35 });
            tone(ctx, { freq: 294, length: 1.6, gain: 0.12 });
        },
        // Siréna: dvakrát stoupající a klesající tón, naléhavé.
        siren: (ctx) => {
            for (let i = 0; i < 2; i++) {
                tone(ctx, { type: 'sawtooth', freq: 600, to: 1200, start: i * 0.9, length: 0.45, gain: 0.08 });
                tone(ctx, { type: 'sawtooth', freq: 1200, to: 600, start: i * 0.9 + 0.45, length: 0.45, gain: 0.08 });
            }
        },
        none: () => {},
    };
    const selects = [...menu.querySelectorAll('[data-sound-level]')];
    const choice = (level) => {
        const select = selects.find((s) => s.dataset.soundLevel === level);
        return read(`choice.${level}`, select?.dataset.default ?? 'none');
    };
    const play = (level) => {
        const kind = choice(level);
        // Pro testy a ladění: co se naposledy hrálo.
        menu.dataset.lastPlayed = `${level}:${kind}`;
        menu.dataset.played = String(Number(menu.dataset.played ?? 0) + 1);
        const ctx = context();
        if (ctx && SOUNDS[kind]) SOUNDS[kind](ctx);
    };

    // --- Ztlumení ------------------------------------------------------------
    const stateEl = menu.querySelector('[data-sound-state]');
    const waves = menu.querySelector('[data-sound-waves]');
    const cross = menu.querySelector('[data-sound-cross]');
    const labels = { on: stateEl?.textContent ?? '' };
    const mutedUntil = () => {
        const value = read('muted', '0');
        if (value === 'forever') return Infinity;
        const until = Number(value) || 0;
        if (until > 0 && until <= Date.now()) {
            write('muted', '0');
            return 0;
        }
        return until;
    };
    const isMuted = () => mutedUntil() > Date.now();
    const renderState = () => {
        const until = mutedUntil();
        const muted = until > Date.now();
        menu.dataset.muted = muted ? (until === Infinity ? 'forever' : 'until') : 'no';
        if (waves) waves.hidden = muted;
        if (cross) cross.hidden = !muted;
        if (stateEl) {
            if (!muted) {
                stateEl.textContent = labels.on;
            } else if (until === Infinity) {
                stateEl.textContent = menu.dataset.textOff;
            } else {
                const time = new Date(until).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                stateEl.textContent = (menu.dataset.textUntil ?? '').replace('{time}', time);
            }
        }
        for (const button of menu.querySelectorAll('[data-sound-action]')) {
            const action = button.dataset.soundAction;
            const active = (action === 'on' && !muted) || (action === 'off' && until === Infinity) || (action === 'hour' && muted && until !== Infinity);
            button.setAttribute('aria-pressed', String(active));
        }
    };
    for (const button of menu.querySelectorAll('[data-sound-action]')) {
        button.addEventListener('click', () => {
            const action = button.dataset.soundAction;
            write('muted', action === 'off' ? 'forever' : action === 'hour' ? String(Date.now() + 3600 * 1000) : '0');
            renderState();
        });
    }
    for (const select of selects) {
        select.value = choice(select.dataset.soundLevel);
        select.addEventListener('change', () => {
            write(`choice.${select.dataset.soundLevel}`, select.value);
            play(select.dataset.soundLevel);
        });
    }
    for (const button of menu.querySelectorAll('[data-sound-test]')) {
        button.addEventListener('click', () => play(button.dataset.soundTest));
    }
    renderState();
    // Ztlumení na hodinu se po uplynutí zruší samo (i bez obnovení stránky).
    setInterval(renderState, 30000);

    // --- Dotazy na novinky ---------------------------------------------------
    const toast = document.querySelector('[data-sound-toast]');
    let toastTimer = null;
    const showToast = (item, count) => {
        if (!toast) return;
        toast.className = `sound-toast sound-toast-${item.level}`;
        toast.textContent = count > 1 ? `${item.text} (+${count - 1})` : item.text;
        toast.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toast.hidden = true; }, 10000);
    };
    const RANK = { info: 0, warning: 1, critical: 2 };
    let timer = null;
    const poll = async () => {
        try {
            const since = read('since', '');
            const url = new URL(menu.dataset.pulseUrl, window.location.href);
            if (since) url.searchParams.set('since', since);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if (response.ok && (response.headers.get('Content-Type') ?? '').includes('application/json')) {
                const data = await response.json();
                // Čas se sdílí mezi kartami prohlížeče, aby se stejná novinka nehrála vícekrát.
                if (read('since', '') === since) write('since', data.now);
                const items = (data.items ?? []).filter((i) => RANK[i.level] !== undefined);
                if (since && items.length > 0) {
                    const top = items.reduce((a, b) => (RANK[b.level] > RANK[a.level] ? b : a));
                    showToast(top, items.length);
                    if (!isMuted()) play(top.level);
                }
                menu.dataset.polled = String(Number(menu.dataset.polled ?? 0) + 1);
            }
        } catch {
            // Výpadek spojení: zkusí se znovu.
        }
        clearTimeout(timer);
        timer = setTimeout(poll, document.hidden ? 60000 : Number(menu.dataset.interval ?? 30000));
    };
    poll();

    window.nordvarrSound = { play, poll, isMuted };
}
