<?php

declare(strict_types=1);

use Nordvarr\Api\ApiApp;
use Nordvarr\App;
use Nordvarr\Checks\HeartbeatEndpoint;
use Nordvarr\Config;
use Nordvarr\Db\Database;
use Nordvarr\ErrorPage;
use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Http\Session;

$appRoot = dirname(__DIR__);
require $appRoot . '/src/autoload.php';

$request = Request::fromGlobals(ApiApp::MAX_BODY);

if (!is_file($appRoot . '/config.php')) {
    if ($request->isApi()) {
        ApiApp::json(['error' => 'not_installed'], 503)->send();
        exit;
    }
    Response::redirect($request->basePath . '/install.php', 302)->send();
    exit;
}

$config = null;
try {
    $config = Config::load($appRoot . '/config.php');
    if (HeartbeatEndpoint::matches($request->path)) {
        (new HeartbeatEndpoint(Database::connectSqlite($config->databasePath())))->handle($request)->send();
    } elseif ($request->isApi()) {
        // API nepoužívá session ani cookies.
        (new ApiApp(Database::connectSqlite($config->databasePath()), $config->appSecret()))->handle($request)->send();
    } else {
        $session = Session::startNative($config->dataDir() . '/sessions', $request->https);
        (new App($appRoot, $config))->handle($request, $session)->send();
    }
} catch (Throwable $e) {
    ErrorPage::fromException($e, $config, $request->isApi())->send();
}
