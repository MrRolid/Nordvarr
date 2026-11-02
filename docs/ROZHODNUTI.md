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

## 2026-10-20 (F2 Kontroly)

1. **Výsledky kontrol** (`check_results`) mají celočíselné id místo UUIDv7 kvůli objemu (stovky kontrol každou minutu, retence 14 dní). Ostatní tabulky dál používají UUIDv7.
2. **Výchozí počet selhání před změnou stavu** je 2 u `http`, `tcp` a `ping`, ale 1 u `tls_expiry`, `domain_expiry` a `heartbeat`. Tyto kontroly běží zřídka (6 h, 24 h) nebo už toleranci obsahují, takže dvojí potvrzení by znamenalo zpoždění o den. U kontroly je hodnota nastavitelná (1 až 10).
3. **Stavový automat:** do horšího stavu (i z počátečního `unknown`) až po N selháních po sobě; pak stav sleduje poslední výsledek, takže i zlepšení z `unhealthy` na `degraded` se projeví hned. Zpět do `healthy` po prvním úspěchu. Výsledek, který začal dřív než poslední započítaný, jde jen do historie.
4. **Stav `paused` se nepočítá do uloženého stavu**, ale při zobrazení: vypnutá kontrola nebo archivovaný objekt. Objekt bez kontrol má zvláštní stav "bez kontrol" (`none`). Pozastavení údržbovým oknem přidá F3.
5. **`http`:** ověření TLS je vždy zapnuté (bez volby), proxy z prostředí se nepoužívá, nepovolený kód 1xx až 3xx a příliš mnoho přesměrování dávají `unknown_error`. Hledání textu vyžaduje metodu GET.
6. **`tcp` a `tls_expiry` běží přes curl s `CONNECT_ONLY`** ve stejném `curl_multi` jako `http` (paralelně až 20). `tls_expiry` čte certifikát bez ověření důvěry (sleduje jen platnost v čase) a podporuje jen přímé TLS, ne STARTTLS. Když cíl není dostupný, je stav `unknown` (expiraci nejde zjistit), nikoli `unhealthy`; výpadek služby hlásí její `http`/`tcp` kontrola.
7. **`ping`** volá `ping -n -c 1 -W <timeout>`, až 20 procesů současně. Bez odpovědi -> `unhealthy` s `check_timeout` (SPEC pro ping vlastní kód nemá), neznámé jméno -> `dns_failure`, chybějící příkaz nebo oprávnění -> `unknown` s `tool_missing`. Cestu k příkazu jde změnit v `config.php` (`ping_binary`).
8. **RDAP:** bootstrap IANA se drží v tabulce `http_cache` 7 dní. Nedostupný RDAP, 404 nebo chybějící server pro TLD dávají `unknown` (s `unknown_error`, při chybě DNS `dns_failure`), protože o expiraci domény to nic neříká. Adresu bootstrapu jde změnit v `config.php` (`rdap_bootstrap_url`). Interval `domain_expiry` je pevně 24 h.
9. **Heartbeat:** token (32 hex) je uložený čitelně, protože je součástí adresy, kterou admin potřebuje opakovaně zobrazit. Do exportu nejde; po importu vznikne nový. Tolerance (`grace_s`) je výchozí 300 s. Po zmeškání se kontrola vyhodnotí znovu za interval, příchozí signál ji vrátí do `healthy` hned.
10. **Worker** posune termín dalšího běhu ještě před spuštěním kontrol (při pádu se kontrola neopakuje každou minutu). Když databáze čeká na migrace, worker nic nespustí. Agregace se přepočítávají od hodiny před poslední údržbou (zachytí pozdní výsledky i výpadek workeru). Souhrn za 24 h se bere z agregací, takže aktuální hodina se v něm projeví až po hodinové údržbě.
11. **Přehled se obnovuje přes `/overview/data`**, který vrací hotové HTML sekcí v JSON (vykresluje server, prohlížeč jen nahradí obsah). ETag je SHA-1 tohoto HTML, při shodě `If-None-Match` vrací 304. Interval 15 s (aktivní karta) / 60 s (na pozadí), při chybě se zdvojnásobuje až do 5 minut. "Nefunguje teď" zahrnuje kontroly ve stavu `unhealthy` a `degraded`.
12. **Hlavička objektu** už v F2 ukazuje stav s důvodem (např. "TCP: tcp_refused, 3 min") a stav přímých závislostí (objekty, na kterých závisí nebo ve kterých je obsažen). Zbytek diagnostické karty je v F4.
13. **Kontroly jsou součástí exportu a importu inventáře** (konfigurace bez stavu a historie, URL s přihlašovacími údaji maskované). Formát má dál verzi 1, klíč `checks` je volitelný, takže export z F1 jde importovat.
14. **Ukázková data** dostala jen heartbeat noční zálohy. Jiné kontroly by mířily na dokumentační adresy a jen by čekaly na timeout.
15. **Testy kontrol** běží proti lokálním fixture serverům (`php -S` s více workery, TLS server s certifikáty vygenerovanými v testu), falešnému příkazu `ping` a RDAP z fixture JSON přes vyměnitelného HTTP klienta. Chyba DNS u curl se netestuje, protože by vyžadovala skutečný DNS dotaz.

