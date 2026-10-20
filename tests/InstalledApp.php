<?php

declare(strict_types=1);

namespace Nordvarr\Tests;

use Nordvarr\Api\ApiApp;
use Nordvarr\App;
use Nordvarr\Config;
use Nordvarr\Db\Database;
use Nordvarr\Http\Request;
use Nordvarr\Http\Response;
use Nordvarr\Http\Session;
use Nordvarr\Installer;
use PDO;

/**
 * Instalace v dočasném adresáři a pomocníci pro požadavky na UI a API v jednom procesu.
 */
trait InstalledApp
{
    private string $tmp;
    private string $app;
    private Session $session;

    private const ADMIN_EMAIL = 'admin@example.com';
    private const ADMIN_PASSWORD = 'tajne-heslo-1';

    private function install(): void
    {
        $this->tmp = TempDir::create();
        $this->app = TempDir::copyApp($this->tmp);
        (new Installer($this->app))->install([
            'data_dir' => $this->tmp . '/nordvarr-data',
            'email' => self::ADMIN_EMAIL,
            'password' => self::ADMIN_PASSWORD,
            'password_confirm' => self::ADMIN_PASSWORD,
            'timezone' => 'Europe/Prague',
        ]);
        $this->session = Session::inMemory();
    }

    private function uninstall(): void
    {
        TempDir::remove($this->tmp);
    }

    private function config(): Config
    {
        return Config::load($this->app . '/config.php');
    }

    private function pdo(): PDO
    {
        return Database::connectSqlite($this->tmp . '/nordvarr-data/nordvarr.sqlite');
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $query
     * @param array<string, mixed> $files
     */
    private function ui(string $method, string $path, array $post = [], array $query = [], array $files = []): Response
    {
        $app = new App($this->app, $this->config());

        return $app->handle(new Request($method, $path, $query, $post, [], [], '', true, false, '192.0.2.50', '', $files), $this->session);
    }

    private function csrfToken(): string
    {
        $token = $this->session->get('csrf_token');
        if (!is_string($token)) {
            $this->ui('GET', '/login');
            $token = $this->session->get('csrf_token');
        }

        return (string) $token;
    }

    /**
     * POST z UI s platným CSRF tokenem.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    private function post(string $path, array $post = [], array $files = []): Response
    {
        return $this->ui('POST', $path, ['_csrf' => $this->csrfToken()] + $post, [], $files);
    }

    private function loginAdmin(): void
    {
        $response = $this->post('/login', ['email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD]);
        self::assertSame(303, $response->status);
    }

    /**
     * @param array<string, string> $headers
     */
    private function api(string $method, string $path, mixed $json = null, ?string $token = null, array $headers = []): Response
    {
        $headers = array_change_key_case($headers);
        if ($token !== null) {
            $headers['authorization'] = 'Bearer ' . $token;
        }
        $body = '';
        if ($json !== null) {
            $body = is_string($json) ? $json : json_encode($json, JSON_THROW_ON_ERROR);
            $headers += ['content-type' => 'application/json'];
        }
        $api = new ApiApp($this->pdo(), $this->config()->appSecret());

        return $api->handle(new Request($method, $path, [], [], [], $headers, '', true, false, '198.51.100.9', $body));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Response $response): array
    {
        return json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
    }
}
