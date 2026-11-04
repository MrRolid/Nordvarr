# Stav práce

Aktualizováno: 2026-11-03

## Hotové fáze

### F13 Vlastní stav kontejneru (větev `faze-13-stav-kontejneru`)

Kandidát z F12 (vlastník: "slouč a pokračuj").

- Typ kontroly `container` (migrace `0007_kontrola_kontejneru.sql` přestaví tabulku `checks`, stejně jako 0005). Kontrolu zakládá agent u každého objektu kontejneru, ručně ji založit nejde (`CheckTypes::MANUAL_TYPES`), upravit jde toleranci, práh a notifikace.
- `Agent\ContainerEvaluator`: stav kontejneru -> stav kontroly (tabulka v `docs/NAVOD.md`). Každé hlášení serveru zapíše výsledek všem jeho kontejnerům, zmizelý kontejner dostane jednou `container_missing`. Worker hlídá, jestli server hlásí (`agent_missing`, stav neznámý).
- Kontrola Agent serveru už problémy kontejnerů nehlásí (přesunuto na kontejnery). Vztah "server obsahuje kontejner" dává potlačení alertů a pravděpodobnou příčinu.
- Testy: `ContainerEvaluatorTest`, `AgentContainersTest` (stav každého kontejneru po dvou hlášeních, `container_missing` jednou, ruční založení odmítnuto, worker při výpadku serveru), `ContainerCheckMigrationTest` (migrace nad daty z 0001 až 0006), e2e `agent.spec.js` (kontrola na kartě kontejneru, alert serveru vedle alertů kontejnerů).

### F12 Kontejnery Dockeru z agenta (větev `faze-12-docker`)

Další krok po návrhu 3D mapy (vlastník: "slouč a pokračuj" po F11, kandidát z F11).

- Agent 1.1.0: když je na serveru `docker` a agent k němu má přístup, posílá výstup `docker ps -a --format {{json .}}` (nejvýš 200 kontejnerů, časový limit 10 s). Bez přístupu posílá `containers_error`. `NORDVARR_DOCKER` mění cestu, `0` hlášení vypne.
- Server: `Report` z výstupu dockeru udělá jméno, obraz, stav, healthcheck, návratový kód a projekt compose. Neplatná jména a duplicity zahodí.
- `AgentService` udržuje kontejnery jako objekty typu kontejner pod serverem (vztah obsahuje, atributy se zdrojem `agent`, externí id `docker:<externí id serveru>/<jméno>`). Zmizelý kontejner se archivuje, vrácený se obnoví. Hlášení bez seznamu (starší agent) nic nemění.
- `HostEvaluator`: problém `container_down` (omezeně) pro restartující se, mrtvé, s chybou ukončené kontejnery a neúspěšný healthcheck.
- Karta serveru: tabulka kontejnerů, upozornění na chybějící přístup k dockeru.
- Testy: `AgentScriptTest` (sběr z falešného dockeru, vypnutí, docker bez přístupu, docker chybí, rozbor na serveru), `AgentContainersTest` (objekty, atributy, archivace a obnovení, starší agent, problémy kontroly, karta, neplatné vstupy), e2e `agent.spec.js` (tabulka a vztah na kartě serveru).

### F11 Kontejnery a clustery, metriky ve 3D (větev `faze-11-kontejnery`)

Krok D z `docs/NAVRH-3D-MAPA.md` (vlastník: "slouč a pokračuj" po F10). Tím je návrh 3D mapy celý hotový.

