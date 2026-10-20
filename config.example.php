<?php

// Vzor konfigurace. Skutečný config.php vytváří instalátor (public/install.php)
// a nová verze aplikace ho nikdy nepřepisuje. Do repozitáře config.php nepatří.

return [
    // Náhodné tajemství aplikace (64 hex znaků), slouží např. pro HMAC API tokenů.
    'app_secret' => 'nahradte-nahodnym-retezcem',
    // Absolutní cesta k datovému adresáři mimo webroot (databáze, logy, zámky, zálohy).
    'data_dir' => '/srv/www/nordvarr-data',
    // Podrobné chyby jen pro vývoj.
    'debug' => false,
];
