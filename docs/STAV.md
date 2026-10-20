# Stav práce

Aktualizováno: 2026-10-21

## Hotové fáze

### F3 Alerty a notifikace (větev `faze-3-alerty`, navazuje na `faze-2-kontroly`)

- Migrace `0004_alerty.sql`: alerty (jeden otevřený na kontrolu), kanály a jejich pravidla, fronta odesílání s historií pokusů, údržbová okna.
- Alerty: otevření při `unhealthy` (u `degraded` jen s `notify_on_degraded`), uzavření a notifikace o obnovení při návratu do `healthy`, připomínky (výchozí 24 h, 0 = vypnuto).
- Potlačení o jeden krok (`depends_on` před `contains`, pak dřív otevřený alert, pak menší id; cykly nevadí). U potlačeného alertu je vidět pravděpodobná příčina: seznam alertů, detail alertu, hlavička objektu, přehled.
- Údržbová okna (objekt nebo tag, volitelně s pozastavením kontrol). Během okna se nic neposílá, po jeho konci odejde za každý stále otevřený alert nejvýš jedna notifikace.
- Kanály se společným rozhraním `Nordvarr\Notify\Channel`:
  - e-mail: vlastní SMTP klient se SSL/STARTTLS, AUTH LOGIN/PLAIN, primární a záložní server,
  - Telegram,
  - webhook s `X-Nordvarr-Signature` (HMAC-SHA256),
  - pravidla podle tagů, tajné údaje zašifrované, tlačítko "Odeslat test".
- Fronta `deliveries` s opakováním po 1, 5, 15 a 60 minutách, potom `failed`; historie pokusů v UI.
- UI:
  - stránka Alerty (filtr, detail s doručeními, údržbová okna, odeslané notifikace),
  - nastavení kanálů a notifikací (připomínky, adresa aplikace, jazyk notifikací).
- Worker po kontrolách vyhodnotí alerty a odešle frontu (v rámci limitu 50 s).

Ověření:

- logika alertů (otevření, obnovení, degraded, potlačení přes závislost i obsažení, jen o jeden krok, pořadí kandidátů, cykly, údržba, připomínky, pravidla kanálů, opakování doručení, smazaná a vypnutá kontrola): `AlertEngineTest`,
- e-mail proti lokálnímu falešnému SMTP serveru (STARTTLS + AUTH PLAIN, implicitní TLS + AUTH LOGIN, ověření certifikátu, záložní server, oba servery nedostupné, odmítnutý příjemce, zákaz přihlášení bez šifrování), Telegram a webhook proti lokálnímu mock endpointu: `ChannelsTest`,
- UI kanálů, testovací odeslání, údržbová okna, stránka alertů a nastavení: `AlertsUiTest`; celý řetězec worker -> alert -> e-mail přes CLI: `CliTest`,
- e2e `alerts.spec.js`: webhook kanál s testem a ověřením podpisu, výpadek databáze (notifikace jen o databázi, web s pravděpodobnou příčinou), údržbové okno,
- testy zelené: `composer test` (375 testů), `npm run e2e` (20 testů).

### F2 Kontroly (větev `faze-2-kontroly`, navazuje na `faze-1-inventar`)

- Migrace `0003_kontroly.sql`: kontroly, syrové výsledky (retence 14 dní), hodinové agregace (retence 400 dní), aktuální stav, cache HTTP odpovědí.
- Všech 6 typů kontrol:
  - `http`: GET/HEAD, očekávané kódy, hledaný text, limit latence, max. 5 přesměrování, max. 1 MB, ověření TLS,
  - `tcp`, `tls_expiry` (prahy 21/7 dní),
  - `ping` přes systémový příkaz (`tool_missing`, když chybí nebo nemá oprávnění),
  - `domain_expiry` přes RDAP s bootstrapem IANA v cache 7 dní,
  - `heartbeat` s adresou `/hb/<token>` (GET i POST).
