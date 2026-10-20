<?php

declare(strict_types=1);

namespace Nordvarr;

use RuntimeException;

/**
 * Konfigurace instalace načtená z config.php.
 */
final class Config
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly array $values)
    {
        foreach (['app_secret', 'data_dir'] as $key) {
            if (!isset($values[$key]) || !is_string($values[$key]) || $values[$key] === '') {
                throw new RuntimeException("V konfiguraci chybí položka '$key'.");
            }
        }
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException('Konfigurační soubor neexistuje: ' . $file);
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new RuntimeException('Konfigurační soubor musí vracet pole.');
        }

        return new self($values);
    }

    public function appSecret(): string
    {
        return $this->values['app_secret'];
    }

    public function dataDir(): string
    {
        return rtrim($this->values['data_dir'], '/');
    }

    public function databasePath(): string
    {
        return $this->dataDir() . '/nordvarr.sqlite';
    }

    public function debug(): bool
    {
        return ($this->values['debug'] ?? false) === true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}
