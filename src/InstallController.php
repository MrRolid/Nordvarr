<?php

declare(strict_types=1);

namespace Nordvarr;

use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Http\Session;
use Nordvarr\Security\Csrf;
use Nordvarr\Security\Passwords;
use Nordvarr\View\UrlGenerator;
use Nordvarr\View\View;
use Throwable;

/**
 * Obsluha install.php. Při existujícím config.php odmítne běžet.
 */
final class InstallController
{
    public function __construct(
        private readonly string $appRoot,
        private readonly string $documentRoot = '',
        private readonly bool $probeWeb = true,
    ) {
    }

    public function handle(Request $request, Session $session): Response
    {
        $installer = new Installer($this->appRoot);
        $lang = I18n::resolve($request->queryString('lang', $request->cookies[App::LANG_COOKIE] ?? I18n::DEFAULT));
        $i18n = new I18n($this->appRoot . '/lang', $lang);
        $urls = new UrlGenerator($request->basePath, $request->prettyUrls, Installer::appVersion($this->appRoot));
        $csrf = new Csrf($session);
        $view = new View($this->appRoot . '/templates', $i18n, $urls, $csrf->token(), 'UTC');
        $view->share('user', null);
        $view->share('installer', true);
        $view->share('version', Installer::appVersion($this->appRoot));
        $loginUrl = $urls->route('/login');

        if ($installer->isLocked()) {
            return new Response(
                $view->page('install_locked', ['title' => 'install.title', 'loginUrl' => $loginUrl]),
                403
            );
        }

        $input = [
            'data_dir' => $installer->defaultDataDir($this->documentRoot),
            'email' => '',
            'password' => '',
            'password_confirm' => '',
            'timezone' => 'Europe/Prague',
        ];
        $errors = [];
        $generalError = null;

        if ($request->method === 'POST') {
            if (!$csrf->isValid($request->post[Csrf::FIELD] ?? null)) {
                return new Response($view->page('error', ['title' => 'error.title', 'message' => 'error.csrf']), 403);
            }
            foreach (array_keys($input) as $key) {
                $input[$key] = trim($request->input($key));
            }
            $input['password'] = $request->input('password');
            $input['password_confirm'] = $request->input('password_confirm');
            $errors = $installer->validate($input);
            if ($errors === [] && $installer->requirementsMet()) {
                try {
                    $installer->install($input, $request->ip);
                    $csrf->rotate();

                    return $this->done($view, $installer, rtrim($input['data_dir'], '/'), $request, $loginUrl);
                } catch (Throwable $e) {
                    $generalError = $e->getMessage();
                }
            }
        }

        $status = $errors !== [] || $generalError !== null ? 422 : 200;

        return new Response($view->page('install', [
            'title' => 'install.title',
            'requirements' => $installer->requirements(),
            'requirementsMet' => $installer->requirementsMet(),
            'input' => $input,
            'errors' => $errors,
            'generalError' => $generalError,
            'timezones' => timezone_identifiers_list(),
            'minPassword' => Passwords::MIN_LENGTH,
        ]), $status);
    }

    private function done(View $view, Installer $installer, string $dataDir, Request $request, string $loginUrl): Response
    {
        $status = 'install.data_dir_ok';
        $warning = false;
        if (Installer::isInsideDocumentRoot($dataDir, $this->documentRoot)) {
            $status = 'install.warn_in_docroot';
            $warning = true;
            $relative = substr(realpath($dataDir) ?: $dataDir, strlen(rtrim(realpath($this->documentRoot) ?: $this->documentRoot, '/')));
            $host = $request->header('Host');
            if ($this->probeWeb && $host !== null && preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host) === 1) {
                $url = ($request->https ? 'https://' : 'http://') . $host
                    . str_replace('%2F', '/', rawurlencode($relative . '/nordvarr.sqlite'));
                if (Installer::isReachableFromWeb($url)) {
                    $status = 'install.warn_web_reachable';
                }
            }
        }

        return new Response($view->page('install_done', [
            'title' => 'install.done_title',
            'cronLine' => $installer->cronLine(),
            'dataDirStatus' => $status,
            'dataDirWarning' => $warning,
            'loginUrl' => $loginUrl,
        ]));
    }
}
