# Návrh: vrstvená 3D mapa infrastruktury

Stav: návrh k rozhodnutí, nic z toho není implementované. Datum: 2026-11-02.

## 1. Nápad

Vlastník chce zobrazit vazby mezi objekty v 3D prostoru. Objekt mapy by byl vícevrstvý, například koule nebo válec s několika koulemi či válci v sobě. Každá vrstva by měla jeden druh objektů: hardware (disky, CPU, RAM), VPS, Docker, Kubernetes, internet, domény, projekty, dokumentace, logy. Model by šel natočit k místu, kde se řeší problém, přiblížit ho a hledat v něm. Inspirací je mapa clusteru ve 2D a ovládání z filmu Minority Report.

Motivace: s přibývajícími objekty se z 2D mapy stane změť čar.

## 2. Co je na nápadu dobré

- **Vrstvy odpovídají tomu, jak se o infrastruktuře přemýšlí:** železo -> virtuální stroje -> kontejnery -> služby -> weby -> domény -> internet. Kdo hledá příčinu výpadku webu, jde přesně touto cestou dolů.
- **Oddělí se dva druhy vazeb.** Vazba "obsahuje" vede většinou mezi sousedními vrstvami (server obsahuje VM), "závisí na" často napříč (web závisí na databázi). Když je výška daná vrstvou, je druh vazby vidět ze sklonu čáry.
- **Natočení k problému** je dobrý způsob, jak zaměřit pozornost a přitom neztratit souvislosti s okolím.
- **Technicky to jde.** WebGL umí každý současný prohlížeč a stovky uzlů s popisky jsou pro něj malá zátěž.

## 3. Na co si dát pozor

- **Zakrývání.** U koulí nebo válců v sobě jsou vnitřní vrstvy schované za vnějšími a z každé slupky je polovina odvrácená. V každém okamžiku je tak velká část objektů vzadu nebo za něčím. Průhlednost to zmírní, ale prolínající se čáry z několika slupek se čtou hůř než ve 2D.
- **Změť čar sama nezmizí.** 3D ji rozloží do prostoru, ale při otáčení se čáry znovu kříží. Hlavní lék je ukazovat méně: okolí vybraného objektu, cestu k příčině, filtr. Nordvarr to ve 2D už umí (F4) a ve 3D by to bylo potřeba také.
- **Perspektiva zkresluje.** Vzdálené objekty jsou menší a popisky se překrývají. Text v 3D se čte hůř než v rovině.
- **Ovládání.** Otáčení myší je pro rychlou kontrolu pomalejší než 2D posun a přiblížení, na telefonu ještě víc. 3D by proto mělo být druhý pohled vedle 2D a 2D by mělo zůstat výchozí.
- **Testy.** WebGL v testovacím prohlížeči běží softwarově a pomalu. Jde ověřit, že se scéna vykreslí, že v konzoli nejsou chyby a že klik a hledání fungují. Porovnávání snímků by bylo nestabilní.
- **Velikost.** Knihovna three.js má několik set kB. Vložit by se musela do `assets/vendor` s pevnou verzí (pravidla projektu, žádné CDN).

Shrnutí: 3D se vyplatí jako pohled na celek a jako cesta k problému. Pro rutinní práci s desítkami objektů stačí 2D s filtrem.

## 4. Varianty tvaru

| Varianta | Jak vypadá | Plus | Minus |
|---|---|---|---|
| A. Koule v sobě | každá vrstva je slupka koule | působivé | zakrývání, polovina vždy vzadu, těžko se v tom hledá |
| B. Válce v sobě | každá vrstva je plášť válce | výška pláště může nést další údaj | vnitřní pláště jsou schované stejně jako u koulí |
| C. Patra nad sebou (doporučeno) | válec rozřezaný na vodorovná patra, každé patro je jedna vrstva | při pohledu šikmo shora je vidět všechno, pohled shora dá 2D mapu, patra jdou roztáhnout | méně efektní než slupky |
| D. 2D prstence | vrstvy jako soustředné kružnice v rovině | žádné 3D, funguje všude | s přibývajícími objekty jsou vnější prstence přeplněné |

**Doporučení: varianta C.** Zachovává váš válec, jen vrstvy nejsou v sobě, ale nad sebou. Vzniká z toho přirozené uspořádání:

- **výška = vrstva** (hardware dole, internet nahoře),
- **úhel = "sloupec"**: všechno, co běží na jednom fyzickém stroji nebo u jednoho poskytovatele, leží nad sebou ve stejném směru. Fyzický server s VM, kontejnery a weby nad ním tak tvoří svislý sloupec a problém na železe je vidět jako problém celého sloupce,
- **vazba "obsahuje"** je pak skoro svislá krátká čára, **"závisí na"** šikmá čára mezi sloupci, takže se oba druhy vazeb na první pohled liší.

Rozvržení je deterministické (z dat, ne z náhodné simulace). Mapa proto vypadá pokaždé stejně, i když SPEC ukládání ručních pozic do MVP nezahrnuje.

## 5. Vrstvy v datech Nordvarru

Dnešní typy objektů: `server`, `vm`, `service`, `website`, `database`, `domain`, `network_device`, `other`.

Návrh pater (zdola nahoru):

