<?php

declare(strict_types=1);

use Nordvarr\Http\Request;
use Nordvarr\Http\Session;
use Nordvarr\InstallController;

$appRoot = dirname(__DIR__);
require $appRoot . '/src/autoload.php';

$request = Request::fromGlobals();
$session = Session::startNative('', $request->https);
(new InstallController($appRoot, (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')))
    ->handle($request, $session)
    ->send();
