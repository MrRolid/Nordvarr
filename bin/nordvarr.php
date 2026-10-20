<?php

declare(strict_types=1);

/*
 * Příkazová řádka Nordvarru.
 *   php bin/nordvarr.php worker    spouští cron každou minutu
 *   php bin/nordvarr.php migrate   provede čekající migrace (se zálohou)
 *   php bin/nordvarr.php demo-data [--force]   nahraje ukázkovou fiktivní infrastrukturu
 *   php bin/nordvarr.php reset-password <e-mail>   nové heslo pro zapomenutý účet
 *   php bin/nordvarr.php version
 */

use Nordvarr\Cli;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Zálohy a logy bez práv pro ostatní uživatele serveru.
umask(0027);

$appRoot = dirname(__DIR__);
require $appRoot . '/src/autoload.php';

exit((new Cli($appRoot))->run(array_slice($argv, 1), STDOUT, STDERR));
