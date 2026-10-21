<?php

declare(strict_types=1);

namespace Nordvarr\View;

/**
 * Tvoří adresy: hezké (/login) při aktivním mod_rewrite, jinak index.php?r=/login.
 */
final class UrlGenerator
{
    public function __construct(
        private readonly string $basePath,
        private readonly bool $pretty,
        private readonly string $version = '',
    ) {
    }

    /**
     * @param array<string, string> $query
     */
    public function route(string $path, array $query = []): string
    {
        $path = '/' . ltrim($path, '/');
        if ($this->pretty) {
            $url = $this->basePath . $path;
            $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

            return $qs === '' ? $url : $url . '?' . $qs;
        }

        $url = $this->basePath . '/index.php?r=' . str_replace('%2F', '/', rawurlencode($path));
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $qs === '' ? $url : $url . '&' . $qs;
    }

    public function asset(string $path): string
    {
        $url = $this->basePath . '/assets/' . ltrim($path, '/');

        return $this->version === '' ? $url : $url . '?v=' . rawurlencode($this->version);
    }

    public function base(): string
    {
        return $this->basePath;
    }
}
