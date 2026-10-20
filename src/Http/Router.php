<?php

declare(strict_types=1);

namespace Nordvarr\Http;

/**
 * Jednoduchý router: metoda + vzor cesty s parametry ve tvaru {nazev}.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback(
            '#\{([a-z_][a-z0-9_]*)\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $pattern
        );
        $this->routes[] = ['method' => strtoupper($method), 'regex' => '#^' . $regex . '$#', 'handler' => $handler];
    }

    /**
     * @return array{status: 200, handler: callable, params: array<string, string>}|array{status: 404|405, allowed: list<string>}
     */
    public function match(string $method, string $path): array
    {
        $method = strtoupper($method);
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }
            $routeMethod = $route['method'];
            if ($routeMethod === $method || ($routeMethod === 'GET' && $method === 'HEAD')) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

                return ['status' => 200, 'handler' => $route['handler'], 'params' => $params];
            }
            $allowed[] = $routeMethod;
        }

        return $allowed === [] ? ['status' => 404, 'allowed' => []] : ['status' => 405, 'allowed' => array_values(array_unique($allowed))];
    }
}