- Stavy, kódy důvodů a detail (max. 300 znaků). Přechod do horšího stavu až po nastaveném počtu selhání, návrat po prvním úspěchu, pozdní výsledek jen do historie. Stav objektu je nejhorší stav jeho kontrol, archivovaný objekt a vypnutá kontrola jsou `paused`.
- Worker (`php bin/nordvarr.php worker`): zámek, limit 50 s, `http`/`tcp`/`tls_expiry` paralelně přes `curl_multi` (max. 20), ping paralelně, RDAP postupně. Jednou za hodinu agregace a retence, zápis času posledního běhu.
- UI:
  - přehled: souhrn stavů, nefunguje teď, změny za 24 h, brzy vyprší, varování o neběžícím workeru; obnovování přes JSON s ETag (15 s / 60 s / zpomalení při chybách),
  - kontroly na detailu objektu: přidání, úprava, pozastavení, spuštění při dalším běhu, smazání s auditem,
  - detail kontroly: posledních 50 výsledků, hodinové souhrny za 48 h, souhrn za 24 h, adresa heartbeatu,
  - stav a důvod v hlavičce objektu, stav závislostí, sloupec a filtr stavu v inventáři.
- Kontroly jsou součástí exportu a importu inventáře.

Ověření (SPEC F2: testy proti lokálním fixture serverům, RDAP z fixture JSON, bez síťových volání):

- HTTP 200, 500, 404, pomalá odpověď (latence i timeout), přesměrování (3 v pořádku, 7 příliš mnoho), zavřený port, chybějící text, odpověď nad 1 MB, nedůvěryhodný certifikát: `CurlRunnerTest`,
- TLS certifikát s blízkou expirací (90, 10 a 3 dny) a nedostupný TLS port: `CurlRunnerTest`,
- RDAP z fixture JSON (platná, brzy vyprší, vypršelá, bez data expirace, 404, cache bootstrapu, nejdelší přípona): `RdapRunnerTest`,
- ping přes falešný příkaz (odpověď, bez odpovědi, DNS, oprávnění, zaseknutý proces, chybějící příkaz): `PingRunnerTest`,
- worker, prahy, pozastavení, heartbeat, agregace a retence: `WorkerTest`, `StateMachineTest`, CLI `CliTest`,
- UI a přehled s ETag: `ChecksUiTest`, e2e `checks.spec.js` (worker spuštěný přes CLI nad nainstalovaným zipem),
- testy zelené: `composer test` (306 testů), `npm run e2e` (16 testů).

### F1 Inventář (větev `faze-1-inventar`, navazuje na `faze-0-kostra`)

- Migrace `0002_inventar.sql`: objekty, externí id, atributy (se zdrojem a příznakem JSON), vztahy (`depends_on`, `contains`), tagy, dokumentace s neměnnými revizemi, události, API tokeny, limity API.
- UI:
  - inventář s filtrem (text, typ, tag, aktivní/archivované) a hromadným přidáním tagu,
  - detail objektu: atributy s vyznačeným rozporem (ruční hodnota platí), tagy, externí id, vztahy v obou směrech s kontrolou cyklu u `contains`, dokumentace v bezpečném Markdownu s revizemi, časová osa objektu a přímých sousedů, ruční události, archivace a mazání,
  - nastavení: API tokeny (zobrazení jen jednou, zneplatnění), export a import inventáře v JSON, časová zóna, řádek pro cron.
- API: `POST /api/v1/objects/upsert` (idempotentní, 422 se seznamem chybějících external_id, odpověď s přehledem změn), `POST /api/v1/events` (idempotentní), bearer tokeny se scope, limit 120 požadavků za minutu, bez session a cookies.
- Vlastní bezpečný renderer Markdownu (`src/View/Markdown.php`), maskování tajných údajů v exportu (`src/Security/SecretMasker.php`).
- Ukázková data: `php bin/nordvarr.php demo-data` (40 fiktivních objektů, 8 událostí).
- Audit: tokeny, mazání objektů, atributů a vztahů, změna časové zóny, export a import.

