# Nordvarr

Nástroj pro správce malé infrastruktury: co provozujeme, co na čem závisí, jestli to běží, co se změnilo a jak se to spravuje. Specifikace je v `SPEC.md`, stav práce v `docs/STAV.md`.

## Vývoj

```
scripts/setup-cloud.sh        # příprava prostředí (PHP 8.4, Composer, Node, Playwright)
composer test                 # PHPUnit
npm run e2e                   # Playwright (sestaví zip, nainstaluje ho a projde UI)
php -S 127.0.0.1:8080 -t public bin/dev-router.php   # lokální server
bin/build-release.sh          # dist/nordvarr-<verze>.zip
```

Na serveru stačí nahrát obsah zipu a otevřít `/install.php`. Webroot je adresář `public/`.