## 2026-10-21 (F3 Alerty a notifikace)

1. **Alerty se odvozují z aktuálního stavu kontrol** jedním průchodem po každém běhu workeru, ne přímo při zápisu výsledku. Díky tomu se stejně zpracují výsledky workeru, heartbeat i změny z UI a potlačení se rozhoduje až nad všemi výsledky jednoho běhu (web a databáze spadlé ve stejné minutě se vyhodnotí správně). Heartbeat, který dorazí mezi běhy, se do alertů promítne nejpozději při dalším běhu (do minuty).
2. **Kdy se alert zavře:** jen při návratu do `healthy`. Přechod do `unknown` nebo do `degraded` bez `notify_on_degraded` alert nechá otevřený. Vypnutí kontroly nebo archivace objektu alert tiše uzavře (`paused`), smazání kontroly také (`check_deleted`). Alert uchovává snímek typu, názvu a důvodu kontroly, takže historie zůstane i po smazání kontroly.
3. **Potlačení o jeden krok** se vyhodnocuje v pořadí otevření. Když nový alert potlačil jiný nový alert, který je nakonec sám potlačený, rozhodne se znovu, takže potlačovat smí jen nepotlačený alert (řetěz hypervizor -> VM -> služba: VM je potlačená, služba se hlásí). Při vzájemné závislosti dvou objektů se hlásí jeden z nich.
4. **Když se příčina vyřeší a potlačený alert trvá dál,** odejde o něm jedna notifikace ("nefunguje i po vyřešení příčiny") a dál se chová jako běžný alert. Pravděpodobná příčina zůstává viditelná. SPEC tento případ neřeší, bez toho by trvající výpadek zůstal bez upozornění.
5. **Notifikace o obnovení** se posílá, jen když o alertu dřív nějaká notifikace odešla (u potlačeného alertu tedy ne).
6. **Údržbové okno:** během okna se nic neposílá a otevřené alerty objektů v rozsahu se označí; po konci okna za každý stále otevřený alert odejde jedna notifikace ("po údržbě stále nefunguje"). Obnovení během okna je tiché. S `pause_checks` kontroly objektu neběží, mají stav `paused` a jejich alerty zůstávají beze změny; po konci okna jsou kontroly hned na řadě.
7. **Fronta `deliveries`:** 5 pokusů celkem (opakování po 1, 5, 15 a 60 minutách), pak `failed`. Každý pokus je v tabulce `delivery_attempts` a v UI (detail alertu, detail kanálu, stránka "Odeslané notifikace"). Testovací odeslání proběhne hned z UI a neopakuje se.
8. **Tajné položky kanálů** (hesla SMTP, token Telegramu, tajemství webhooku) jsou v databázi zašifrované AES-256-GCM klíčem odvozeným z `app_secret` (rozšíření openssl, které SPEC už vyžaduje). Do formuláře se nikdy nevracejí; prázdné pole při úpravě ponechá dosavadní hodnotu, při změně serveru nebo uživatele se staré heslo zahodí. Změna `app_secret` kanály rozbije (je potřeba zadat tajemství znovu).
9. **SMTP klient:** `ssl` (implicitní TLS, výchozí port 465), `starttls` (výchozí 587, server musí STARTTLS nabízet) a `none`. Certifikát se ve výchozím stavu ověřuje (vypnout jde u kanálu). Přihlášení přes nešifrované spojení je povolené jen na localhost. Preferuje se AUTH PLAIN, jinak AUTH LOGIN. Záložní server se zkusí při jakékoli chybě primárního (spojení, TLS, přihlášení, odmítnutí).
10. **Webhook:** podpis `X-Nordvarr-Signature: sha256=<hex HMAC-SHA256 těla>`, navíc hlavička `X-Nordvarr-Event`. POST nesleduje přesměrování. Úspěch je jakákoli odpověď 2xx.
11. **Telegram:** chyba nesmí prozradit token (je součástí URL), proto se v hlášení nahrazuje `***`. Adresu API jde v `config.php` změnit (`telegram_api_url`), testy tak běží proti lokálnímu mocku.
12. **Pravidla kanálů** (`channel_rules`): bez tagů dostane kanál vše, jinak alerty objektů s kterýmkoli z tagů.
13. **Odkazy v notifikacích** potřebují adresu aplikace, kterou worker z cronu nezná. Uloží se sama při prvním požadavku admina (`base_url` v nastavení, bez mod_rewrite i s `index.php?r=`) a jde ji změnit v nastavení. Jazyk notifikací je nastavitelný (výchozí čeština), časy jsou v časové zóně z nastavení.
14. **Připomínky** jsou nastavitelné v nastavení (výchozí 24 h, 0 = vypnuto), počítají se od poslední notifikace k alertu. Potlačené alerty a alerty v údržbě připomínky nedostávají.
15. **Historie doručení** se maže po 400 dnech spolu s hodinovými agregacemi.
16. **Testy** běží proti lokálnímu falešnému SMTP serveru (STARTTLS, implicitní TLS, AUTH PLAIN/LOGIN, výpadek, odmítnutý příjemce) a lokálnímu mock endpointu pro Telegram a webhooky; v e2e přijímá webhooky malý HTTP server v Node. Test paralelismu kontrol používá vlastní pomalý server obsluhující všechna spojení současně, protože `php -S` s workery jich zvládá jen část a test byl nestabilní.

