# Nordvarr - specifikace štíhlého MVP

Verze 1.0, 20. 10. 2026. Nahrazuje plán z 11. 9. 2026 (ten se dál nepoužívá).

## 1. Účel

Nordvarr je nástroj pro jednoho správce nebo malý tým, který potřebuje mít na jednom místě:

- co provozuje (servery, VM, služby, weby, databáze, domény, síťová zařízení),
- co na čem závisí,
- jestli to právě běží,
- co se naposledy změnilo,
- jak se to spravuje (dokumentace přímo u objektu).

Návrh vychází z incidentu. Když něco spadne, má jeden pohled odpovědět na tři otázky: co spadlo a proč, co se v okolí naposledy měnilo, na čem to závisí.

Cílová velikost: desítky až nízké stovky objektů, stovky kontrol. Jedna instalace = jedna organizace.

## 2. Principy

1. Jednoduchost před obecností. Žádné registry šablon, autoritní třídy, víceorganizační režim ani abstrakce "pro budoucnost", pokud je tato specifikace výslovně nežádá.
2. Instalace a aktualizace jako u WordPressu: nahrát soubory, otevřít v prohlížeči. Na serveru žádný Composer, Node ani build krok.
3. Konfigurační soubory se v balíčku dodávají jen jako `.example`. Nová verze nikdy nepřepíše `config.php`.
4. Nordvarr nikdy nevolá LLM. Umí jen připravit kontext ke zkopírování.
5. Tajné údaje (hesla, tokeny, privátní klíče, DSN s heslem) do atributů ani dokumentace nepatří. Při exportu se navíc vždy maskují.
6. Všechna ukázková a testovací data používají fiktivní jména (`example.com`, `example.net`) a dokumentační IP rozsahy (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`, `2001:db8::/32`).

## 3. Stack

- PHP 8.4 a 8.5 (produkce: Debian 13, Apache 2.4, PHP 8.5-FPM). Kód nesmí používat nic novějšího než PHP 8.4.
- Bez frameworku a bez runtime závislostí z Composeru. Composer slouží jen ve vývoji pro PHPUnit, adresář `vendor/` se do balíčku nedává.
- Rozšíření PHP: pdo_sqlite, curl, openssl, json, mbstring.
- Databáze SQLite 3 v režimu WAL přes PDO. SQL psát přenositelně a k databázi přistupovat jen přes vrstvu repozitářů, aby šel později doplnit PostgreSQL. PostgreSQL ovladač se v MVP neimplementuje.
- Frontend: HTML generované na serveru + vanilla JavaScript jako ES moduly bez buildu. Žádné inline skripty (kvůli CSP), žádné CDN.
- Graf: Cytoscape.js, rozvržení cytoscape-dagre + dagre. Pevné verze uložené v `assets/vendor/`, seznam verzí, zdrojů a licencí v `assets/vendor/VERSIONS.md`.
- Worker: PHP CLI příkaz `php bin/nordvarr.php worker` spouštěný cronem každou minutu.
- Testy: PHPUnit (`composer test`) a Playwright pro hlavní průchody UI (`npm run e2e`). Node je jen vývojový nástroj.
- CI: GitHub Actions spouští testy při každém pushi a při tagu `v*` sestaví a přiloží release zip.

## 4. Struktura repozitáře

```
public/            webroot (index.php, install.php, assets/, .htaccess)
src/               aplikační kód (namespace Nordvarr\)
templates/         PHP šablony
lang/              cs.php, en.php
migrations/        číslované SQL migrace
bin/               nordvarr.php (CLI), build-release.sh
tests/             unit, integration, e2e, fixtures
docs/              STAV.md, ROZHODNUTI.md, NAVOD.md
config.example.php
VERSION
```

Pokud agent při F0 zvolí odlišnou strukturu, zapíše důvod do `docs/ROZHODNUTI.md`.

## 5. Datový model

Identifikátory: UUIDv7 jako text. Časy: UTC v ISO 8601, v UI se zobrazují v časové zóně z nastavení (výchozí Europe/Prague).

- `objects`: id, type, name, slug, description, lifecycle (`active` | `archived`), created_at, updated_at.
  Typy jsou pevný seznam: `server`, `vm`, `service`, `website`, `database`, `domain`, `network_device`, `other`. Popisky v UI česky.
- `object_external_ids`: object_id, source, external_id; unikátní (source, external_id).
- `object_attributes`: object_id, key, value (text nebo JSON), source (`manual` nebo název API zdroje), updated_at; unikátní (object_id, key, source).
  Když má stejný klíč od různých zdrojů různé hodnoty, UI zobrazí obě a označí je jako rozpor. Manuální hodnota se zobrazuje jako platná.
- `relationships`: id, from_id, to_id, kind (`depends_on` | `contains`), note, source. U `contains` se brání cyklům.
- `tags`, `object_tags`.
- `object_docs`: aktuální markdown u objektu; `doc_revisions`: neměnné revize (autor, čas, text).
- `events`: id, source, source_event_id (unikátní se source), type (`deploy` | `config` | `maintenance` | `restart` | `incident` | `note` | `other`), occurred_at, summary (max 300 znaků), details (max 4000 znaků); `event_objects`: vazba událostí na objekty (jedna událost může patřit více objektům).
- `checks`: id, object_id, type, config (JSON), interval_s, timeout_s, enabled, notify_on_degraded.
- `check_results`: syrové výsledky, retence 14 dní.
- `check_hourly`: hodinové agregace (počet, počet selhání, průměr a maximum latence), retence 400 dní.
- `check_state`: aktuální stav, reason_code, reason_detail, since, last_run_at, next_run_at, consecutive_failures.
- `alerts`: id, check_id, object_id, status (`open` | `resolved`), opened_at, resolved_at, last_notified_at, suppressed_by_alert_id, probable_cause_object_id, maintenance_suppressed.
- `channels`: typ (`email` | `telegram` | `webhook`), konfigurace, enabled; `channel_rules`: kanál dostane vše, nebo jen objekty s vybraným tagem.
- `deliveries`: odchozí fronta notifikací (stav, počet pokusů, další pokus, poslední chyba).
- `maintenance_windows`: rozsah (objekt nebo tag), od, do, pause_checks, poznámka.
- `users`: e-mail, hash hesla, role (`admin` | `viewer`; MVP používá jen admina, druhá role je připravená), `login_attempts`, `api_tokens` (prefix, hash, název zdroje, scope, created_at, last_used_at, revoked_at), `audit_log`.
- `settings`: klíč a hodnota.
- `schema_migrations`.

## 6. Kontroly

Typy:

1. `http`: URL (jen http/https), metoda GET nebo HEAD, očekávané stavové kódy (výchozí 200-399), volitelně text, který musí odpověď obsahovat, max. 5 přesměrování, limit latence pro stav degraded, ověření TLS zapnuté, odpověď max. 1 MB.
2. `tcp`: host a port.
3. `ping`: host; používá systémový `ping`. Pokud chybí nebo nemá oprávnění, stav `unknown` s důvodem `tool_missing`.
4. `tls_expiry`: host a port; degraded při méně než 21 dnech, unhealthy při méně než 7 dnech nebo po expiraci (limity nastavitelné).
5. `domain_expiry`: doména přes RDAP (bootstrap z IANA, cache 7 dní); degraded pod 30 dní, unhealthy pod 7 dní; kontrola jednou denně. Když RDAP datum expirace nevrátí, stav `unknown` s důvodem `rdap_no_expiry`.
6. `heartbeat`: pasivní kontrola pro cron úlohy a zálohy. Každá má vlastní adresu `/hb/<token>` (GET i POST). Když ping nepřijde do intervalu + tolerance, stav unhealthy s důvodem `heartbeat_missed`.

Stavy: `healthy`, `degraded`, `unhealthy`, `unknown`, `paused`. Přechod do horšího stavu až po 2 po sobě jdoucích selháních (nastavitelné u kontroly), návrat do healthy po prvním úspěchu. Stav objektu je nejhorší stav jeho kontrol; archivovaný objekt a objekt v údržbě s pozastavením je `paused`.

Kódy důvodů: `dns_failure`, `tcp_refused`, `tcp_timeout`, `tls_error`, `http_4xx`, `http_5xx`, `content_mismatch`, `latency_degraded`, `cert_expiring`, `cert_expired`, `domain_expiring`, `domain_expired`, `rdap_no_expiry`, `heartbeat_missed`, `check_timeout`, `tool_missing`, `dependency_unhealthy`, `unknown_error`. Ke kódu se ukládá krátký čitelný detail (max 300 znaků).

Worker:

- běží z cronu každou minutu, zámek proti souběhu, celková doba běhu max. 50 s,
- vybere kontroly, které jsou na řadě, HTTP spouští paralelně přes curl_multi (max. 20 současně),
- výsledek, který dorazí později než novější výsledek téže kontroly, se uloží do historie, ale nepřepíše aktuální stav,
- jednou za hodinu přepočítá agregace a promaže data po retenci,
- po každém běhu zapíše čas posledního běhu; když worker neběžel déle než 3 minuty, přehled zobrazí varování.

## 7. Alerty a notifikace

- Alert se otevře, když kontrola přejde do `unhealthy`. U `degraded` jen když má kontrola zapnuté `notify_on_degraded` (výchozí zapnuto u `tls_expiry` a `domain_expiry`, jinak vypnuto).
- Na jednu kontrolu je otevřený nejvýš jeden alert. Při návratu do healthy se alert uzavře a odejde notifikace o obnovení.
- Připomínka otevřeného alertu každých 24 h (nastavitelné, 0 = vypnuto).
- Potlačení o jeden krok: pokud objekt X závisí na Y (`depends_on`) nebo je v Y obsažen (`contains`) a Y má otevřený, nepotlačený alert, notifikace alertu X se neodešle. Alert X zůstává viditelný s odkazem "pravděpodobná příčina: Y". Při více kandidátech rozhoduje: `depends_on` před `contains`, pak dříve otevřený alert, pak menší id. Rekurze jen o jeden krok, cykly nevadí.
- Údržbové okno potlačí notifikace pro objekty v rozsahu. Kontroly běží dál, pokud okno nemá zapnuté `pause_checks`. Po skončení okna se stav přehodnotí a za každý stále otevřený alert odejde nejvýš jedna notifikace.
- Kanály:
  - e-mail přes SMTP s primárním a záložním serverem (když primární selže, zkusí se záložní), vlastní malý SMTP klient se SSL/STARTTLS a AUTH LOGIN/PLAIN,
  - Telegram bot (token, chat_id),
  - webhook: POST JSON s hlavičkou `X-Nordvarr-Signature` (HMAC-SHA256 s tajemstvím kanálu).
- Odesílání přes frontu `deliveries` s opakováním po 1, 5, 15 a 60 minutách, pak stav `failed`. Historie pokusů je vidět v UI.
- Kanály implementují společné rozhraní, aby šel později přidat další typ (například odchozí hlasový hovor) bez změny logiky alertů. Samotný hlasový kanál není součástí MVP.
- U každého kanálu tlačítko "Odeslat test".

## 8. API pro stroje

Autentizace tokenem v hlavičce `Authorization: Bearer <token>`. Token určuje název zdroje a scope (`inventory:write`, `events:write`). API odmítá cookies a session, UI odmítá bearer tokeny. Limit 120 požadavků za minutu na token.

- `POST /api/v1/objects/upsert`
  ```json
  {"external_id":"web:shop.example.com","type":"website","name":"shop.example.com",
   "attributes":{"document_root":"/srv/www/shop"},"tags":["eshop"],
   "relationships":[{"kind":"depends_on","to_external_id":"vm:app1"}],
   "docs_markdown":"Volitelný text dokumentace"}
  ```
  Idempotentní podle (zdroj, external_id). Stejný vstup nevytvoří duplicitní objekty, atributy, vztahy, revize dokumentace ani události. Odkaz na neexistující objekt vrátí 422 se seznamem chybějících external_id. Odpověď obsahuje id objektu a co se změnilo.
- `POST /api/v1/events`
  ```json
  {"event_id":"deploy-2026-10-20-1","type":"deploy","occurred_at":"2026-10-20T13:58:00Z",
   "summary":"release 42","objects":[{"external_id":"web:shop.example.com"}]}
  ```
  Idempotentní podle (zdroj, event_id).
- `GET /api/v1/objects/{id}/context`: stejný obsah jako export pro LLM, ve formátu JSON.
- `GET|POST /hb/{token}`: heartbeat, bez další autentizace.

Router: front controller `public/index.php`, hezké adresy přes `.htaccess`, fallback `index.php?r=...` bez mod_rewrite.

## 9. Uživatelské rozhraní

Čeština jako výchozí jazyk, kompletní anglický překlad v `lang/en.php`. Použitelné na mobilu: na úzké obrazovce hlavní navigace jako spodní lišta v dosahu palce.

Stránky:

1. Přehled: "Nefunguje teď", "Změny za 24 h", "Brzy vyprší" (certifikáty a domény do 30 dní), souhrn stavů, varování o neběžícím workeru. Obnovení dat dotazem na JSON endpoint s ETag a `If-None-Match`: aktivní karta každých 15 s, karta na pozadí každých 60 s, při chybě postupné prodlužování.
2. Inventář: seznam s filtrem podle typu, tagu, stavu a textu; hromadné přidání tagu.
3. Detail objektu (diagnostická karta). Nahoře hlavička na jeden pohled:
   - stav a důvod (například "HTTP: tcp_refused, 3 min"),
   - pravděpodobná příčina, pokud alert potlačil jiný objekt,
   - poslední změna na objektu a jeho přímých sousedech za 24 h,
   - stav závislostí,
   - počet alertů za 90 dní.
   Pod hlavičkou: atributy (včetně rozporů), vztahy, kontroly, dokumentace s revizemi, časová osa, tlačítko "Zkopírovat pro LLM".
4. Topologie: graf Cytoscape. Výchozí pohled je okolí vybraného objektu (1 nebo 2 kroky). Druhý režim zobrazí objekty podle filtru (typ, tag, stav), max. 300 uzlů; při překročení vyzve k zúžení filtru. Barva uzlu podle stavu, rozvržení dagre, klik na uzel otevře kartu. Ruční pozice se v MVP neukládají.
5. Alerty: otevřené a uzavřené, filtr, správa údržbových oken.
6. Nastavení: kanály, API tokeny (token se zobrazí jen jednou), uživatelé, časová zóna, export a import celého inventáře v JSON (bez tajných údajů), zobrazení řádku pro cron s doplněnou skutečnou cestou instalace.

Markdown: vlastní bezpečný renderer s omezenou sadou (nadpisy, odstavce, seznamy, kód, odkazy, tučné a kurzíva, jednoduché tabulky). Vstup se vždy escapuje, HTML se nepropouští.

## 10. Export pro LLM

Tlačítko u objektu vygeneruje Markdown (a stejný obsah je v API jako JSON):

- identifikace objektu, typ, tagi,
- aktuální stav, důvod, otevřené alerty, pravděpodobná příčina,
- souhrn kontrol za 24 h z agregací, ne syrová data,
- závislosti a závislé objekty se stavem (1 až 2 kroky, max. 30 objektů, řazeno: nezdravé, nedávno změněné, bližší),
- události z okolí za 7 dní (max. 30),
- atributy,
- dokumentace (max. 4000 znaků).

Maskování: atributy s klíčem obsahujícím `pass`, `pwd`, `secret`, `token`, `key`, `private`, URL s `uživatel:heslo@`, DSN s heslem a dlouhé řetězce s vysokou entropií. Testy obsahují nastražené "kanárkové" tajné hodnoty, které se v exportu nesmí objevit. Velikost exportu max. 24 kB. Tlačítko "Kopírovat" používá `navigator.clipboard`.

## 11. Bezpečnost

- Hesla přes `password_hash` (Argon2id, jinak bcrypt). Session cookie HttpOnly, SameSite=Lax, Secure na HTTPS, nové id session po přihlášení, zneplatnění session po změně hesla.
- CSRF token u každého POST z UI.
- Přihlášení: obecná chybová hláška, po 5 neúspěších postupné zpoždění, po 10 neúspěších zámek na 15 minut (podle účtu i IP).
- API tokeny uložené jako prefix + HMAC-SHA256, nikdy v čitelné podobě.
- Datový adresář (SQLite, logy, zámek, zálohy) mimo webroot. Instalátor ověří, že není dostupný z webu, a varuje. V datovém adresáři je navíc `.htaccess` se zákazem přístupu.
- Hlavičky: CSP bez inline skriptů, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `X-Content-Type-Options: nosniff`.
- Audit: přihlášení, tokeny, změny nastavení a kanálů, mazání.

## 12. Instalace a aktualizace

- Balíček: `nordvarr-<verze>.zip` s adresářem `nordvarr/`, obsahuje `config.example.php`, neobsahuje `config.php` ani data.
- Instalace: nahrát soubory, otevřít `/install.php`. Ten ověří verzi PHP a rozšíření, zeptá se na cestu k datovému adresáři (výchozí návrh: `nordvarr-data` vedle adresáře aplikace), vytvoří `config.php` s náhodným tajemstvím aplikace, databázi a účet admina. Po instalaci se sám zablokuje (při existujícím `config.php` odmítne běžet). Na konci zobrazí přesný řádek pro cron.
- Aktualizace: nahrát nové soubory přes staré. Při prvním požadavku přihlášeného admina se spustí migrace; před migrací se vytvoří kopie databáze v `nordvarr-data/backups/`. Žádné upgrade skripty ani ruční kroky.
- `bin/build-release.sh` sestaví zip do `dist/`. Verze je v souboru `VERSION`.

## 13. Fáze

Každá fáze končí zelenými testy, aktualizovaným `docs/STAV.md` a pushnutou větví.

### F0 Kostra

Struktura, front controller a router, šablony s escapováním, migrace, instalátor, přihlášení s CSRF a omezením pokusů, layout CZ/EN včetně mobilní spodní lišty, PHPUnit a Playwright, CI, build zipu, `docs/STAV.md`, `docs/ROZHODNUTI.md`, `scripts/setup-cloud.sh`.

Akceptace: čistá instalace do prázdného adresáře přes `install.php`, přihlášení a odhlášení, testy zelené, sestavený zip jde po rozbalení do jiného adresáře znovu nainstalovat.

### F1 Inventář

Objekty, externí id, atributy s rozpory, tagy, vztahy, dokumentace s revizemi, import a export JSON, API tokeny, upsert API, events API, časová osa, skript s ukázkovými daty (fiktivní infrastruktura, cca 40 objektů).

Akceptace: opakovaný upsert stejného vstupu nic nezduplikuje; událost se zobrazí na časové ose objektu i jeho souseda; export a import inventáře dá stejný výsledek.

### F2 Kontroly

Všech 6 typů, worker, stavy a kódy důvodů, historie, agregace, retence, přehled.

Testy proti lokálním fixture serverům (HTTP 200, 500, pomalá odpověď, přesměrování, zavřený port, TLS certifikát s blízkou expirací) a RDAP z fixture JSON přes vyměnitelného HTTP klienta. Žádná síťová volání na skutečné služby.

### F3 Alerty a notifikace

Alerty, fronta odesílání, e-mail (testy proti lokálnímu falešnému SMTP serveru), Telegram a webhook (lokální mock endpoint), údržbová okna, potlačení o jeden krok, připomínky, testovací odeslání.

### F4 Topologie a diagnostická karta

Cytoscape graf, okolí objektu, filtrový režim, hlavička diagnózy, obnovování přes ETag.

Akceptace: Playwright scénář "spadne databáze -> web je unhealthy, notifikace webu je potlačena, karta webu ukazuje databázi jako pravděpodobnou příčinu a poslední deploy v okolí".

### F5 Export, bezpečnost, dokumentace

Export pro LLM s maskováním a kanárkovými testy, kontrolní průchod bezpečnosti podle kapitoly 11 (zjištění a opravy do `docs/STAV.md`), `docs/NAVOD.md` (instalace, cron, aktualizace, příklady API s curl), release 0.1.0.

## 14. Mimo rozsah MVP

Hostový agent, automatické objevování, volání LLM, více organizací, SSO, TOTP, vysoká dostupnost, distribuované sondy, ticketing, fulltext přes historii, diff dokumentace, hlasový notifikační kanál (jen připravené rozhraní), PostgreSQL ovladač (jen připravená vrstva), ukládání ručních pozic v grafu, statistické baseliny latence.