- Nové typy objektů `cluster` a `container` (UI, API, import, filtry). Migrace `0006_kontejnery.sql` přestaví tabulku `objects` (nové omezení CHECK), data i cizí klíče zůstávají.
- Oba typy leží v patře kontejnerů. Doporučené kontroly jako u služby (HTTP na `url`, jinak TCP na `host` + `port`).
- Ukázková data: cluster `k3s na mon1` (ve VM mon1) s kontejnery prometheus a grafana (43 objektů místo 40).
- 3D: server s agentem má pod názvem vytížení CPU, RAM a GPU z posledního hlášení, obnovuje se s daty.
- Testy: `ContainerTypesMigrationTest` (migrace nad daty ze 0001 až 0005: objekty, vztahy, atributy a kontroly zůstanou, nové typy projdou, kaskádové mazání funguje, `foreign_key_check` je prázdný), `FloorsTest`, `SuggestionsTest`, upravené počty ukázkových dat (`DemoDataTest`, `TransferTest`, `InventoryUiTest`), e2e `agent.spec.js` (metriky u serveru ve 3D).

### F10 Hledání, patra a režim problému v mapě (větev `faze-10-hledani-problem`)

Krok C z `docs/NAVRH-3D-MAPA.md` (vlastník: "slouč a pokračuj" po F9).

- Hledání nad seznamem objektů v topologii: filtr bez ohledu na diakritiku a velikost písmen, Enter vybere první shodu a ukáže ji (ve 3D přelet kamery), Esc zruší.
- Patra v mapě: zaškrtávátka pater z dat, vypnuté patro zmizí v automatickém rozvržení, v patrech i ve 3D (s pásem, popisky a hranami). Seznam pater se obnovuje s daty.
- Režim problému (tlačítko K příčině): nejkratší cesta od objektu k pravděpodobné příčině (Cytoscape aStar, bez ohledu na směr vztahů), ztlumení zbytku, výběr příčiny, ve 2D přiblížení na cestu, ve 3D natočení kamery a zvýraznění cesty. Vypne se druhým kliknutím, výběrem jiného objektu nebo změnou grafu.
- Testy: `TopologyTest` (nové ovládací prvky), e2e v `topology.spec.js` (hledání s diakritikou a prázdným výsledkem, režim problému ve 2D i 3D, vypnutí patra ve 2D i 3D, ukončení režimu výběrem).

### F9 3D pohled na patra (větev `faze-9-3d-mapa`)

Krok B z `docs/NAVRH-3D-MAPA.md` (vlastník: "slouč a pokračuj", 2. 11. 2026): 3D prototyp jen pro čtení nad daty z F8.

- `public/assets/js/topology3d.js` nad three.js 0.170.0 (`assets/vendor/three-0.170.0`, MIT, záznam ve `VERSIONS.md`). Modul se načte až při prvním přepnutí na 3D.
- Patra jako průhledné disky nad sebou s osou, objekty jako koule na obvodu (úhel z vodorovné polohy F8, zalomená patra na menším poloměru), barva podle stavu, centrum okolí větší, vybraný objekt s obrysem. Hrany: "závisí na" plná čára, "obsahuje" čárkovaná, cesta k příčině oranžová.
- Popisky objektů a pater jako HTML nad plátnem (ostrý text). Klik na popisek vybere objekt.
- Ovládání bez další knihovny: tažení otáčí, kolečko a dva prsty přibližují, klik vybere (karta vpravo), dvojklik otevře kartu, najetí ztlumí vše mimo sousedy. Klik v seznamu natočí mapu k objektu. Tlačítka Pohled shora, Roztáhnout patra, Celý graf.
- Obnovování dat (ETag) aktualizuje i 3D: barvy beze změny scény, při změně objektů nebo vztahů se scéna postaví znovu.
- Bez WebGL zůstane mapa v patrech s upozorněním.
- Testy: `TopologyTest` (přepínač 3D, modul s verzí v adrese, skryté ovládání), `ReleaseBuildTest` (soubory three.js a modulu v zipu), e2e `topology.spec.js` (3D scéna, klik na kouli otevře kartu, výběr ze seznamu, tlačítka, znovunačtení, návrat do pater, prohlížeč bez WebGL).

### F8 Patra v mapě (větev `faze-8-patra`)