## 2026-10-21 (F4 Topologie a diagnostická karta)

1. **Klik na uzel otevře kartu v bočním panelu mapy** (stav, příčina, kontroly, incidenty za 90 dní, poslední změny, dokumentace) s tlačítkem na plnou kartu objektu; dvojklik vede rovnou na kartu objektu. SPEC říká "klik na uzel otevře kartu"; panel umožní procházet graf bez opouštění mapy.
2. **Výchozí střed okolí** (SPEC ho neurčuje): objekt s nejstarším nepotlačeným otevřeným alertem úrovně `unhealthy`, potom `degraded`, jinak aktivní objekt s nejvíce vztahy. Výchozí hloubka jsou 2 kroky.
3. **Rozvržení:** dagre shora dolů, nebo zleva doprava, pokud se tak graf do mapy vejde výrazně větší. V režimu filtru s více než 24 uzly se použije silové rozložení `cose` (součást Cytoscape), protože dagre by objekty v málo vrstvách roztáhl do velmi široké řady s nečitelnými popisky. Uzly jsou pak menší s popiskem pod sebou a popisky hran se ukazují jen u zvýrazněného uzlu.
4. **Barva uzlu** je prstenec s podíly stavů jeho kontrol (zelená, žlutá, červená, šedá), záře u objektů `unhealthy` a `degraded`; objekt bez kontrol má neutrální obrys, pozastavený a v údržbě čárkovaný. Pravděpodobná příčina se v grafu kreslí jako oranžová hrana od potlačeného objektu k příčině (po `závisí na`, nebo po `obsahuje` od hostitele).
5. **Metriky CPU, RAM, GPU a podobně se v mapě nezobrazují:** vyžadovaly by hostového agenta, který je mimo rozsah MVP. Uzel ukazuje latenci první kontroly `http`/`tcp`/`ping`, stav, důvod a počet alertů.
6. **Okolí** zahrnuje i archivované objekty, které jsou ve vztahu se středem (vztah stále existuje), režim filtru jen aktivní. Limit 300 uzlů platí i pro okolí (pak se ořízne a pod seznamem uzlů je upozornění s celkovým počtem).
7. **Cytoscape a CSP:** knihovna při startu vkládá `<style>` s jedním pravidlem, což CSP `style-src 'self'` blokuje. Místo povolení inline stylů je pravidlo v `app.css` a na stránce je prázdný prvek s id `__________cytoscape_stylesheet`, podle kterého knihovna pozná, že styl už existuje.
8. **Knihovny:** Cytoscape.js 3.34.2, dagre 0.8.5 a cytoscape-dagre 2.5.0 (MIT) jako minifikované UMD soubory z npm, načtené běžnými `<script>` bez sestavování; kontrolní součty jsou ve `VERSIONS.md`.
9. **Diagnostická hlavička** je samostatná šablona `partials/diagnosis.php` s endpointem `/objects/{id}/diagnosis` (JSON s HTML, ETag) a obnovuje se stejně jako přehled. "Poslední změna" bere nejnovější událost za 24 h z časové osy objektu a přímých sousedů a ukazuje, u kterého souseda nastala. Počet alertů za 90 dní zahrnuje i potlačené alerty; odkaz vede na novou sekci s historií incidentů (posledních 20).
10. **Zástupná stránka** z F0 (`templates/placeholder.php`) byla odstraněna, všechny položky navigace už mají vlastní stránku.


