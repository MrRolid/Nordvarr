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

## 2026-10-20 (F1 Inventář)

1. **Upsert jako úplný stav zdroje.** Když vstup upsertu obsahuje klíč `attributes`, bere se jako kompletní sada atributů daného zdroje: atributy tohoto zdroje, které ve vstupu chybí, se smažou. Totéž platí pro `relationships` (odchozí vztahy objektu od tohoto zdroje). Když klíč ve vstupu chybí, nic se nemaže. Tagy se jen přidávají, protože jsou sdílené s ručními úpravami. Ruční atributy a vztahy (zdroj `manual`) upsert nikdy nemaže.
2. **Cíle vztahů v upsertu** se hledají podle `to_external_id` jen v rámci zdroje tokenu. Odkaz na neexistující objekt vrátí 422 se seznamem `missing_external_ids` a nic se nezapíše (celý upsert běží v transakci).
3. **Vztah je unikátní podle (od, do, druh)** bez ohledu na zdroj. Když stejný vztah už existuje od jiného zdroje, upsert ho nezdvojí ani nesmaže.
4. **Události** musí patřit aspoň k jednomu objektu. Objekty se odkazují přes `external_id` (v rámci zdroje tokenu) nebo přes interní `id`. Stejné `event_id` z jiného zdroje je jiná událost. Opakované odeslání vrátí 200 a existující id, obsah se nemění.
5. **Časová osa objektu** ukazuje jeho události a události přímých sousedů (oba směry, oba druhy vztahů), nejnovějších 50. U události souseda je vidět, ke kterému sousedovi patří.
6. **Ruční události z UI** (zdroj `manual`): SPEC je výslovně nezmiňuje, ale bez nich by časová osa šla plnit jen přes API.
7. **Export** obsahuje objekty, externí id, atributy, tagy, vztahy, dokumentaci s revizemi a události. Neobsahuje uživatele, tokeny, audit ani nastavení. Tajné údaje se maskují (`***`) podle klíče atributu a v URL/DSN; po importu takového exportu zůstanou zamaskované. Maskování řetězců s vysokou entropií a kanárkové testy pro export pro LLM jsou v F5.
8. **Import nahradí celý inventář** (ne slučování), vyžaduje potvrzení a předtím se udělá záloha databáze do `backups/`. Neplatný soubor nic nezmění (transakce). Limit 20 MB, na hostingu ho může dál omezit `upload_max_filesize`.
9. **API tokeny** mají tvar `nv_<12 hex>_<48 hex>`, ukládá se prefix a HMAC-SHA256 s tajemstvím aplikace (změna `app_secret` tokeny zneplatní). Zdroje `manual` a `import` jsou pro tokeny vyhrazené.
10. **Limit 120 požadavků za minutu** se počítá v pevném minutovém okně (tabulka `api_rate_limits`). Překročení vrací 429 s `Retry-After`.
11. **API přijímá jen JSON** (`Content-Type: application/json`, max. 1 MB). Session se pro `/api/` vůbec nestartuje, cookies se ignorují.
12. **Atributy** ukládají text, nebo JSON (pole, čísla, logické hodnoty) s příznakem `is_json`. Při rozporu platí ruční hodnota; bez ruční hodnoty se jako platná neoznačí žádná.
13. **Filtr podle stavu** v inventáři přibude v F2 spolu s kontrolami (stav zatím neexistuje). Místo něj je filtr aktivní/archivované.
14. **Správa uživatelů** v nastavení zatím není (F1 ji nezmiňuje, MVP má jednoho admina z instalátoru). Role `viewer` je připravená: nesmí odeslat žádný POST kromě přihlášení, odhlášení a jazyka.
15. **Mazání objektu** je fyzické (kaskádově atributy, vztahy, dokumentace, vazby událostí), vyžaduje potvrzení a zapisuje se do auditu. Pro běžné vyřazení slouží archivace.
16. **Ukázková data** nahrává `php bin/nordvarr.php demo-data` přes upsert se zdrojem `demo` (40 objektů, 8 událostí, ukázkový rozpor atributu). Do inventáře s jinými objekty jen s `--force`.
17. **Markdown v dokumentaci** posouvá úrovně nadpisů o jednu (`#` -> `h2`), protože `h1` stránky je název objektu.