Krok A z `docs/NAVRH-3D-MAPA.md` (vlastník s návrhem souhlasil 2. 11. 2026): ověřit model vrstev ve 2D, než se začne s 3D.

- `Topology\Floors`: patro podle typu objektu, přepis atributem `vrstva` (klíč patra nebo číslo 1 až 7), deterministické rozvržení do řádků a sloupců (průměr poloh sousedů v jiných patrech, několik průchodů), zalomení patra s více než 10 objekty.
- Data topologie (stránka i `/topology/data`) mají u uzlu patro a polohu a seznam pater s popisky.
- Mapa: přepínač Automaticky / Patra, pásy pater v pozadí s popisky, řádky se roztáhnou na výšku mapy. Volba je v adrese (`layout=floors`) a formulář ji zachová.
- `AttributeRepository::validValues` načte platnou hodnotu atributu pro více objektů jedním dotazem.
- Testy: `FloorsTest` (patra, přepis, sloupce, rozestupy, determinismus, zalomení), `TopologyTest` (patra v datech, přepis atributem s rozporem zdrojů, přepínač na stránce), e2e v `topology.spec.js` (pásy, pořadí pater, klik na uzel, zachování volby).

### F7 Rychlé nastavení monitoringu (větev `faze-7-rychle-nastaveni`)

Fáze nad rámec SPEC na přání vlastníka po nasazení 0.2.1 ("jak tam přidám reálné monitoringy"). Cíl: z inventáře bez kontrol se k hlídání dostat na pár kliknutí.

- `Checks\Suggestions`: doporučené kontroly podle typu objektu, názvu a atributů (pravidla v ROZHODNUTI F7). Návrhy se validují stejně jako ruční kontroly a nenabízí se to, co už existující kontrola pokrývá.
- Karta objektu: blok "Doporučené kontroly" se zaškrtávátky a tlačítkem pro přidání vybraných; u serveru a VM bez agenta odkaz na Nastavení -> Agent pro servery.
- Inventář: hromadné tlačítko "Přidat doporučené kontroly" pro vybrané objekty (výsledek ve flash zprávě: kolik kontrol, u kolika objektů, kolik objektů bez návrhu).
- Přehled: počet objektů bez kontrol s odkazem na filtr inventáře.
- Každá přidaná kontrola se zapisuje do auditu (`check.create`, příznak `suggested`).
- `docs/NAVOD.md`: část "Jak zapnout hlídání" v kapitole 4.
- Testy: `SuggestionsTest` (pravidla, duplicity, neplatné cíle), `SuggestionsUiTest` (karta, hromadné přidání, tip v přehledu), e2e `suggestions.spec.js`. Výsledek: `composer test` a `npm run e2e` zelené.

### Oprava 0.2.1 (větev `oprava-datovy-adresar`)

- Instalace na server, kde webový server smí zapisovat jen do adresáře domény (aplikace v adresáři domény, webroot `public/`), selhala: instalátor navrhl datový adresář o úroveň výš, kam webový server nesmí zapisovat, a uvnitř aplikace ho zakazoval. Nově navrhne první zapisovatelné místo (vedle aplikace, jinak v adresáři aplikace vedle `public/`), respektuje `open_basedir` a zapisovatelnost ověří už ve formuláři. Viz ROZHODNUTI z 23. 10. 2026.
- Testy: `InstallerTest` (záložní návrh, vyhýbání se webrootu, `open_basedir`, zakázané složky s kódem, instalace do podadresáře aplikace).

### F6 Hostový agent (větev `faze-6-agent`, navazuje na `faze-5-export-bezpecnost`)

Fáze nad rámec SPEC na přání vlastníka (viz ROZHODNUTI F5 bod 11 a F6). Rozsah podle zadání: jen Linux, NVIDIA GPU, teploty, opotřebení disků a hlášení jejich problémů, zaplnění disků.