## 2026-10-21 (F5 Export, bezpečnost, dokumentace)

1. **Export pro LLM je samostatná stránka** (`/objects/{id}/llm`) s Markdownem v poli jen pro čtení a tlačítkem "Kopírovat". Tlačítko "Zkopírovat pro LLM" na kartě na ni vede, takže je vidět, co se kopíruje. `navigator.clipboard` funguje jen na HTTPS (a localhost); jinde se text označí a zobrazí se výzva ke zkopírování klávesami.
2. **Nový scope `context:read`** pro `GET /api/v1/objects/{id}/context`. SPEC jmenuje jen dva zapisovací scope; čtení kontextu vydává data z inventáře, proto ho zapisovací tokeny (Ansible, CI) nemají. Nový token má ve formuláři předvybrané jen zapisovací scope. API vrací JSON, s `?format=markdown` stejný Markdown jako UI; jazyk Markdownu je jazyk notifikací, nebo `?lang=`.
3. **Přísné maskování jen v exportu pro LLM.** Export inventáře (záloha) dál maskuje jen podle klíče, URL a DSN, aby šel bez ztráty importovat zpět; maskování podle entropie by v záloze poškodilo legitimní hodnoty. Pravidla entropie: aspoň 20 znaků z `[A-Za-z0-9+/=_-]`, malá i velká písmena i číslice a Shannonova entropie aspoň 3,5 bitu na znak; čistě hex řetězce se maskují od 41 znaků (hash commitu do 40 a UUID zůstanou).
4. **Limit 24 kB platí pro Markdown i JSON.** Když se nevejdou, ubírá se postupně dokumentace (půlením), události, okolí, atributy a kontroly (každé nejméně na 5 položek); export pak nese příznak `truncated` a poznámku. Markdown se v krajním případě ořízne na hranici znaku UTF-8.
5. **Okolí v exportu:** do 2 kroků v obou směrech po obou druzích vztahů. Ve 2. kroku se vztah popisuje vůči prostřednímu objektu ("závisí na nginx na web1"), protože tranzitivní vztah k exportovanému objektu by mohl být zavádějící. Řazení: nezdravé (`unhealthy`, `degraded`, `unknown` od nejhoršího), pak podle poslední události za 7 dní, pak blíž, pak podle názvu. Události se berou z objektu a sousedů zařazených do exportu, podrobnosti události a detail důvodu jsou zkrácené na 300 znaků.
6. **Nadpisy dokumentace** se v exportu posunou o dvě úrovně (`#` -> `###`), aby nenarušily strukturu exportu.
7. **Změna hesla a obnova z CLI.** SPEC uvádí v nastavení "uživatelé" a v kap. 11 zneplatnění session po změně hesla, ale heslo zatím nešlo změnit. Doplněna sekce Nastavení -> Účet (jen vlastní heslo; správa dalších uživatelů není, MVP má jednoho admina) a příkaz `reset-password`, který vygeneruje náhodné heslo a vypíše ho jednou. Neúspěšné ověření současného hesla se počítá do omezení přihlašování.
8. **Opravy z bezpečnostního průchodu:** `umask 0027` v `index.php`, `install.php` a CLI; `config.php` dostane práva 0640 před zápisem; cesty se zpětným lomítkem nebo řídicími znaky se normalizují na `/`; odkazy v Markdownu se zpětným lomítkem se zahodí; ověřuje se nejvýš 4096 bajtů hesla; worker maže pokusy o přihlášení starší 2 dnů; zálohy před importem se drží posledních 10; tělo POST zahozené kvůli `post_max_size` vrací 413 se srozumitelnou hláškou.
9. **Vědomě neřešeno v aplikaci:** HSTS (patří do konfigurace webového serveru), reverzní proxy (aplikace nedůvěřuje hlavičkám `X-Forwarded-*`, protože by šly podvrhnout), retence auditu. Popsané v `docs/NAVOD.md`.
10. **Release 0.1.0:** `VERSION` je `0.1.0`. Tag `v0.1.0` agent nevytváří: CI z tagu sestaví a zveřejní release, to má proběhnout až z `main` po sloučení všech fází.
11. **Hostový agent** je podle SPEC kap. 14 mimo rozsah MVP. Vlastník o něj 21. 10. 2026 výslovně požádal. Aby release 0.1.0 odpovídal SPEC, bude agent samostatná fáze F6 po release; `SPEC.md` se nemění, návrh je v `docs/STAV.md` (Další krok).