Akceptace F1 ověřená testy:

- opakovaný upsert stejného vstupu nic nezduplikuje: `UpsertTest`, `ApiTest`, e2e `inventory.spec.js` (přes skutečné HTTP),
- událost se zobrazí na časové ose objektu i jeho souseda: `EventIngestTest`, `InventoryUiTest`, e2e,
- export a import inventáře dá stejný výsledek: `TransferTest` (ukázková data + kanárková tajemství), `InventoryUiTest`, e2e přes stažení a nahrání souboru,
- testy zelené: `composer test` (222 testů), `npm run e2e` (13 testů).

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
- testy zelené (stav na konci F0): `composer test` (128 testů), `npm run e2e` (8 testů).

## Rozpracované

Nic.

## Známé problémy a poznámky

- V cloud VM není přístup na PPA ondrej/php (proxy vrací 403 pro `ppa.launchpadcontent.net`). `scripts/setup-cloud.sh` proto jako zálohu bere PHP 8.4 z oficiálního Docker obrazu (`mirror.gcr.io/library/php:8.4-cli`) a pouští ho nativně přes patchelf. Docker Hub (`registry-1.docker.io`) vracel 429, mirrory fungují. Pokud by se povolil přístup na `keyserver.ubuntu.com` a `ppa.launchpadcontent.net`, použije skript standardní balíčky.
- Composer v cloud VM stahuje balíčky přes git (API GitHubu `api.github.com` i `codeload.github.com` vrací 403), funguje to, jen pomaleji.
- Kontrola dostupnosti datového adresáře z webu dělá HTTP požadavek serveru na sebe sama; na hostingu, kde server nevidí sám sebe, zůstane jen obecné varování.
- Testovaný běh je na vestavěném serveru PHP; na skutečném Apache se `.htaccess` (mod_rewrite, proměnná `NORDVARR_REWRITE`, předání hlavičky Authorization pro API) zatím neověřoval.
- Po nahrání nové verze se migrace spustí až při prvním požadavku přihlášeného admina (SPEC kap. 12). Do té doby API vrací 500, protože nové tabulky ještě neexistují. Řešení: po aktualizaci se přihlásit nebo spustit `php bin/nordvarr.php migrate`.
- Seznam objektů ve výběru cíle vztahu na detailu objektu obsahuje všechny objekty; pro cílovou velikost (nízké stovky) to stačí, pro víc objektů by bylo potřeba vyhledávání.
- Správa uživatelů v nastavení zatím chybí (viz ROZHODNUTI F1 bod 14).
- Detail chyby u síťových kontrol přebírá anglický text z knihovny curl (např. "Failed to connect ..."); kód důvodu je vždy z pevného seznamu.
- Souhrn za 24 h v detailu kontroly se počítá z hodinových agregací, aktuální hodina se v něm objeví až po hodinové údržbě workeru.
- Kontrola `tls_expiry` podporuje jen přímé TLS (např. 443, 465, 993), ne STARTTLS.
- Chyba DNS u kontrol přes curl není pokrytá automatickým testem (vyžadovala by skutečný DNS dotaz).
- Alerty se vyhodnocují při běhu workeru, takže signál heartbeatu nebo ruční změna se do alertů a notifikací promítne nejpozději do minuty.
- Skutečné odeslání přes veřejné SMTP servery, Telegram a cizí webhooky se netestovalo (jen lokální falešné servery); na produkci doporučuji po nastavení kanálu použít "Odeslat test".
- Změna `app_secret` v `config.php` znemožní dešifrovat tajné údaje kanálů, je pak potřeba je zadat znovu.

## Další krok

F4 Topologie a diagnostická karta: Cytoscape graf (okolí objektu, filtrový režim), hlavička diagnózy, obnovování přes ETag. Akceptace: Playwright scénář "spadne databáze -> web je unhealthy, notifikace webu je potlačena, karta webu ukazuje databázi jako pravděpodobnou příčinu a poslední deploy v okolí".