- **Agent** `agent/nordvarr-agent.sh` (bash + curl, bez dalších závislostí), systemd služba a časovač, vzor konfigurace:
  - CPU a síť jako průměr od minulého běhu (za minutu), load, RAM, swap, zaplnění disků a inodů, teploty všech čidel hwmon (s mezemi max a crit), NVIDIA GPU přes `nvidia-smi`,
  - SMART jednou za 15 minut: agent posílá výstup `smartctl -j`, rozbor dělá server,
  - konfigurace se jen čte (nespouští), token jde curl přes soubor s právy 600, poslední chyba v `last-error`,
  - režimy `--test`, `--dry-run`, `--version`.
- **Server:**
  - API `POST /api/v1/agent/report` (scope `agent:write`): ověření a normalizace hlášení, první hlášení založí objekt typu Server (nebo se připojí k objektu z `NORDVARR_OBJECT_ID`) a kontrolu typu **Agent**,
  - rozbor SMART pro ATA/SATA i NVMe: zdraví, opotřebení (atributy 177, 202, 231, 233, NVMe `percentage_used`), teplota, provozní hodiny, přemapované, čekající a neopravitelné sektory, chyby média, kritická varování NVMe, chyby v logu disku,
  - kontrola Agent s nastavitelnými prahy a novými kódy důvodů `agent_missing`, `cpu_high`, `memory_high`, `disk_full`, `temperature_high`, `disk_failing`, `disk_wear`, `disk_errors`; vyhodnocuje se hned při hlášení, chybějící hlášení hlídá worker, fungují alerty, potlačení i údržba,
  - migrace `0005_agent.sql` (přestavba tabulky `checks` kvůli novému typu, tabulky `agent_hosts`, `agent_samples` 48 h, `agent_hourly` 400 dní), Migrator nově spouští migrace s vypnutými cizími klíči a po migraci je ověří.
- **UI:**
  - na kartě objektu sekce Server (agent): ukazatele CPU, RAM, swap, nejplnější disk, nejvyšší teplota a každá GPU, graf za 24 h (CPU, RAM, teplota, GPU), zaplnění disků, stav disků ze SMART, všechna čidla, síť,
  - v topologii popisek uzlu s CPU, RAM a GPU a pruhy hodnot v panelu uzlu,
  - sekce v exportu pro LLM,
  - Nastavení -> Agent pro servery s návodem a adresami ke stažení; soubory agenta jsou veřejné na `/agent/...` (neobsahují nic tajného),
  - pole prahů ve formuláři kontroly.
- `docs/NAVOD.md` kapitola 12 (instalace, konfigurace, prahy, API). Agent je součástí release zipu (`nordvarr/agent/`). Vydáno jako release 0.2.0.

Ověření:

- normalizace hlášení, rozbor SMART nad vzorky výstupu `smartctl -j` (zdravé SSD, selhávající HDD, opotřebené NVMe s chybami), prahy, priorita důvodů: `AgentEvaluationTest`,
- API (založení serveru a kontroly, připojení k objektu, konflikt, oprávnění, validace), práh 3 hlášení a alert, chybějící agent přes worker, agregace a retence, migrace 0005 se zachováním výsledků a alertů a funkční kaskádou: `AgentApiTest`,
- skript agenta nad falešným `/proc` a `/sys` a falešnými `df`, `nvidia-smi` a `smartctl`: hodnoty, výpočet CPU a sítě ze dvou běhů, mezera v cestě disku, SMART a jeho interval, konfigurace se nespouští, odeslání s tokenem v hlavičce na lokální mock, chyba odeslání: `AgentScriptTest`,
- karta, topologie, export, formulář prahů, návod, veřejné soubory agenta, žádné inline styly: `AgentUiTest`,
- e2e `agent.spec.js`: token se scope `agent:write` z UI, skutečný skript ze zipu nad falešným `/proc` třikrát pošle hlášení do nainstalované aplikace -> karta s metrikami, SMART "selhává", stav nefunguje (`disk_failing`), alert po běhu workeru, panel v topologii; mobil bez vodorovného posuvníku,
- vzhled na snímcích s ukázkovými daty (tmavý, světlý, mobil),
- testy zelené: `composer test` (468 testů), `npm run e2e` (30 testů).

