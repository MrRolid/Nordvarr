# Stav práce

Aktualizováno: 2026-10-20

## Hotové fáze

### F0 Kostra (větev `faze-0-kostra`)

- Struktura repozitáře podle SPEC kap. 4, vlastní PSR-4 autoloader (`src/autoload.php`), bez runtime závislostí.
- Front controller `public/index.php`, router s parametry, hezké adresy přes `public/.htaccess` a fallback `index.php?r=...`.
- PHP šablony s povinným escapováním (`View::e()`, `View::t()`), layout CZ/EN, na mobilu spodní navigační lišta.
- Překlady `lang/cs.php` a `lang/en.php`, test hlídá úplnost a shodu parametrů.
- SQLite ve WAL přes PDO, vrstva repozitářů, číslované migrace se zálohou (`VACUUM INTO`) před spuštěním. Migrace se spouští při prvním požadavku přihlášeného admina nebo příkazem `php bin/nordvarr.php migrate`.
- Instalátor `public/install.php`: kontrola PHP a rozšíření, datový adresář (výchozí `nordvarr-data` vedle aplikace), `.htaccess` v datovém adresáři, databáze, admin, `config.php` s náhodným tajemstvím, řádek pro cron, kontrola dostupnosti dat z webu, zablokování po instalaci.
- Přihlášení a odhlášení: Argon2id, nové id session po přihlášení, CSRF u každého POST, omezení pokusů (zpoždění od 5 selhání, zámek 15 min od 10 selhání, podle účtu i IP), obecná chybová hláška, zneplatnění session po změně hesla, audit přihlášení a odhlášení.
- Bezpečnostní hlavičky (CSP bez inline skriptů, X-Frame-Options, Referrer-Policy, nosniff), UI odmítá bearer tokeny.
- CLI `bin/nordvarr.php` (`worker` jako zástupce se zámkem, `migrate`, `version`).
- `bin/build-release.sh` -> `dist/nordvarr-<verze>.zip` s adresářem `nordvarr/`, bez `config.php`, dat a vývojových souborů.
- Testy: PHPUnit (unit + integration, `composer test`), Playwright (`npm run e2e`), CI v `.github/workflows/ci.yml` (testy při pushi, release zip při tagu `v*`).
- `scripts/setup-cloud.sh` pro přípravu cloud VM.

Akceptace F0 ověřená testy:

- čistá instalace do prázdného adresáře přes `install.php`: e2e `install.spec.js` (instaluje z rozbaleného zipu),
- přihlášení a odhlášení: e2e `app.spec.js`, integrace `AppTest`,
- sestavený zip jde po rozbalení do jiného adresáře znovu nainstalovat: e2e sestaví zip a instaluje z něj do nového dočasného adresáře, `ReleaseBuildTest` hlídá obsah zipu,
- testy zelené: `composer test` (128 testů), `npm run e2e` (8 testů).

## Rozpracované

Nic.

## Známé problémy a poznámky

- V cloud VM není přístup na PPA ondrej/php (proxy vrací 403 pro `ppa.launchpadcontent.net`). `scripts/setup-cloud.sh` proto jako zálohu bere PHP 8.4 z oficiálního Docker obrazu (`mirror.gcr.io/library/php:8.4-cli`) a pouští ho nativně přes patchelf. Docker Hub (`registry-1.docker.io`) vracel 429, mirrory fungují. Pokud by se povolil přístup na `keyserver.ubuntu.com` a `ppa.launchpadcontent.net`, použije skript standardní balíčky.
- Composer v cloud VM stahuje balíčky přes git (API GitHubu `api.github.com` i `codeload.github.com` vrací 403), funguje to, jen pomaleji.
- Kontrola dostupnosti datového adresáře z webu dělá HTTP požadavek serveru na sebe sama; na hostingu, kde server nevidí sám sebe, zůstane jen obecné varování.
- Testovaný běh je na vestavěném serveru PHP; na skutečném Apache se `.htaccess` (mod_rewrite, proměnná `NORDVARR_REWRITE`) zatím neověřoval.

## Další krok

F1 Inventář: objekty, externí id, atributy s rozpory, tagy, vztahy, dokumentace s revizemi, import a export JSON, API tokeny, upsert API, events API, časová osa, skript s ukázkovými daty.
