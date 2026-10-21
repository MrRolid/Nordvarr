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
    ) {
    }

    public static function fromGlobals(): self
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
        );
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return preg_replace('#/+#', '/', $path) ?? '/';
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

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
