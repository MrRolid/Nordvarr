<?php

declare(strict_types=1);

use Nordvarr\App;
use Nordvarr\Config;
use Nordvarr\ErrorPage;
use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Http\Session;

$appRoot = dirname(__DIR__);
require $appRoot . '/src/autoload.php';

$request = Request::fromGlobals();

if (!is_file($appRoot . '/config.php')) {
    Response::redirect($request->basePath . '/install.php', 302)->send();
    exit;
}

$config = null;
try {
    $config = Config::load($appRoot . '/config.php');
    $session = Session::startNative($config->dataDir() . '/sessions', $request->https);
    (new App($appRoot, $config))->handle($request, $session)->send();
} catch (Throwable $e) {
    ErrorPage::fromException($e, $config)->send();
}
