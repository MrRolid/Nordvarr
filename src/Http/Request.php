<?php

declare(strict_types=1);

namespace Nordvarr\Http;

/**
 * HTTP požadavek. Cesta routy se bere z parametru r (fallback bez mod_rewrite),
 * jinak z REQUEST_URI bez základní cesty aplikace.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $cookies
     * @param array<string, string> $headers klíče malými písmeny
     * @param array<string, mixed> $files nahrané soubory ve tvaru $_FILES
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly string $basePath = '',
        public readonly bool $prettyUrls = false,
        public readonly bool $https = false,
        public readonly string $ip = '',
        public readonly string $body = '',
        public readonly array $files = [],
    ) {
    }

    /**
     * @param int $maxBody kolik bajtů těla načíst (0 = nenačítat); načte se o bajt víc, aby šlo poznat překročení
     */
    public static function fromGlobals(int $maxBody = 0): self
    {
        $server = $_SERVER;
        $basePath = rtrim(str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/index.php'))), '/');
        if ($basePath === '.') {
            $basePath = '';
        }

        $route = $_GET['r'] ?? null;
        if (!is_string($route)) {
            $uriPath = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $uriPath = rawurldecode($uriPath);
            if ($basePath !== '' && str_starts_with($uriPath, $basePath)) {
                $uriPath = substr($uriPath, strlen($basePath));
            }
            $route = $uriPath === '/index.php' ? '/' : $uriPath;
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (!isset($headers['authorization']) && isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $pretty = ($server['NORDVARR_REWRITE'] ?? $server['REDIRECT_NORDVARR_REWRITE'] ?? '') === '1';
        $https = !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off';

        return new self(
            strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath($route),
            $_GET,
            $_POST,
            array_filter($_COOKIE, 'is_string'),
            $headers,
            $basePath,
            $pretty,
            $https,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            $maxBody > 0 ? (string) file_get_contents('php://input', false, null, 0, $maxBody + 1) : '',
            $_FILES,
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return preg_replace('#/+#', '/', $path) ?? '/';
    }

    public function isApi(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Cesta k dočasnému souboru úspěšně nahraného souboru, nebo null.
     */
    public function uploadedFile(string $field): ?string
    {
        $file = $this->files[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
            return null;
        }

        return $file['tmp_name'];
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
