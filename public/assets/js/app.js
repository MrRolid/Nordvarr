// Nordvarr - společný skript stránek (ES modul bez buildu, žádné inline skripty).

document.documentElement.classList.add('js');

// Formuláře s výběrem, které se mají odeslat hned po změně (např. přepínač jazyka).
for (const select of document.querySelectorAll('select[data-autosubmit]')) {
    select.addEventListener('change', () => {
        select.form?.requestSubmit();
    });
}
