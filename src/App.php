<?php

declare(strict_types=1);

namespace Nordvarr;

use Nordvarr\Db\Database;
use Nordvarr\Db\Migrator;
use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Http\Router;
use Nordvarr\Http\Session;
use Nordvarr\Repository\AuditLogRepository;
use Nordvarr\Repository\LoginAttemptRepository;
use Nordvarr\Repository\SettingsRepository;
use Nordvarr\Repository\UserRepository;
use Nordvarr\Security\Auth;
use Nordvarr\Security\Csrf;
use Nordvarr\Security\LoginThrottle;
use Nordvarr\View\UrlGenerator;
use Nordvarr\View\View;
use PDO;

/**
 * Webová aplikace: zpracuje jeden požadavek UI a vrátí odpověď.
 */
final class App
{
    public const LANG_COOKIE = 'nordvarr_lang';

    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $appRoot,
        private readonly Config $config,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Database::connectSqlite($this->config->databasePath());
    }

    public function handle(Request $request, Session $session): Response
    {
        $i18n = new I18n($this->appRoot . '/lang', I18n::resolve($request->cookies[self::LANG_COOKIE] ?? null));
        $urls = new UrlGenerator($request->basePath, $request->prettyUrls, Installer::appVersion($this->appRoot));
        $csrf = new Csrf($session);
        $settings = new SettingsRepository($this->pdo());
        $timezone = $settings->get('timezone', 'Europe/Prague') ?? 'Europe/Prague';

        // Webové rozhraní nepřijímá bearer tokeny (ty patří jen API).
        if (stripos((string) $request->header('Authorization'), 'bearer ') === 0) {
            $view = new View($this->appRoot . '/templates', $i18n, $urls, '', $timezone);

            return $this->error($view, 401, 'error.bearer_rejected');
        }

        $audit = new AuditLogRepository($this->pdo());
        $users = new UserRepository($this->pdo());
        $auth = new Auth($users, new LoginThrottle(new LoginAttemptRepository($this->pdo())), $audit, $session);

        if ($request->method === 'POST' && !$csrf->isValid($request->post[Csrf::FIELD] ?? null)) {
            $view = new View($this->appRoot . '/templates', $i18n, $urls, $csrf->token(), $timezone);
            // PHP zahodí celé tělo nad post_max_size; místo matoucí chyby CSRF řekne, že je soubor velký.
            if ($request->post === [] && $request->files === [] && (int) $request->header('Content-Length') > 0) {
                return $this->error($view, 413, 'error.too_large');
            }

            return $this->error($view, 403, 'error.csrf');
        }

        $user = $auth->user();
        if ($user !== null && $user['role'] === 'admin') {
            $this->runPendingMigrations($session, $audit, (string) $user['id'], $request->ip);
            $this->rememberBaseUrl($request, $settings);
        }

        $view = new View($this->appRoot . '/templates', $i18n, $urls, $csrf->token(), $timezone);
        $view->share('user', $user);
        $view->share('path', $request->path);
        $view->share('pretty_urls', $request->prettyUrls);
        $view->share('version', Installer::appVersion($this->appRoot));
        $view->share('flash', $session->pull('flash'));
        $view->share('migrations', $session->pull('flash_migrations'));

        $public = ['/login', '/lang'];
        if ($user === null && !in_array($request->path, $public, true)) {
            return Response::redirect($urls->route('/login'));
        }

        $router = $this->routes($request, $session, $auth, $csrf, $view, $urls);
        $match = $router->match($request->method, $request->path);
        if ($match['status'] === 404) {
            return $this->error($view, 404, 'error.not_found');
        }
        if ($match['status'] === 405) {
            $response = $this->error($view, 405, 'error.method_not_allowed');
            $response->headers['Allow'] = implode(', ', $match['allowed']);

            return $response;
        }

        // Změny smí dělat jen admin (role viewer je připravená pro pozdější použití).
        if ($request->method === 'POST' && !in_array($request->path, ['/login', '/logout', '/lang'], true)
            && ($user['role'] ?? null) !== 'admin') {
            return $this->error($view, 403, 'error.forbidden');
        }

        return ($match['handler'])($match['params']);
    }

    private function routes(Request $request, Session $session, Auth $auth, Csrf $csrf, View $view, UrlGenerator $urls): Router
    {
        $router = new Router();


        $user = $auth->user();
        if ($user !== null) {
            $ctx = new Ui\Ctx($request, $session, $view, $urls, $this->pdo(), $user, new AuditLogRepository($this->pdo()), $this->config);
            (new Ui\OverviewController($ctx))->register($router);
            (new Ui\InventoryController($ctx))->register($router);
            (new Ui\CheckController($ctx))->register($router);
            (new Ui\ObjectController($ctx))->register($router);
            (new Ui\SettingsController($ctx, $this->appRoot))->register($router);
            (new Ui\AlertController($ctx))->register($router);
            (new Ui\ChannelController($ctx, $this->appRoot))->register($router);
            (new Ui\TopologyController($ctx, $this->appRoot))->register($router);
            (new Ui\LlmExportController($ctx, $this->appRoot))->register($router);
        }

        $router->get('/login', function () use ($auth, $view, $urls): Response {
            if ($auth->user() !== null) {
                return Response::redirect($urls->route('/'));
            }

            return new Response($view->page('login', ['title' => 'login.title', 'email' => '', 'error' => null]));
        });

        $router->post('/login', function () use ($request, $auth, $csrf, $view, $urls): Response {
            $email = $request->input('email');
            $result = $auth->attempt($email, $request->input('password'), $request->ip, Time::now());
            if ($result === Auth::OK) {
                $csrf->rotate();

                return Response::redirect($urls->route('/'));
            }
            $error = $result === Auth::THROTTLED ? 'login.throttled' : 'login.invalid';
            $status = $result === Auth::THROTTLED ? 429 : 401;

            return new Response($view->page('login', ['title' => 'login.title', 'email' => $email, 'error' => $error]), $status);
        });

        $router->post('/logout', function () use ($request, $auth, $session, $urls): Response {
            $auth->logout($request->ip);
            $session->set('flash', 'login.logged_out');

            return Response::redirect($urls->route('/login'));
        });

        $router->post('/lang', function () use ($request, $urls): Response {
            $lang = I18n::resolve($request->input('lang'));
            $back = Request::normalizePath($request->input('back', '/'));
            $response = Response::redirect($urls->route($back));

            return $response->withCookie(self::LANG_COOKIE, $lang, [
                'expires' => time() + 365 * 86400,
                'path' => '/',
                'secure' => $request->https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        });

        return $router;
    }

    /**
     * Aktualizace: při prvním požadavku přihlášeného admina se provedou čekající
     * migrace, předtím se vytvoří kopie databáze v backups/.
     */
    private function runPendingMigrations(Session $session, AuditLogRepository $audit, string $userId, string $ip): void
    {
        $migrator = new Migrator($this->pdo(), $this->appRoot . '/migrations');
        $pending = $migrator->pending();
        if ($pending === []) {
            return;
        }
        $backupDir = $this->config->dataDir() . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }
        $backup = $backupDir . '/nordvarr-' . gmdate('Ymd-His') . '-pred-' . array_key_first($pending) . '.sqlite';
        $migrator->backup($backup);
        $done = $migrator->migrate();
        $audit->log('migrate', $userId, $ip, ['versions' => $done, 'backup' => basename($backup)]);
        $session->set('flash_migrations', ['versions' => implode(', ', $done), 'backup' => basename($backup)]);
    }

    /**
     * Adresa aplikace pro odkazy v notifikacích (worker z cronu ji nezná). Uloží se
     * při prvním požadavku admina, v nastavení jde změnit. Bez mod_rewrite obsahuje
     * i "index.php?r=", aby šlo za ni rovnou připojit cestu.
     */
    private function rememberBaseUrl(Request $request, SettingsRepository $settings): void
    {
        $host = (string) $request->header('Host');
        if ($host === '' || preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host) !== 1 || ($settings->get('base_url') ?? '') !== '') {
            return;
        }
        $url = ($request->https ? 'https://' : 'http://') . $host . $request->basePath . ($request->prettyUrls ? '' : '/index.php?r=');
        $settings->set('base_url', $url);
    }

    private function error(View $view, int $status, string $messageKey): Response
    {
        return new Response($view->page('error', ['title' => 'error.title', 'message' => $messageKey]), $status);
    }
}