## 2026-10-21 (F6 Hostový agent)

Vlastník fázi zadal výslovně ("jen linux, nvidia ano, teploty ano, stav opotřebení disku a hlášení problémů, zaplnění disku"), i když SPEC kap. 14 hostového agenta řadí mimo MVP. `SPEC.md` se nemění, rozsah je popsaný zde a v `docs/STAV.md`.

1. **Agent je skript pro bash + curl**, ne binárka ani PHP: na serverech nic dalšího instalovat nemusí a kód jde přečíst. Spouští ho systemd timer nebo cron každou minutu; démon by potřeboval vlastní hlídání.
2. **CPU a síť se počítají z rozdílu čítačů od minulého běhu** (uložené ve stavovém adresáři), takže hodnota je průměr za minutu a skript nemusí čekat. První běh tyto hodnoty nemá.
3. **SMART posílá agent jako syrový `smartctl -j` a rozbor dělá server** (PHP je testovatelné a opravy nevyžadují aktualizaci agentů). Čte se jednou za 15 minut; hlášení bez SMART ponechá na serveru poslední stav. Při chybě odeslání se SMART pošle znovu příště.
4. **První hlášení založí objekt typu Server** (podle zdroje tokenu a `external_id`, výchozí `host:<hostname>`) a kontrolu Agent s výchozími prahy. Nejde o automatické objevování sítě: objekt založí jen agent nainstalovaný adminem s jeho tokenem. Připojení k existujícímu objektu přes `NORDVARR_OBJECT_ID`; když `external_id` už patří jinému objektu, API vrátí 422.
5. **Kontrola Agent:** interval 60 s, tolerance 180 s, práh 3 hlášení po sobě, notifikace i při stavu omezeně. Prahy viz `docs/NAVOD.md`. U teplot platí nižší z prahu kontroly a kritické meze čidla; mez `max` čidla znamená omezeně. Při stejné závažnosti určuje kód důvodu pořadí: selhávající disk, teplota, zaplnění, opotřebení, chyby disku, RAM, CPU. Detail výsledku obsahuje všechny problémy (max. 300 znaků).
6. **Opotřebení:** NVMe `percentage_used`, u ATA normalizovaná hodnota atributů 177, 202, 231 nebo 233 (100 - hodnota). Chyby (přemapované, čekající, neopravitelné sektory, chyby média, chyby v logu) jsou omezeně, selhání podle SMART, atribut pod prahem výrobce a kritické varování NVMe jsou nefunguje.
7. **Čas hlášení** je čas agenta, jen když se od času serveru liší nejvýš o 5 minut; jinak čas příjmu (rozladěné hodiny serveru nesmí rozbít graf ani hlídání).
8. **Ukládání:** poslední hlášení a SMART v `agent_hosts`, souhrnné hodnoty každého hlášení v `agent_samples` (48 h, jeden řádek na hlášení), hodinové souhrny v `agent_hourly` (400 dní) počítá hodinová údržba workeru. Podrobné hodnoty (jednotlivé disky, čidla) se drží jen z posledního hlášení.
9. **Přestavba tabulky `checks`** v migraci 0005 (SQLite neumí změnit omezení CHECK). Migrator proto nově vypíná cizí klíče mimo transakci, po migraci spustí `PRAGMA foreign_key_check` a klíče zase zapne (postup z dokumentace SQLite); jinak by DROP TABLE kaskádově smazal výsledky a alerty.
10. **Pruhy a graf jsou SVG generované na serveru** (šířky jako atributy), protože CSP zakazuje inline styly. Graf za 24 h je v 5minutových úsecích, mezera v datech přeruší čáru.
11. **Soubory agenta jsou veřejně ke stažení** na `/agent/<soubor>` (jen pevný seznam souborů), aby šel agent nainstalovat příkazem curl přímo na serveru bez přihlášení. Neobsahují nic tajného.
12. **Verze:** agent je nová funkce nad MVP, vydává se jako 0.2.0. Release 0.1.0 se samostatně nevydal: vlastník rozhodl vydat rovnou 0.2.0 se všemi fázemi F0 až F6.
13. **Test omezení pokusů při změně hesla** (F5) byl závislý na rychlosti stroje a na tom, jestli se pokus trefí do stejné sekundy jako přihlášení; přepsán deterministicky, oprava je i na větvi F5.

