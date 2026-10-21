<?php

declare(strict_types=1);

namespace Nordvarr;

use Nordvarr\Http\Response;
use Throwable;

/**
 * Obecná chybová stránka pro neošetřené výjimky. Podrobnosti jdou jen do logu.
 */
final class ErrorPage
{
    public static function fromException(Throwable $e, ?Config $config): Response
    {
        if ($config !== null) {
            $logDir = $config->dataDir() . '/logs';
            if (is_dir($logDir) || @mkdir($logDir, 0750, true)) {
                $line = sprintf(
                    "[%s] %s: %s v %s:%d\n%s\n",
                    gmdate('Y-m-d\TH:i:s\Z'),
                    $e::class,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                );
                @file_put_contents($logDir . '/app.log', $line, FILE_APPEND | LOCK_EX);
            }
        } else {
            error_log('Nordvarr: ' . $e->getMessage());
        }

        $body = '<!doctype html><html lang="cs"><head><meta charset="utf-8"><title>Nordvarr - chyba</title></head>'
            . '<body><h1>Chyba / Error</h1><p>Došlo k vnitřní chybě. Podrobnosti jsou v logu v datovém adresáři.</p>'
            . '<p>An internal error occurred. Details are in the log in the data directory.</p></body></html>';

        return new Response($body, 500);
    }
}
