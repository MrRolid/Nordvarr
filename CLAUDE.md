# Nordvarr - pravidla pro agenta

## Na začátku každé session

1. Přečti `SPEC.md` (zdroj pravdy) a `docs/STAV.md` (stav práce). Pokud `docs/STAV.md` neexistuje, jsi ve fázi F0.
2. Zjisti, která fáze je další nedokončená, a pracuj jen na ní. Do další fáze se bez pokynu nepouštěj.

## Prostředí (cloud VM)

- Potřebuješ PHP 8.4 s rozšířeními pdo_sqlite, curl, openssl, mbstring, dále Composer, Node.js LTS a Playwright s Chromiem.
- Co chybí, nainstaluj (na Ubuntu PHP 8.4 z PPA ondrej/php). Každý příkaz, který jsi k instalaci potřeboval, zapiš do `scripts/setup-cloud.sh`, aby šel skript použít jako setup prostředí pro další session. Skript musí být idempotentní.
- Pokud instalace kvůli omezení sítě nejde, zastav se a v závěrečném shrnutí přesně napiš, jaký přístup k síti je potřeba povolit.
- Kód nesmí používat nic novějšího než PHP 8.4.

## Git a odevzdání

- Pracuj na nové větvi `faze-<N>-<kratky-popis>` (například `faze-0-kostra`), nikdy přímo na `main`.
- Commituj průběžně po logických krocích, zprávy commitů česky a stručně.
- Na konci session pushni větev. Pull request do `main` založ, pokud to prostředí umožňuje; pokud ne, napiš to ve shrnutí.
- Popis PR a závěrečné shrnutí česky: co je hotové, jak jsi to testoval, co jsi rozhodl jinak než SPEC, známé problémy, co je další fáze.

## Testy

- Před odevzdáním spusť `composer test` a `npm run e2e`. Obojí musí projít.
- Testy nikdy nemaž, nepřeskakuj ani neoslabuj jen proto, aby prošly. Když test selže kvůli prostředí, napiš to ve shrnutí.
- Žádné volání skutečných externích služeb v testech (SMTP, Telegram, RDAP, cizí weby). Používej lokální fixture servery a mocky.
- Testy pracují v dočasných adresářích a po sobě uklízí.

## Pravidla kódu

- PHP s `declare(strict_types=1);`, styl PSR-12, namespace `Nordvarr\`.
- Bez frameworku a bez runtime závislostí z Composeru. Composer jen pro vývojové nástroje.
- Databáze jen přes vrstvu repozitářů a prepared statements. Žádné SQL skládané z uživatelského vstupu.
- Veškerý výstup do HTML přes escapovací funkci. Žádné inline skripty ani inline obsluhy událostí (CSP).
- Žádné CDN. JavaScriptové knihovny jen s pevnou verzí v `assets/vendor/` a se záznamem ve `assets/vendor/VERSIONS.md` (verze, zdroj, licence).
- Při pochybnosti zvol jednodušší řešení. Abstrakce přidávej jen tam, kde je SPEC výslovně chce (vrstva repozitářů, rozhraní notifikačních kanálů).
- `config.php` a datový adresář jsou v `.gitignore`. V repozitáři je jen `config.example.php`.

## Data a soukromí

- Do repozitáře nikdy nedávej tajné údaje, tokeny ani hesla.
- Ukázková a testovací data jen s fiktivními jmény (`example.com`, `example.net`) a dokumentačními IP rozsahy (`192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`, `2001:db8::/32`). Nikdy skutečné názvy serverů nebo domén vlastníka.

## Texty a typografie

- Dokumentace, komentáře, texty UI a commity česky (UI má i anglický překlad v `lang/en.php`).
- Piš jen znaky z klávesnice: žádná dlouhá pomlčka (místo ní spojovník s mezerami, čárka nebo dvojtečka), rovné uvozovky, rovný apostrof, tři tečky jako tři tečky, šipka jako `->`. České texty s diakritikou.

## Dokumentace průběhu

- `docs/STAV.md`: na konci každé session aktualizuj: hotové fáze, rozpracované věci, známé problémy, další krok.
- `docs/ROZHODNUTI.md`: každé rozhodnutí odlišné od SPEC nebo rozhodnutí, které SPEC nechává otevřené (datum, co, proč).
- `SPEC.md` neměň. Výjimkou je oprava zjevné chyby, kterou uvedeš ve shrnutí.

## Co nedělat

- Žádné nasazování na servery, žádné placené služby, žádné volání LLM API.
- Nepřidávej funkce z kapitoly "Mimo rozsah MVP".
