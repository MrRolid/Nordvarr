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