## 2026-10-23 (oprava po nasazení, 0.2.1)

1. **Datový adresář smí být uvnitř adresáře aplikace** (mimo složky s kódem `public`, `src`, `templates`, `lang`, `migrations`, `bin`, `agent`, `vendor`). Nahrazuje F0 bod 8. Důvod: při instalaci na server, kde webový server zapisuje jen do adresáře domény (vlastník nepoužívá Plesk, podobnost cesty `/var/www/vhosts/...` je náhoda), takže adresář vedle aplikace vytvořit nešel a uvnitř aplikace ho instalátor zakazoval; instalace nebyla možná. Riziko přepsání při aktualizaci je malé (nahrání souborů přes staré nic nemaže), adresář chrání `.htaccess` v kořeni aplikace i jeho vlastní, a pokud by ležel ve webrootu, instalátor dál varuje a zkouší ho stáhnout přes HTTP.
2. **Návrh datového adresáře** bere první použitelnou možnost: `nordvarr-data` vedle aplikace, potom `nordvarr-data` v adresáři aplikace. Použitelná je možnost, kterou webový server smí vytvořit nebo do ní zapisovat a kterou povoluje `open_basedir`; přednost mají možnosti mimo webroot.
3. **Instalátor ověří zapisovatelnost** datového adresáře už při kontrole formuláře a srozumitelně napíše, proč adresář nejde použít (dřív se zobrazila jen technická chyba "Adresář nelze vytvořit").

## 2026-10-23 (F7 Rychlé nastavení monitoringu)

