<?php

declare(strict_types=1);

// Čeština je výchozí jazyk. Každý klíč musí mít překlad i v lang/en.php.
return [
    'app.name' => 'Nordvarr',
    'app.tagline' => 'Co provozujeme, co na čem závisí a co se změnilo',

    'nav.main' => 'Hlavní navigace',
    'nav.overview' => 'Přehled',
    'nav.inventory' => 'Inventář',
    'nav.topology' => 'Topologie',
    'nav.alerts' => 'Alerty',
    'nav.settings' => 'Nastavení',

    'user.logged_in_as' => 'Uživatel: {email}',
    'user.logout' => 'Odhlásit',

    'lang.label' => 'Jazyk',
    'lang.cs' => 'Čeština',
    'lang.en' => 'English',
    'lang.switch' => 'Přepnout',

    'login.title' => 'Přihlášení',
    'login.email' => 'E-mail',
    'login.password' => 'Heslo',
    'login.submit' => 'Přihlásit se',
    'login.invalid' => 'Nesprávný e-mail nebo heslo.',
    'login.throttled' => 'Příliš mnoho neúspěšných pokusů. Zkuste to prosím později.',
    'login.logged_out' => 'Odhlášení proběhlo.',

    'dashboard.title' => 'Přehled',
    'dashboard.welcome' => 'Vítejte v Nordvarru.',
    'dashboard.placeholder' => 'Přehled stavů, změn a blížících se expirací přibude ve fázi F2.',

    'placeholder.text' => 'Tato část bude doplněna ve fázi {phase}.',

    'migrations.done' => 'Databáze byla aktualizována (migrace {versions}). Záloha před migrací: {backup}',

    'error.title' => 'Chyba',
    'error.csrf' => 'Platnost formuláře vypršela. Načtěte stránku znovu a akci zopakujte.',
    'error.not_found' => 'Stránka nebyla nalezena.',
    'error.method_not_allowed' => 'Tato metoda není pro danou adresu povolena.',
    'error.bearer_rejected' => 'Webové rozhraní nepřijímá API tokeny. Použijte adresy /api/v1/.',
    'error.back_home' => 'Zpět na přehled',

    'footer.version' => 'Verze {version}',

    'install.title' => 'Instalace Nordvarru',
    'install.locked' => 'Nordvarr už je nainstalovaný (config.php existuje). Instalátor je zablokovaný.',
    'install.go_login' => 'Přejít na přihlášení',
    'install.requirements' => 'Požadavky',
    'install.req_php' => 'PHP {required} nebo novější (nalezeno {found})',
    'install.req_ext' => 'Rozšíření PHP {name}',
    'install.req_writable' => 'Zápis do adresáře aplikace (kvůli config.php)',
    'install.req_ok' => 'v pořádku',
    'install.req_fail' => 'chybí',
    'install.req_blocking' => 'Některé požadavky nejsou splněné. Instalaci nelze dokončit.',
    'install.data_dir' => 'Datový adresář',
    'install.data_dir_help' => 'Absolutní cesta mimo webroot. Bude obsahovat databázi, logy, zámky a zálohy.',
    'install.admin_email' => 'E-mail administrátora',
    'install.admin_password' => 'Heslo administrátora',
    'install.admin_password_help' => 'Alespoň {min} znaků.',
    'install.admin_password_confirm' => 'Heslo znovu',
    'install.timezone' => 'Časová zóna',
    'install.submit' => 'Nainstalovat',
    'install.err_data_dir' => 'Datový adresář musí být absolutní cesta.',
    'install.err_data_dir_create' => 'Datový adresář nelze vytvořit nebo do něj nelze zapisovat.',
    'install.err_data_dir_used' => 'V datovém adresáři už je databáze Nordvarru. Zvolte prázdný adresář.',
    'install.err_data_dir_in_app' => 'Datový adresář nesmí být uvnitř adresáře aplikace (při aktualizaci by se přepsal).',
    'install.err_email' => 'Zadejte platný e-mail.',
    'install.err_password' => 'Heslo musí mít alespoň {min} znaků.',
    'install.err_password_confirm' => 'Hesla se neshodují.',
    'install.err_timezone' => 'Neznámá časová zóna.',
    'install.err_config_write' => 'Soubor config.php nelze vytvořit.',
    'install.done_title' => 'Instalace dokončena',
    'install.done_text' => 'Nordvarr je nainstalovaný. Instalátor se tím zablokoval.',
    'install.cron' => 'Přidejte do cronu webového uživatele tento řádek (worker se spouští každou minutu):',
    'install.warn_web_reachable' => 'Pozor: datový adresář je dostupný z webu! Přesuňte ho mimo webroot nebo zakažte přístup v konfiguraci serveru.',
    'install.warn_in_docroot' => 'Datový adresář leží uvnitř webrootu. Je chráněný souborem .htaccess, ale bezpečnější je přesunout ho mimo webroot.',
    'install.data_dir_ok' => 'Datový adresář leží mimo webroot.',
];
