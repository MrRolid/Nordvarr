<?php

declare(strict_types=1);

/*
 * Router pro vestavěný server PHP (jen vývoj a testy), náhrada .htaccess:
 *   php -S 127.0.0.1:8080 -t public bin/dev-router.php
 * Webroot se bere z -t (DOCUMENT_ROOT), takže router jde použít i pro rozbalený balíček.
 */

$public = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__) . '/public'), '/');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = realpath($public . $path);

if ($path !== '/' && $file !== false && str_starts_with($file, $public . '/') && is_file($file)) {
    return false;
}

$_SERVER['NORDVARR_REWRITE'] = '1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $public . '/index.php';
require $public . '/index.php';