### F5 Export, bezpečnost, dokumentace (větev `faze-5-export-bezpecnost`, navazuje na `faze-4-topologie`)

- **Export pro LLM** (SPEC kap. 10), `Nordvarr\Export\LlmContext`:
  - tlačítko "Zkopírovat pro LLM" na kartě objektu vede na stránku s Markdownem a tlačítkem "Kopírovat" (`navigator.clipboard`, bez HTTPS se text označí k ručnímu zkopírování),
  - obsah: identifikace, typ, tagy, externí id, stav a důvod, otevřené alerty s pravděpodobnou příčinou, souhrn kontrol za 24 h z hodinových agregací, okolí do 2 kroků v obou směrech (max. 30, řazeno nezdravé -> nedávno změněné -> bližší), události z okolí za 7 dní (max. 30), atributy včetně rozporů, dokumentace (max. 4000 znaků),
  - velikost nejvýš 24 kB pro Markdown i JSON (postupně se zkracuje dokumentace, události, okolí, atributy),
  - API `GET /api/v1/objects/{id}/context` (JSON, nebo Markdown s `?format=markdown`), nový scope `context:read`.
- **Přísné maskování** (`SecretMasker::maskStrict`): klíče atributů s `pass`, `pwd`, `secret`, `token`, `key`, `private`; přihlašovací údaje v URL; hesla v DSN; dvojice `klíč: hodnota` s tajným klíčem v textu (i `heslo:`); `Bearer` tokeny; bloky PEM privátních klíčů; řetězce s vysokou entropií (aspoň 20 znaků, malá i velká písmena a číslice, entropie aspoň 3,5 bitu na znak) a hex řetězce delší než 40 znaků. UUID, hash commitu, cesty a názvy hostů zůstanou.
- **Účet:** změna hesla v Nastavení -> Účet (ověření současného hesla s omezením pokusů, ostatní přihlášení se zneplatní, audit) a příkaz `php bin/nordvarr.php reset-password <e-mail>` pro zapomenuté heslo.
- **Bezpečnostní průchod** podle kap. 11 (viz níže) s opravami a regresními testy.
- **`docs/NAVOD.md`:** požadavky, instalace (včetně Apache), cron a worker, první kroky, aktualizace, zálohy a obnova, zapomenuté heslo, API s curl (upsert, události, kontext, heartbeat, chybové kódy), konfigurace, bezpečnost, řešení problémů. Příklady curl jsou ověřené proti běžící instanci.
- **Release 0.1.0:** `VERSION` je `0.1.0`. Release zip sestaví CI po pushnutí tagu `v0.1.0` (tag se má vytvořit až na `main` po sloučení fází).

Ověření:

- kanárkové testy: 14 nastražených tajných hodnot (atribut, URL, DSN, náhodný token, hex tajemství, `password:` a `Bearer` v dokumentaci, PEM klíč, `aws_secret_access_key`, událost, popis, URL kontroly, detail důvodu, popis souseda) se neobjeví v Markdownu, JSON, na stránce exportu ani v API; test zároveň hlídá, že běžný text (commit, cesta, název hosta) maskovaný není. Ověřeno i obráceně: po vypnutí maskování podle entropie test selže. `LlmExportTest`, `SecretMaskerTest`,
- obsah exportu, řazení okolí, limit 24 kB, prázdný objekt, API se scope (200, 401, 403, 404, Markdown): `LlmExportTest`,
- změna hesla, zneplatnění jiných session, omezení pokusů, CLI reset: `AccountTest`,
- CSRF u všech 34 POST adres UI (seznam se čte ze zdrojového kódu, nová adresa se otestuje sama): `AppTest`,
- opravy z bezpečnostního průchodu: `SecurityPassTest`, `MarkdownTest`,
- e2e `export.spec.js`: tlačítko na kartě, Kopírovat do schránky (obsah schránky = text exportu), tajné heslo atributu maskované,
- testy zelené: `composer test` (419 testů), `npm run e2e` (27 testů).

