# Návod: instalace, provoz a API

Nordvarr je jedna PHP aplikace s databází SQLite. Instaluje se jako WordPress: nahrát soubory, otevřít v prohlížeči. Na serveru není potřeba Composer, Node ani žádný build.

Obsah:

1. [Požadavky](#1-požadavky)
2. [Instalace](#2-instalace)
3. [Cron a worker](#3-cron-a-worker)
4. [První kroky](#4-první-kroky)
5. [Aktualizace](#5-aktualizace)
6. [Zálohy a obnova](#6-zálohy-a-obnova)
7. [Zapomenuté heslo](#7-zapomenuté-heslo)
8. [API s curl](#8-api-s-curl)
9. [Konfigurace config.php](#9-konfigurace-configphp)
10. [Bezpečnost](#10-bezpečnost)
11. [Řešení problémů](#11-řešení-problémů)
12. [Agent pro servery (Linux)](#12-agent-pro-servery-linux)

## 1. Požadavky

- PHP 8.4 nebo 8.5 s rozšířeními `pdo_sqlite`, `curl`, `openssl`, `json`, `mbstring`. Instalátor je ověří.
- Webový server, doporučený je Apache 2.4 s `mod_rewrite` (hezké adresy). Bez `mod_rewrite` aplikace funguje s adresami `index.php?r=...`.
- Cron, který každou minutu spustí PHP z příkazové řádky.
- Pro kontrolu `ping` příkaz `ping` (balíček `iputils-ping`). Bez něj má kontrola stav "neznámý" s důvodem `tool_missing`.
- Odchozí spojení ze serveru na kontrolované cíle, na SMTP server, Telegram nebo webhooky a pro expiraci domén na RDAP (`data.iana.org` a servery registrů).

## 2. Instalace

1. Stáhněte `nordvarr-<verze>.zip` z releasu a rozbalte ho. Vznikne adresář `nordvarr/`.
2. Nahrajte adresář na server, například do `/var/www/nordvarr`.
3. Webroot (DocumentRoot) nastavte na `/var/www/nordvarr/public`. Mimo `public/` nesmí být nic dostupné z webu.
4. Otevřete v prohlížeči `https://nordvarr.example.com/install.php` a vyplňte formulář:
   - **Datový adresář**: databáze, logy, zámky a zálohy. Instalátor navrhne `nordvarr-data` vedle adresáře aplikace (`/var/www/nordvarr-data`), a když tam webový server zapisovat nesmí (typicky server, kde má každá doména vlastního uživatele a zapisovat smí jen do adresáře domény), navrhne `nordvarr-data` uvnitř adresáře aplikace vedle `public/` (například `/var/www/vhosts/example.com/app.example.com/nordvarr-data`). Obě místa leží mimo webroot. Datový adresář nesmí být ve složkách s kódem (`public/`, `src/` a další). Instalátor ověří, že adresář jde vytvořit, že není dostupný z webu, a přidá do něj `.htaccess` se zákazem přístupu.
   - **E-mail a heslo administrátora** (heslo aspoň 10 znaků).
   - **Časová zóna** (výchozí Europe/Prague).
5. Instalátor vytvoří `config.php` s náhodným tajemstvím aplikace, databázi a účet. Na konci zobrazí přesný řádek pro cron. Pak se sám zablokuje: dokud existuje `config.php`, `install.php` odmítne běžet.

Webový server musí mít právo zápisu do adresáře aplikace (kvůli `config.php`) a do datového adresáře. Po instalaci stačí zápis do datového adresáře.

### Ukázka konfigurace Apache

```apache
<VirtualHost *:443>
    ServerName nordvarr.example.com
    DocumentRoot /var/www/nordvarr/public

    <Directory /var/www/nordvarr/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/nordvarr.example.com.pem
    SSLCertificateKeyFile /etc/ssl/private/nordvarr.example.com.key
</VirtualHost>
```

`public/.htaccess` zapne hezké adresy a předá do PHP hlavičku `Authorization` (bez ní API tokeny nefungují pod PHP-FPM). Pokud `AllowOverride` povolit nejde, přeneste jeho obsah do konfigurace virtuálního hostu.

Aplikaci provozujte přes HTTPS. Cookie session má na HTTPS příznak `Secure` a tlačítko "Kopírovat" u exportu pro LLM vyžaduje zabezpečené spojení.

## 3. Cron a worker

Worker spouští kontroly, vyhodnocuje alerty a odesílá notifikace. Spouští ho cron každou minutu, jeden běh trvá nejvýš 50 s a souběžné běhy blokuje zámek. Přesný řádek je na konci instalace a v Nastavení, například:

```
* * * * * php /var/www/nordvarr/bin/nordvarr.php worker >/dev/null 2>&1
```

Cron spouštějte pod stejným uživatelem, pod kterým běží web (například `www-data`), aby oba mohli zapisovat do datového adresáře:

```
crontab -u www-data -e
```

Když worker déle než 3 minuty neběžel, přehled zobrazí varování.

Další příkazy:

```
php bin/nordvarr.php version                  # verze
php bin/nordvarr.php migrate                  # ruční spuštění migrací (se zálohou)
php bin/nordvarr.php demo-data                # fiktivní ukázková data do prázdného inventáře
php bin/nordvarr.php reset-password <e-mail>  # nové heslo pro zapomenutý účet
```

## 4. První kroky

1. **Nastavení -> Obecné:** časová zóna a řádek pro cron.
2. **Nastavení -> Notifikace:** adresa aplikace pro odkazy v notifikacích (vyplní se sama při prvním přihlášení), jazyk notifikací a interval připomínek.
3. **Nastavení -> Kanály:** e-mail (SMTP), Telegram nebo webhook. Po uložení použijte "Odeslat test".
4. **Inventář:** objekty ručně, importem JSON nebo přes API. Vztahy "závisí na" a "obsahuje" určují okolí v topologii a potlačení alertů.
5. **Kontroly** na detailu objektu: HTTP, TCP, ping, certifikát TLS, expirace domény, heartbeat. Rychlejší cesta je popsaná níže v části "Jak zapnout hlídání".
6. **Topologie:** graf okolí objektu nebo objektů podle filtru.
7. U incidentu: karta objektu ukazuje stav a důvod, pravděpodobnou příčinu, poslední změnu v okolí a stav závislostí. Tlačítko **"Zkopírovat pro LLM"** připraví kontext k vložení do libovolného LLM (tajné údaje jsou maskované, Nordvarr sám žádné LLM nevolá).

### Jak zapnout hlídání

Nordvarr hlídá dvěma způsoby:

- **Kontroly ze serveru Nordvarru.** Worker (cron každou minutu) se z vašeho serveru připojuje k cílům: web (HTTP), port (TCP), ping, certifikát TLS, expirace domény. Na hlídaných strojích nic instalovat nemusíte.
- **Agent na hlídaném serveru.** Skript na Linuxu každou minutu pošle CPU, RAM, disky, teploty, GPU a stav disků ze SMART (kapitola 12). Hodí se i pro server, na kterém běží sám Nordvarr.

Postup:

1. **Doporučené kontroly.** Na kartě objektu (část Kontroly) Nordvarr navrhne kontroly podle typu objektu a atributů a přidají se jedním tlačítkem. Návrhy:
   - web: HTTP na atribut `url`, jinak `https://<název>/`, u https i certifikát TLS,
   - doména: expirace domény podle názvu,
   - server, VM, síťové zařízení: ping na atribut `ip` (případně `ipv6`, `host`, `hostname`), jinak na název, pokud je to IP nebo jméno hosta,
   - služba, databáze, jiné: HTTP na atribut `url`, jinak TCP na `host` (nebo `ip`, `server`) a `port`.

   Návrh, který už existující kontrola pokrývá, se nenabízí. Pokud se nic nenavrhne, doplňte objektu atribut (např. `url` nebo `ip`) nebo přidejte kontrolu ručně tlačítkem "Přidat kontrolu".
2. **Hromadně z inventáře.** V inventáři vyberte objekty a použijte "Přidat doporučené kontroly". Přehled ukazuje počet objektů bez kontrol s odkazem na jejich seznam.
3. **Ping na hostingu.** Pokud kontrola ping končí důvodem `tool_missing` (na serveru Nordvarru není program `ping` nebo ho PHP nesmí spustit), použijte místo něj kontrolu TCP na otevřený port (např. 22 nebo 443).
4. **Agent** pro metriky serverů nainstalujte podle kapitoly 12. Na kartě serveru bez agenta je odkaz na návod v Nastavení -> Agent pro servery.
5. **Notifikace.** Bez kanálu se o výpadku dozvíte jen v aplikaci: nastavte kanál a použijte "Odeslat test" (kroky 2 a 3 výše).

## 5. Aktualizace

1. Stáhněte nový zip a nahrajte obsah adresáře `nordvarr/` přes stávající soubory. `config.php` v balíčku není, takže se nepřepíše; datový adresář zůstane beze změny.
2. Přihlaste se jako admin. Při prvním požadavku se spustí čekající migrace databáze, předtím se vytvoří kopie databáze v `nordvarr-data/backups/`. Aplikace o tom zobrazí hlášení.

Do přihlášení admina může API vracet chybu 500 (nové tabulky ještě neexistují). Migrace jde spustit i ručně: `php bin/nordvarr.php migrate`.

Žádné další upgrade skripty ani ruční kroky nejsou potřeba. Pokud je datový adresář `nordvarr-data` uvnitř adresáře aplikace, při aktualizaci ho nemažte (nahrání souborů přes staré ho nechá beze změny). Soubory, které nová verze už nepoužívá, nevadí; čistší je ale nahrát novou verzi do prázdného adresáře a přenést jen `config.php`.

## 6. Zálohy a obnova

Všechna data jsou v datovém adresáři:

- `nordvarr.sqlite` (+ `-wal`, `-shm`): databáze,
- `backups/`: automatické kopie před migracemi a před importem inventáře,
- `sessions/`, zámky a logy.

Konzistentní záloha za běhu:

```
sqlite3 /var/www/nordvarr-data/nordvarr.sqlite ".backup '/zalohy/nordvarr-$(date +%F).sqlite'"
```

Pro obnovu zastavte cron, nahraďte `nordvarr.sqlite` zálohou (a smažte `-wal` a `-shm`) a cron znovu zapněte. Zálohujte i `config.php`: obsahuje tajemství aplikace, bez kterého nejde ověřit API tokeny ani dešifrovat hesla notifikačních kanálů.

Inventář (objekty, atributy, vztahy, tagy, dokumentace, události, kontroly) jde také stáhnout v **Nastavení -> Export a import** jako JSON. Export neobsahuje tajné údaje (atributy s citlivým klíčem a hesla v URL jsou maskované), stav kontrol, alerty ani kanály.

## 7. Zapomenuté heslo

Přihlášený admin mění heslo v **Nastavení -> Účet**; ostatní přihlášení se tím odhlásí. Při zapomenutém hesle vygeneruje nové heslo příkaz na serveru:

```
php /var/www/nordvarr/bin/nordvarr.php reset-password admin@example.com
```

Nové heslo se vypíše jen jednou. Po přihlášení ho změňte.

## 8. API s curl

API je na adresách `/api/v1/...` a přijímá jen token v hlavičce `Authorization: Bearer <token>`, cookies ani session nepoužívá. Token vytvoříte v **Nastavení -> API tokeny**; zobrazí se jen jednou. Token určuje název zdroje (například `ansible`, `ci`) a oprávnění:

| Scope | Co dovoluje |
|---|---|
| `inventory:write` | `POST /api/v1/objects/upsert` |
| `events:write` | `POST /api/v1/events` |
| `context:read` | `GET /api/v1/objects/{id}/context` (export pro LLM) |
| `agent:write` | `POST /api/v1/agent/report` (hlášení hostového agenta, viz kap. 12) |

Limit je 120 požadavků za minutu na token (pak `429` s hlavičkou `Retry-After`). Tělo požadavku musí být JSON (`Content-Type: application/json`), nejvýš 1 MB.

V příkladech:

```
NV=https://nordvarr.example.com
TOKEN=nv_0123456789ab_...
```

### Objekty: upsert

Idempotentní podle dvojice (zdroj tokenu, `external_id`). Opakované odeslání stejného vstupu nic nezduplikuje. Když požadavek obsahuje `attributes` nebo `relationships`, atributy a vztahy, které tento zdroj poslal dřív a teď v seznamu chybí, se odstraní; data jiných zdrojů a ruční úpravy zůstanou. Tagy se jen přidávají.

```
curl -sS -X POST "$NV/api/v1/objects/upsert" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"external_id":"vm:app1","type":"vm","name":"app1.example.com",
       "attributes":{"ip":"192.0.2.33","os":"Debian 13"},"tags":["eshop"]}'

curl -sS -X POST "$NV/api/v1/objects/upsert" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"external_id":"web:shop.example.com","type":"website","name":"shop.example.com",
       "attributes":{"document_root":"/srv/www/shop"},"tags":["eshop"],
       "relationships":[{"kind":"depends_on","to_external_id":"vm:app1"}],
       "docs_markdown":"# E-shop\n\nProvoz zajišťuje **app1**."}'
```

Odpověď (`201` nový objekt, `200` existující):

```json
{"id":"01a1...","created":true,"changed":true,"changes":{"object":["created"],"attributes":{"added":["document_root"],"updated":[],"removed":[]},"tags":{"added":["eshop"]},"relationships":{"added":["depends_on:vm:app1"],"removed":[]},"docs":true}}
```

Typy objektů: `server`, `vm`, `service`, `website`, `database`, `domain`, `network_device`, `other`. Vztahy: `depends_on`, `contains`. Odkaz na neexistující objekt vrátí `422` se seznamem `missing_external_ids`, chybný vstup `422` s položkou `fields`.

Tajné údaje (hesla, tokeny, privátní klíče, DSN s heslem) do atributů ani dokumentace nepatří.

### Události

Idempotentní podle (zdroj tokenu, `event_id`). Typy: `deploy`, `config`, `maintenance`, `restart`, `incident`, `note`, `other`. Objekty se určují přes `external_id` (ze stejného zdroje) nebo `id`.

```
curl -sS -X POST "$NV/api/v1/events" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"event_id":"deploy-2026-10-20-1","type":"deploy","occurred_at":"2026-10-20T13:58:00Z",
       "summary":"release 42","details":"Změny: nový výpočet dopravy",
       "objects":[{"external_id":"web:shop.example.com"}]}'
```

Událost se zobrazí na časové ose objektu i jeho přímých sousedů a v diagnostické hlavičce jako "poslední změna".

### Kontext pro LLM

Stejný obsah jako tlačítko "Zkopírovat pro LLM", jako JSON (nebo Markdown):

```
curl -sS "$NV/api/v1/objects/01a1.../context" -H "Authorization: Bearer $TOKEN"
curl -sS "$NV/api/v1/objects/01a1.../context?format=markdown&lang=en" -H "Authorization: Bearer $TOKEN"
```

Id objektu je v adrese jeho karty (`/objects/<id>`) a v odpovědi upsertu. Export má nejvýš 24 kB a maskuje tajné údaje.

### Heartbeat

Kontrola typu heartbeat čeká na pravidelný signál, například od zálohovacího skriptu. Adresu s tokenem najdete na detailu kontroly; nepotřebuje další autentizaci.

```
# na konci zálohovacího skriptu, jen při úspěchu
/usr/local/bin/zaloha.sh && curl -fsS -m 10 "$NV/hb/0123456789abcdef0123456789abcdef" >/dev/null
```

Funguje GET i POST. Když signál nepřijde do intervalu plus tolerance, kontrola přejde do stavu "nefunguje" (`heartbeat_missed`).

### Chybové odpovědi

| Kód | `error` | Význam |
|---|---|---|
| 400 | `invalid_json` | tělo není platný JSON objekt |
| 401 | `unauthorized` | chybí nebo neplatí token |
| 403 | `insufficient_scope` | token nemá potřebné oprávnění |
| 404 | `not_found` | neznámá adresa nebo objekt |
| 413 | `payload_too_large` | tělo nad 1 MB |
| 415 | `unsupported_media_type` | chybí `Content-Type: application/json` |
| 422 | `validation_failed`, `missing_objects` | chybný vstup, odkaz na neexistující objekt |
| 429 | `rate_limited` | překročený limit požadavků |

## 9. Konfigurace config.php

`config.php` vytváří instalátor, nová verze ho nepřepisuje. Vzor je v `config.example.php`.

| Klíč | Význam |
|---|---|
| `app_secret` | náhodné tajemství (HMAC API tokenů, šifrování hesel kanálů). Změna zneplatní tokeny a hesla kanálů. |
| `data_dir` | absolutní cesta k datovému adresáři mimo webroot |
| `debug` | podrobné chyby; v provozu vždy `false` |
| `ping_binary` | cesta k příkazu ping (výchozí `ping` z PATH) |
| `rdap_bootstrap_url` | adresa bootstrapu RDAP (výchozí `https://data.iana.org/rdap/dns.json`) |
| `telegram_api_url` | adresa API Telegramu (výchozí `https://api.telegram.org`) |

## 10. Bezpečnost

- Hesla: Argon2id (jinak bcrypt). Přihlášení má obecnou chybovou hlášku, od 5 neúspěchů postupné zpoždění a od 10 neúspěchů zámek na 15 minut, zvlášť pro účet a IP adresu.
- Každý formulář má CSRF token. Session cookie je `HttpOnly`, `SameSite=Lax` a na HTTPS `Secure`; po přihlášení dostane nové id, po změně hesla se ostatní přihlášení zneplatní.
- API tokeny jsou uložené jen jako prefix a HMAC-SHA256; ztracený token nejde zobrazit, jen zneplatnit a vytvořit nový.
- Hesla notifikačních kanálů jsou v databázi zašifrovaná (AES-256-GCM) a do formulářů se nevracejí.
- Hlavičky: CSP bez inline skriptů, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `X-Content-Type-Options: nosniff`.
- Audit (přihlášení, tokeny, změny nastavení a kanálů, mazání, změna hesla) je v databázi v tabulce `audit_log`.
- Kontroly a webhooky můžou mířit i na interní adresy ve vaší síti. To je záměr (monitoring vnitřní infrastruktury), proto účet admina chraňte silným heslem.
- Export pro LLM a export inventáře maskují tajné údaje. Maskování je pojistka, ne důvod tajné údaje do inventáře ukládat.
- HTTPS a hlavičku `Strict-Transport-Security` nastavte ve webovém serveru (aplikace neví, jestli k ní vede jen HTTPS).
- Za reverzní proxy vidí aplikace jako IP adresu klienta adresu proxy: omezení přihlašování podle IP pak platí pro všechny klienty společně a cookie session nemusí dostat příznak `Secure`. Doporučený je přímý provoz na Apache s HTTPS.

## 11. Řešení problémů

| Příznak | Co zkontrolovat |
|---|---|
| Přehled hlásí, že worker neběží | řádek v cronu, uživatel cronu, `php -v` v cronu (stejná verze jako web), práva k datovému adresáři |
| API vrací `401` i se správným tokenem | předání hlavičky `Authorization` do PHP (viz `public/.htaccess`, u PHP-FPM `CGIPassAuth On` nebo `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`) |
| Adresy vedou na `index.php?r=...` | `mod_rewrite` není zapnutý nebo `AllowOverride` nepovoluje `.htaccess`; aplikace tak funguje dál |
| Kontrola ping má `tool_missing` | chybí příkaz `ping` nebo nemá oprávnění (`setcap cap_net_raw+ep /usr/bin/ping`), případně nastavte `ping_binary` |
| Expirace domény je "neznámý" | server se nedostane na RDAP (`data.iana.org` a server registru) nebo TLD nemá RDAP |
| E-mail se neodesílá | "Odeslat test" u kanálu, historie pokusů v detailu kanálu, port 465 (SSL) nebo 587 (STARTTLS) |
| Po aktualizaci API vrací 500 | přihlaste se jako admin nebo spusťte `php bin/nordvarr.php migrate` |
| Zapomenuté heslo | `php bin/nordvarr.php reset-password <e-mail>` |
| Server s agentem hlásí `agent_missing` | `/usr/local/bin/nordvarr-agent.sh --test` na serveru, `systemctl status nordvarr-agent.timer`, soubor `/var/lib/nordvarr-agent/last-error` |
| U agenta chybí stav disků | balíček `smartmontools`, agent musí běžet jako root; virtuální disky (VM) SMART často nemají |

## 12. Agent pro servery (Linux)

Agent je jeden skript pro bash (`agent/nordvarr-agent.sh`), který každou minutu pošle do Nordvarru stav serveru. Nepotřebuje nic kromě `bash` 4.4+, `curl` a `coreutils`; pro stav disků balíček `smartmontools`, pro GPU ovladač NVIDIA s `nvidia-smi`.

### Co měří

| Údaj | Zdroj |
|---|---|
| CPU (průměr za poslední minutu), load, počet jader | `/proc/stat`, `/proc/loadavg`, `/proc/cpuinfo` |
| RAM a swap | `/proc/meminfo` |
| Zaplnění disků a inodů (bez tmpfs, overlay a podobných) | `df` |
| Teploty všech čidel (CPU, deska, NVMe) včetně mezí čidel | `/sys/class/hwmon`, jinak `/sys/class/thermal` |
| NVIDIA GPU: vytížení, VRAM, teplota, příkon, ventilátor | `nvidia-smi` |
| Síťový provoz rozhraní (průměr za minutu) | `/proc/net/dev` |
| Stav disků ze SMART: zdraví, opotřebení SSD a NVMe, teplota, provozní hodiny, přemapované a čekající sektory, chyby média a chyby v logu disku | `smartctl -j` jednou za 15 minut |

Výstup `smartctl` posílá agent v původní podobě JSON, rozbor (ATA/SATA i NVMe) dělá server.

### Instalace

1. V **Nastavení -> API tokeny** vytvořte token se scope `agent:write` (stačí jeden pro všechny servery, zdroj například `agent`).
2. Na serveru jako root (adresy jsou i v **Nastavení -> Agent pro servery**, už s adresou vaší instalace):

```
curl -fsSL https://nordvarr.example.com/agent/nordvarr-agent.sh -o /usr/local/bin/nordvarr-agent.sh
chmod 755 /usr/local/bin/nordvarr-agent.sh
curl -fsSL https://nordvarr.example.com/agent/nordvarr-agent.service -o /etc/systemd/system/nordvarr-agent.service
curl -fsSL https://nordvarr.example.com/agent/nordvarr-agent.timer -o /etc/systemd/system/nordvarr-agent.timer
apt install smartmontools

install -m 600 /dev/null /etc/nordvarr-agent.conf
cat > /etc/nordvarr-agent.conf <<'KONEC'
NORDVARR_URL=https://nordvarr.example.com
NORDVARR_TOKEN=nv_...
KONEC

/usr/local/bin/nordvarr-agent.sh --test
systemctl daemon-reload
systemctl enable --now nordvarr-agent.timer
```

Soubory agenta jsou i v release zipu v adresáři `nordvarr/agent/`. Místo systemd jde použít cron: `* * * * * /usr/local/bin/nordvarr-agent.sh >/dev/null 2>&1`.

Bez `mod_rewrite` nastavte `NORDVARR_URL=https://nordvarr.example.com/index.php?r=`.

### Konfigurace `/etc/nordvarr-agent.conf`

| Klíč | Význam |
|---|---|
| `NORDVARR_URL` | adresa Nordvarru |
| `NORDVARR_TOKEN` | API token se scope `agent:write` |
| `NORDVARR_EXTERNAL_ID` | identifikace serveru, výchozí `host:<hostname>` |
| `NORDVARR_OBJECT_ID` | připojí agenta k existujícímu objektu (id z adresy karty `/objects/<id>`) |
| `NORDVARR_HOSTNAME` | jméno serveru, výchozí `hostname -f` |
| `NORDVARR_SMART_INTERVAL` | sekundy mezi čteními SMART (výchozí 900, 0 = nečíst) |
| `NORDVARR_SMARTCTL`, `NORDVARR_NVIDIA_SMI` | jiná cesta k příkazům |

Soubor se jen čte jako dvojice `KLÍČ=hodnota`, nic z něj se nespouští. Token se curl předává přes soubor s právy 600, takže není vidět v seznamu procesů. Poslední chyba odeslání je v `/var/lib/nordvarr-agent/last-error`.

### Co se stane po prvním hlášení

- Server se objeví v inventáři jako objekt typu **Server** (nebo se připojí k objektu z `NORDVARR_OBJECT_ID`) a dostane kontrolu typu **Agent (server)**.
- Karta objektu ukáže sekci **Server (agent)**: ukazatele CPU, RAM, swapu, nejplnějšího disku, nejvyšší teploty a každé GPU, graf za 24 h, zaplnění disků, stav disků ze SMART a všechna teplotní čidla.
- V topologii má uzel serveru v popisku CPU, RAM a GPU a panel uzlu ukazuje pruhy hodnot.
- Export pro LLM obsahuje sekci se stavem serveru.

### Prahy kontroly Agent

Nastavují se na detailu kontroly (Upravit). Problém se hlásí po 3 hlášeních po sobě (práh selhání kontroly), aby jednorázová špička nevyvolala alert.

| Problém | Kód důvodu | Stav | Výchozí práh |
|---|---|---|---|
| hlášení nepřišlo | `agent_missing` | nefunguje | interval 60 s + tolerance 180 s |
| vytížení CPU | `cpu_high` | omezeně | 95 % |
| obsazená RAM | `memory_high` | omezeně | 95 % |
| zaplnění disku nebo inodů | `disk_full` | omezeně / nefunguje | 85 % / 95 % |
| teplota čidla nebo GPU | `temperature_high` | omezeně / nefunguje | 85 / 95 °C (kritická mez čidla platí, když je nižší) |
| opotřebení SSD/NVMe | `disk_wear` | omezeně (nefunguje při 100 %) | 80 % |
| SMART hlásí selhání, atribut pod prahem výrobce, kritické varování NVMe | `disk_failing` | nefunguje | - |
| přemapované, čekající nebo neopravitelné sektory, chyby média, chyby v logu disku | `disk_errors` | omezeně | - |

Kontrola posílá notifikace i při stavu omezeně (lze vypnout). Hodnoty se ukládají 48 h po minutách a 400 dní jako hodinové souhrny.

### API hlášení

`POST /api/v1/agent/report` s tokenem `agent:write`. Tělo vytváří skript sám; vlastní agent může poslat stejný JSON:

```json
{"agent_version":"1.0.0","hostname":"srv1.example.com","collected_at":"2026-10-21T10:00:00Z",
 "os":"Debian GNU/Linux 13","kernel":"6.12.0","uptime_s":864000,
 "cpu":{"usage_pct":37.5,"cores":16,"model":"Example CPU","load":[2.5,2.1,1.9]},
 "memory":{"total_b":68719476736,"available_b":25769803776,"swap_total_b":8589934592,"swap_free_b":8589934592},
 "disks":[{"mount":"/","device":"/dev/sda1","fs":"ext4","size_b":500000000000,"used_b":200000000000,"avail_b":275000000000,"inodes_total":30000000,"inodes_used":900000}],
 "temperatures":[{"chip":"coretemp","label":"Package id 0","c":61,"max_c":84,"crit_c":100}],
 "gpus":[{"index":0,"name":"NVIDIA ...","util_pct":92,"mem_used_mb":18000,"mem_total_mb":24576,"temp_c":71,"power_w":280.5,"fan_pct":55}],
 "network":[{"iface":"eth0","rx_bps":1250000,"tx_bps":250000}],
 "smart":[{ ...výstup smartctl -j... }]}
```

Odpověď: `{"object_id":"...","created":false,"check_id":"...","status":"healthy","problems":0}`. Neznámé klíče se ignorují, hodnoty mimo rozumný rozsah se uloží jako prázdné.