| Patro | Obsah | Typy dnes | Doplnit |
|---|---|---|---|
| 1 Hardware a síť | fyzické servery, síťové prvky | `server`, `network_device` | |
| 2 Virtualizace | VM a VPS | `vm` | |
| 3 Kontejnery | Docker, Kubernetes (cluster, node, pod) | nic | typ `container`, případně `cluster` |
| 4 Služby a databáze | aplikace, fronty, DB | `service`, `database` | |
| 5 Weby a API | weby, veřejná API | `website` | |
| 6 Domény | domény a DNS | `domain` | |
| 7 Internet a externí služby | CDN, tunely, cizí API | `other` | případně typ `external` |

VPS u poskytovatele, u kterého neznáme hardware, leží v patře 2 ve sloupci "poskytovatel".

**Projekty, dokumentace a logy jako patra nedoporučuji.** Nejsou to vrstvy infrastruktury, týkají se objektů napříč patry:

- **projekt** je skupina objektů. Lépe funguje jako filtr nebo barva (zvýrazní své sloupce a ostatní ztlumí). Dnes se dá vyjádřit tagem,
- **dokumentace a historie incidentů** patří k objektu. Ukážou se v bočním panelu vybraného objektu (data už má karta objektu z F4),
- **logy** Nordvarr nesbírá. Sběr logů by byla nová velká oblast a fulltext přes historii je ve SPEC mimo MVP.

## 6. Ovládání (realistický Minority Report)

- otáčení tažením, přiblížení kolečkem nebo prsty, dvojklik = kamera přeletí k objektu,
- **hledání**: napíšete jméno, kamera k objektu přeletí a zvýrazní ho,
- **režim problému**: při otevřeném alertu se všechno mimo cestu "objekt -> pravděpodobná příčina" ztlumí a kamera se natočí k ní. Data o příčině už existují z F4,
- vypnutí a zapnutí jednotlivých pater, roztažení pater od sebe,
- **pohled shora** jedním tlačítkem (plynulý přechod do 2D),
- **boční panel** jako na referenční mapě: stav, metriky agenta (CPU, RAM, disky, teploty), alerty, dokumentace, poslední události.

Ovládání gesty rukou v prostoru (kamera, senzory) nenavrhuji.

## 7. Co převzít z referenční 2D mapy i bez 3D

- metriky přímo v uzlu (pruhy CPU, RAM, disk): Nordvarr je má od agenta (F6),
- souhrnný panel vpravo a panel externích služeb,
- latence na hranách: Nordvarr měří latenci kontrol ze svého serveru, ne mezi uzly navzájem. Hrany by mohly ukázat jen stav a latenci kontroly cílového objektu.

Referenční mapa je ručně rozvržená pro jeden cluster se sedmi uzly. Nordvarr musí umět rozvrhnout libovolná data, proto potřebuje pravidla (patra a sloupce) místo ručního umístění.

## 8. Technika

- **three.js** (licence MIT) s pevnou verzí v `public/assets/vendor`, se záznamem ve `VERSIONS.md`. Na pevné rozvržení stačí samotná knihovna a ovládání kamery. Knihovny se simulací sil (např. 3d-force-graph) nejsou potřeba.
- **Popisky** jako HTML vrstva nad scénou (CSS2DRenderer): text je ostrý a čitelný. Styly nastavuje skript přes CSSOM, což CSP bez inline stylů dovoluje. Je ale potřeba to ověřit, stejně jako se to řešilo u Cytoscape (ROZHODNUTI F4 bod 7).
- **Data** ze stávajícího JSON topologie (s ETag) doplněného o patro a sloupec. Rozvržení spočítá server v PHP, aby šlo pokrýt jednotkovými testy.
- **Výkon**: stovky uzlů bez problémů. Při víc než tisíci uzlech by bylo potřeba instancování, to ale cílová velikost Nordvarru (nízké stovky) nepotřebuje.
- **Záloha**: bez WebGL zůstane 2D mapa.

## 9. Postup po krocích

1. **Krok A, patra ve 2D (malý, ověřovací).** Současná 2D mapa dostane režim "vrstvy": vodorovné pásy podle pater, objekty ve sloupcích. Ukáže, jestli model pater sedí na skutečná data, a nic z toho se nezahodí.
2. **Krok B, 3D prototyp jen pro čtení.** Patra, sloupce, otáčení, přiblížení, klik -> boční panel. Vyzkoušet nejdřív na ukázkových datech, pak na vašich.
3. **Krok C, práce s problémem.** Hledání, režim problému, přepínání pater, přechod do pohledu shora.
4. **Krok D (volitelný).** Typy `container` a `cluster`, metriky agenta přímo na uzlech.

Mezi kroky A a B se dá rozhodnout podle zkušenosti, jestli 3D opravdu přidá víc než 2D s patry.

## 10. K rozhodnutí

1. Patra nad sebou (doporučeno), nebo slupky v sobě?
2. Seznam a pořadí pater, přidat typy `container` a `cluster`?
3. Projekty jako tagy (doporučeno), nebo nový typ objektu?
4. Logy mimo rozsah (doporučeno), nebo budoucí samostatná oblast?
5. 3D jako druhý pohled vedle 2D (doporučeno)?
6. Začít krokem A?

3D mapa není ve SPEC. Šlo by o další fázi nad rámec SPEC, stejně jako F6 a F7, se záznamem v `docs/ROZHODNUTI.md`.