#### Bezpečnostní průchod (SPEC kap. 11)

Kód prošla nezávislá kontrola (samostatný agent jen pro čtení) proti každému bodu kapitoly 11 a obvyklým chybám webových aplikací.

| Bod kap. 11 | Stav |
|---|---|
| Hesla Argon2id (jinak bcrypt) | v pořádku; nově se neověřuje heslo delší než 4096 bajtů (ochrana procesoru) |
| Session: HttpOnly, SameSite=Lax, Secure na HTTPS, nové id po přihlášení, zneplatnění po změně hesla | v pořádku; chyběla možnost heslo změnit -> **doplněno** (Nastavení -> Účet, CLI `reset-password`) |
| CSRF u každého POST | v pořádku (kontrola v `App` před směrováním, `hash_equals`); nově test všech POST adres |
| Omezení přihlašování | v pořádku; staré záznamy pokusů nově maže worker (po 2 dnech) |
| API tokeny jako prefix + HMAC-SHA256 | v pořádku |
| Datový adresář mimo webroot, `.htaccess` | v pořádku; **opraveno**: soubory vznikaly s výchozími právy (často čitelné pro všechny), nově `umask 0027`, `config.php` dostane práva 0640 ještě před zápisem tajemství |
| Hlavičky CSP, X-Frame-Options, Referrer-Policy, nosniff | v pořádku na všech odpovědích (HTML, JSON, API, heartbeat, instalátor, chyby, přesměrování) |
| Audit | **doplněno**: odebrání tagu, archivace a obnovení objektu, změna a obnova hesla |

Další zjištění a opravy:

- **Přesměrování na cizí web přes přepínač jazyka** (střední až nízká závažnost): cesta `/\evil.example` prošla jako místní adresa a prohlížeč ji čte jako `//evil.example`. Opraveno: cesty se zpětným lomítkem nebo řídicími znaky se nahradí `/`.
- **Odkaz v Markdownu `/\host`** se považoval za relativní. Opraveno.
- **`install.php` bez zachycení výjimek:** neočekávaná chyba mohla ukázat výpis PHP. Opraveno (`display_errors` vypnuté v `index.php` i `install.php`, chybová stránka).
- **Import kontrol** ukládal konfiguraci ze souboru bez normalizace. Opraveno (ukládá se ověřená konfigurace).
- **Import:** zálohy před importem se nově rotují (posledních 10) a příliš velký soubor (nad `post_max_size`) hlásí srozumitelnou chybu 413 místo chyby CSRF.
- Ověřeno bez nálezu: SQL jen přes parametry, escapování výstupu a Markdownu, role viewer bez POST, heartbeat token (128 bitů), tajemství kanálů šifrovaná a nikde nevypisovaná, API bez session a cookies, UI odmítá bearer tokeny.

Nechané beze změny (popsané v `docs/NAVOD.md`, kap. 10): HSTS patří do konfigurace webového serveru; za reverzní proxy aplikace vidí IP proxy a nepozná HTTPS; tabulka auditu nemá retenci; kontroly a webhooky smí mířit na interní adresy (záměr, nastavuje je jen admin).

### F4 Topologie a diagnostická karta (větev `faze-4-topologie`, navazuje na `faze-3-alerty`)

