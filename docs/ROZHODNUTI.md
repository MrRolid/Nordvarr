# Rozhodnutí

Rozhodnutí odlišná od SPEC.md nebo taková, která SPEC nechává otevřená.

## 2026-10-20 (F0 Kostra)

1. **Migrace po fázích.** Migrace `0001_zaklad.sql` vytváří jen tabulky, které F0 potřebuje (`users`, `login_attempts`, `audit_log`, `settings`, `schema_migrations`). Ostatní tabulky datového modelu přidají migrace fází, které je používají. Důvod: schéma se ověří kódem, který ho používá, a zároveň se tím reálně testuje aktualizace přes migrace.
2. **Hezké adresy se zapínají samy.** `public/.htaccess` při aktivním mod_rewrite nastaví proměnnou `NORDVARR_REWRITE=1` a aplikace pak generuje adresy `/login`. Bez mod_rewrite generuje `index.php?r=/login`. Router přijímá oba tvary vždy. Nic se nenastavuje ručně.
3. **Omezení pokusů o přihlášení.** Počítají se neúspěšné pokusy od posledního úspěchu za poslední hodinu, zvlášť pro e-mail a pro IP. Od 5. selhání musí mezi pokusy uplynout 1, 2, 4, 8, 16 s (místo `sleep()`, které by blokovalo PHP proces), od 10. selhání je zámek 15 minut od posledního selhání. Odmítnutý pokus během zámku se nezapočítává, aby se zámek neprodlužoval donekonečna. Odpověď je HTTP 429 s obecnou hláškou. Neexistující účet se ověřuje proti náhradnímu hashi, aby nešel poznat podle času odpovědi.
4. **Zneplatnění session po změně hesla** přes sloupec `users.session_version`: změna hesla ho zvýší a každá session s jinou hodnotou se zahodí.
5. **Session soubory** jsou v `data_dir/sessions` (mimo webroot, nezávislé na nastavení hostingu). Instalátor, který ještě nezná datový adresář, používá výchozí úložiště session PHP.
6. **Volba jazyka** se ukládá do cookie `nordvarr_lang` (formulář s CSRF). Uživatelské nastavení jazyka v DB zatím není potřeba.
7. **Minimální délka hesla** admina je 10 znaků.
8. **Datový adresář nesmí být uvnitř adresáře aplikace** (aktualizace nahráním souborů by ho mohla přepsat). Do datového adresáře se kromě `.htaccess` dává i prázdný `index.html`. V kořeni aplikace je navíc `.htaccess` se zákazem přístupu (pojistka, když se do webrootu nahraje celý adresář `nordvarr/`, ne jen `public/`).
9. **Kontrola dostupnosti datového adresáře z webu.** Pokud leží mimo DOCUMENT_ROOT, je v pořádku. Pokud leží uvnitř, instalátor vždy varuje a navíc zkusí soubor databáze stáhnout přes HTTP; když se to podaří, zobrazí důraznější varování.
10. **`config.php` vzniká jako poslední krok instalace** a zapisuje se v režimu `x` (selže, pokud mezitím vznikl). Jeho existence instalátor blokuje.
11. **Worker v F0** jen drží zámek proti souběhu a zapisuje čas posledního běhu do `settings.worker_last_run`, aby řádek pro cron z instalátoru fungoval od začátku. Kontroly přidá F2.
12. **Položky navigace** (Inventář, Topologie, Alerty, Nastavení) vedou v F0 na zástupné stránky s informací, ve které fázi přibudou. Díky tomu jde ověřit layout i mobilní spodní lištu.
13. **`bin/dev-router.php`** nahrazuje `.htaccess` pro vestavěný server PHP (vývoj a e2e testy). Do release zipu se nedává, stejně jako `bin/build-release.sh`.
14. **Řádek pro cron** používá `php` z PATH, protože `PHP_BINARY` je pod PHP-FPM cesta k FPM, ne k CLI. Cesta ke skriptu se do uvozovek dává jen tehdy, když obsahuje neobvyklé znaky.
15. **Knihovny JS** budou v `public/assets/vendor/` (CLAUDE.md píše `assets/vendor/`, SPEC má webroot `public/`). Seznam verzí bude v `public/assets/vendor/VERSIONS.md`. V F0 žádná knihovna není.
16. **Testovací nástroje:** PHPUnit 12, Playwright `@playwright/test` 1.56.1 (shoda s Chromiem předinstalovaným v cloud VM). CI pouští PHPUnit na PHP 8.4 i 8.5, e2e na 8.4. E2E testy sestaví zip, rozbalí ho do prázdného dočasného adresáře a instalují z něj.
17. **Verze** v souboru `VERSION` je během vývoje `0.1.0-dev`, release 0.1.0 je až v F5.
