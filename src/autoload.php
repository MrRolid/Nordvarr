<?php

declare(strict_types=1);

/*
 * Vlastní PSR-4 autoloader pro namespace Nordvarr\. Aplikace nemá runtime
 * závislosti z Composeru, proto se Composer autoloader na serveru nepoužívá.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Nordvarr\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