- Stránka Topologie (`/topology`) s grafem v Cytoscape.js a rozvržením dagre (knihovny s pevnou verzí v `public/assets/vendor/`, záznam ve `VERSIONS.md`):
  - režim "okolí objektu" (1 nebo 2 kroky, oba směry, oba druhy vztahů); výchozí střed je objekt s nejstarším nepotlačeným alertem `unhealthy`, pak `degraded`, jinak objekt s nejvíce vztahy,
  - režim "podle filtru" (typ, tag, stav), max. 300 uzlů, nad limitem výzva k zúžení filtru,
  - uzel: kruh s prstencem podílu stavů kontrol, název a druhý řádek (latence, stav nebo typ), záře u `unhealthy`/`degraded`, čárkovaně pozastavené a objekty v údržbě, zvýrazněný střed,
  - hrany: `závisí na` se šipkou, `obsahuje` čárkovaně, cesta k pravděpodobné příčině oranžově a silněji,
  - levý panel: formulář pohledu a seznam uzlů seřazený od nejhoršího stavu; pravý panel: karta vybraného uzlu (stav a důvod, pravděpodobná příčina, tagy, kontroly s latencí, incidenty za 90 dní, poslední změny i u sousedů, dokumentace),
  - klik na uzel otevře kartu v panelu, dvojklik nebo "Otevřít kartu" vede na detail objektu, najetí myší zvýrazní okolí uzlu,
  - obnovování dat grafu přes `/topology/data` s ETag (15 s / 60 s / zpomalení při chybách), změna stavu bez přestavění grafu, změna struktury s novým rozvržením,
  - světlý i tmavý vzhled, mapa přes celou šířku okna, na mobilu pod sebou.
- Diagnostická hlavička karty objektu (SPEC kap. 9 bod 3): stav a důvod, pravděpodobná příčina, poslední změna na objektu a přímých sousedech za 24 h, stav závislostí, počet alertů za 90 dní. Obnovuje se přes `/objects/{id}/diagnosis` s ETag. Pod kartou nová sekce "Historie incidentů (90 dní)" a tlačítko "Zobrazit v topologii".
- Obnovování přes ETag je společné (`Nordvarr\Ui\JsonFragment`, v `app.js` pro každý prvek s `data-refresh-url`).
- Oprava: detail zmeškaného heartbeatu ukazoval čas posledního signálu v UTC ve formátu ISO, teď je v časové zóně z nastavení.

Ověření:

- okolí do hloubky 1 a 2, ořez velkého okolí s upozorněním, příčinná hrana, data uzlu, výchozí střed, filtr podle stavu, limit 300 uzlů, stránka, JSON endpointy s ETag a 304, karta uzlu s dokumentací a incidenty, diagnostická hlavička: `TopologyTest`,
- obsah release zipu včetně knihoven grafu: `ReleaseBuildTest`,
- akceptace e2e `topology.spec.js`: "spadne databáze -> web je unhealthy, notifikace webu je potlačena (lokální přijímač webhooků dostane jen databázi), karta webu ukazuje databázi jako pravděpodobnou příčinu a poslední deploy v okolí"; dál graf okolí s příčinnou hranou, klik na uzel v grafu i v seznamu, filtrový režim, ETag, samočinné obnovení mapy, žádné chyby v konzoli (CSP); `mobile.spec.js`: topologie na mobilu bez vodorovného posuvníku,
- vzhled kontrolován na snímcích obrazovky s ukázkovými daty (světlý i tmavý režim),
- testy zelené: `composer test` (391 testů), `npm run e2e` (26 testů).

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
- Po nahrání nové verze se migrace spustí až při prvním požadavku přihlášeného admina (SPEC kap. 12). Do té doby API vrací 500, protože nové tabulky ještě neexistují. Řešení: po aktualizaci se přihlásit nebo spustit `php bin/nordvarr.php migrate` (popsané v `docs/NAVOD.md`).
- Seznam objektů ve výběru cíle vztahu na detailu objektu obsahuje všechny objekty; pro cílovou velikost (nízké stovky) to stačí, pro víc objektů by bylo potřeba vyhledávání.
- Správa dalších uživatelů v nastavení chybí, MVP má jednoho admina; heslo jde změnit v Nastavení -> Účet (viz ROZHODNUTI F1 bod 14 a F5).
- Detail chyby u síťových kontrol přebírá anglický text z knihovny curl (např. "Failed to connect ..."); kód důvodu je vždy z pevného seznamu.
- Souhrn za 24 h v detailu kontroly se počítá z hodinových agregací, aktuální hodina se v něm objeví až po hodinové údržbě workeru.
- Kontrola `tls_expiry` podporuje jen přímé TLS (např. 443, 465, 993), ne STARTTLS.
- Chyba DNS u kontrol přes curl není pokrytá automatickým testem (vyžadovala by skutečný DNS dotaz).
- Alerty se vyhodnocují při běhu workeru, takže signál heartbeatu nebo ruční změna se do alertů a notifikací promítne nejpozději do minuty.
- Skutečné odeslání přes veřejné SMTP servery, Telegram a cizí webhooky se netestovalo (jen lokální falešné servery); na produkci doporučuji po nastavení kanálu použít "Odeslat test".
- Změna `app_secret` v `config.php` znemožní dešifrovat tajné údaje kanálů, je pak potřeba je zadat znovu.
- Mapa topologie ukazuje stav, latenci kontrol, alerty a příčiny. Metriky jako CPU, RAM nebo GPU by potřebovaly hostového agenta, který je mimo rozsah MVP (SPEC kap. 14).
- Rozvržení grafu se při každém načtení počítá znovu (ruční pozice se podle SPEC neukládají). U velkých grafů v režimu filtru (nad 24 uzlů) se místo dagre použije silové rozložení, viz ROZHODNUTI F4 bod 3. U stovek uzlů jsou popisky malé, je potřeba přiblížit.
- Maskování podle entropie je heuristika: krátké nebo slovníkové tajné hodnoty v textu bez klíče (např. "heslo je zahrada") nepozná. Atributy s citlivým klíčem a dvojice `klíč: hodnota` maskuje vždy.
- Záznamy auditu (včetně neúspěšných přihlášení) se nemažou; při dlouhodobém zkoušení hesel tabulka `audit_log` roste. Tabulku pokusů o přihlášení worker čistí.
- Agent: jen Linux s bash 4.4+. Bez root práv a bez `smartmontools` chybí stav disků (karta to napíše). Virtuální disky ve VM obvykle SMART nemají. Disky za RAID řadičem vyžadují pro `smartctl` zvláštní typ zařízení (`-d megaraid,N` apod.), který `smartctl --scan` někdy nenabídne.
- Agent: archivované kontejnery se v okolí objektu v mapě dál zobrazují (jako archivované mají stav pozastaveno, poslední výsledek kontroly je `container_missing`), stejně jako jiné archivované objekty se vztahy.
- Agent: popisy problémů (např. "přemapované sektory: 1872") jsou uložené česky i pro anglické UI, stejně jako detaily ostatních kontrol.
- 3D pohled: popisky se při mnoha objektech na jednom patře překrývají (pomůže přiblížení nebo Roztáhnout patra). Čáry mají v WebGL vždy šířku 1 px, cesta k příčině se proto liší jen barvou. Výkon je ověřený na ukázkových datech (40 objektů), ne na stovkách.
- Cytoscape vkládá na stránku vlastní `<style>`, který CSP blokuje. Obchází se to prázdným prvkem s id, které knihovna hledá, a pravidlem v `app.css` (ROZHODNUTI F4 bod 7). Při aktualizaci knihovny je potřeba to ověřit (e2e test hlídá chyby v konzoli).

## Další krok

- Release 0.9.0 (F8 až F13): vydat ručním spuštěním workflow CI na `main` (Actions -> CI -> Run workflow), tag vznikne podle `VERSION`. Vydání `v0.3.0` s tagem na starém commitu doporučuji smazat. Na serverech s agentem stáhnout agenta znovu (verze 1.1.0).
- Další práce podle zkušenosti vlastníka se skutečnými daty. Kandidáti: retence auditu, ověření `.htaccess` na skutečném Apache, agent pro další platformy.
