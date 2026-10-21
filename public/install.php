<?php

declare(strict_types=1);

use Nordvarr\ErrorPage;
use Nordvarr\Http\Request;
use Nordvarr\Http\Session;
use Nordvarr\InstallController;

// Podrobnosti chyb jen do logu, nikdy do stránky.
ini_set('display_errors', '0');
umask(0027);

$appRoot = dirname(__DIR__);
require $appRoot . '/src/autoload.php';

$request = Request::fromGlobals();
try {
    $session = Session::startNative('', $request->https);
    (new InstallController($appRoot, (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')))
        ->handle($request, $session)
        ->send();
} catch (Throwable $e) {
    ErrorPage::fromException($e, null, false)->send();
}