Vlastník po nasazení napsal, že má objekty bez kontrol a neví, jak hlídání zapnout, a zadal pokračování dalšími milníky. SPEC nic takového nepředepisuje, `SPEC.md` se nemění.

1. **Nic se nezakládá samo.** Návrhy se jen ukážou a admin je potvrdí (na kartě objektu výběrem, v inventáři výběrem objektů). Jinak by úprava názvu nebo atributu tiše přidávala kontroly a alerty.
2. **Pravidla návrhů:** web -> HTTP na atribut `url`/`adresa`, jinak `https://<název>/` (jen když název je jméno hosta s tečkou, ne IP), u https certifikát TLS; doména -> expirace domény z názvu; server, VM, síťové zařízení -> ping na `ip`/`ipv4`/`ipv6`/`host`/`hostname`, jinak na název (IP nebo jméno s tečkou); služba, databáze, jiné -> HTTP na `url` (+ TLS), bez `url` TCP na `host`/`ip`/`server` a `port`. U služby se z názvu nic nehádá. Klíče atributů se porovnávají bez ohledu na velikost písmen, použije se platná hodnota (při rozporu ruční).
3. **Ping místo TCP u serverů:** nevyžaduje znát port. Kde ping na serveru Nordvarru nejde (hosting), návod doporučuje TCP.
4. **Nové kontroly mají výchozí nastavení typu** (interval, práh, notifikace), stejně jako ruční formulář bez úprav. Duplicita se pozná podle typu a cíle kontroly.
5. **Hromadné tlačítko v inventáři** používá stejný formulář jako hromadná úprava (`formaction`), aby výběr objektů fungoval bez JavaScriptu.
6. **Verze 0.3.0:** nová funkce nad 0.2.1.

## 2026-11-02 (F8 Patra v mapě)

Vlastník navrhl 3D zobrazení vrstev infrastruktury. Rozbor a návrh jsou v `docs/NAVRH-3D-MAPA.md`. Vlastník s doporučeními souhlasil: patra nad sebou (ne slupky v sobě), projekty jako tagy, logy mimo rozsah, 3D jako druhý pohled vedle 2D, začít krokem A (patra ve 2D). `SPEC.md` se nemění.

1. **Patra nejdřív ve 2D** ve stávající mapě Cytoscape. Ověří model vrstev na skutečných datech bez nové knihovny. Výpočet rozvržení je na serveru a použije ho i případný 3D pohled.
2. **Sedm pater zdola:** hardware a síť (`server`, `network_device`), virtuální stroje a VPS (`vm`), kontejnery, služby a databáze (`service`, `database`), weby a API (`website`), domény (`domain`), internet a externí služby (`other`).
3. **Nové typy objektů (kontejner, cluster) se zatím nepřidávají.** Vyžadovaly by přestavbu tabulky `objects` (omezení CHECK) a úpravy všude, kde se typy vyjmenovávají. Místo toho jde patro přepsat atributem `vrstva` (také `patro` nebo `layer`), takže služba v Dockeru může ležet v patře kontejnerů. Neplatná hodnota se ignoruje. Při rozporu zdrojů platí ruční hodnota, stejně jako u ostatních atributů.
4. **Rozvržení je deterministické** (řádek = patro, vodorovná poloha = průměr poloh sousedů v jiných patrech, šest průchodů střídavě zdola a shora, minimální rozestup 1, shody podle názvu). Vazby uvnitř patra polohu neurčují. Stejná data dají vždy stejnou mapu, ruční pozice se dál neukládají (SPEC kap. 14).
5. **Patro s více než 10 objekty se zalomí** do dalších řádků, jinak by byl pás při filtru přes celý inventář příliš široký a text nečitelný.
6. **Prázdná patra se nekreslí.** Řádky se v prohlížeči roztáhnou podle poměru stran mapy (nejvýš na dvojnásobek), aby široký graf nezůstal plochý.
7. **Volba rozvržení** je parametr adresy `layout=floors` (žádné ukládání do profilu). Výchozí zůstává automatické rozvržení.
8. **Verze 0.4.0.**

