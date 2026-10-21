// Nordvarr - společný skript stránek (ES modul bez buildu, žádné inline skripty).

document.documentElement.classList.add('js');

// Formuláře s výběrem, které se mají odeslat hned po změně (např. přepínač jazyka).
for (const select of document.querySelectorAll('select[data-autosubmit]')) {
    select.addEventListener('change', () => {
        select.form?.requestSubmit();
    });
}

// Formulář kontroly: zobrazí jen pole zvoleného typu a doplní výchozí interval a práh.
const checkForm = document.querySelector('form[data-check-form]');
if (checkForm) {
    const typeSelect = checkForm.querySelector('select[data-check-type]');
    const showFields = () => {
        const type = typeSelect ? typeSelect.value : null;
        for (const fieldset of checkForm.querySelectorAll('fieldset[data-type-fields]')) {
            const active = type === null || fieldset.dataset.typeFields === type;
            fieldset.hidden = !active;
            fieldset.disabled = !active;
        }
    };
    if (typeSelect) {
        typeSelect.addEventListener('change', () => {
            const option = typeSelect.selectedOptions[0];
            const interval = checkForm.querySelector('#interval_s');
            const threshold = checkForm.querySelector('#failure_threshold');
            const notify = checkForm.querySelector('#notify_on_degraded');
            if (option && interval) interval.value = option.dataset.interval;
            if (option && threshold) threshold.value = option.dataset.threshold;
            if (option && notify) notify.checked = option.dataset.notify === '1';
            showFields();
        });
    }
    showFields();
}

// Obnovování částí stránky (přehled, diagnostická karta objektu) dotazem na JSON s ETag.
// Aktivní karta každých 15 s, karta na pozadí každých 60 s, při chybě postupné
// prodlužování až na 5 minut.
for (const box of document.querySelectorAll('[data-refresh-url]')) {
    const ACTIVE = 15000;
    const BACKGROUND = 60000;
    const MAX_BACKOFF = 300000;
    let etag = null;
    let failures = 0;
    let timer = null;

    const delay = () => {
        const base = document.hidden ? BACKGROUND : ACTIVE;
        return failures === 0 ? base : Math.min(MAX_BACKOFF, base * 2 ** failures);
    };
    const schedule = (ms = delay()) => {
        clearTimeout(timer);
        timer = setTimeout(refresh, ms);
    };
    const refresh = async () => {
        try {
            const headers = { Accept: 'application/json' };
            if (etag) headers['If-None-Match'] = etag;
            const response = await fetch(box.dataset.refreshUrl, { headers, credentials: 'same-origin', cache: 'no-store' });
            if (response.status === 200) {
                const data = await response.json();
                box.innerHTML = data.html;
                etag = response.headers.get('ETag');
            } else if (response.status !== 304) {
                throw new Error(`HTTP ${response.status}`);
            }
            failures = 0;
            box.dataset.refreshedAt = String(Date.now());
        } catch {
            failures += 1;
        }
        schedule();
    };
    document.addEventListener('visibilitychange', () => {
        // Po návratu na kartu obnovit hned, na pozadí jen přeplánovat.
        schedule(document.hidden ? delay() : 0);
    });
    schedule();
}

// Tlačítko Kopírovat (export pro LLM): navigator.clipboard, jinak označí text k ručnímu zkopírování.
for (const button of document.querySelectorAll('[data-copy-target]')) {
    const field = document.getElementById(button.dataset.copyTarget);
    const status = document.querySelector(`[data-copy-status="${button.dataset.copyTarget}"]`);
    if (!field) continue;
    button.addEventListener('click', async () => {
        try {
            if (!navigator.clipboard) throw new Error('clipboard');
            await navigator.clipboard.writeText(field.value);
            if (status) status.textContent = button.dataset.copied;
        } catch {
            field.focus();
            field.select();
            if (status) status.textContent = button.dataset.copyFailed;
        }
    });
}
