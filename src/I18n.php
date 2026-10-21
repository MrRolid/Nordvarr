<?php

declare(strict_types=1);

namespace Nordvarr;

/**
 * Překlady UI. Výchozí jazyk je čeština, chybějící klíč se vezme z češtiny.
 */
final class I18n
{
    public const LANGUAGES = ['cs', 'en'];
    public const DEFAULT = 'cs';

    /** @var array<string, string> */
    private array $messages;
    /** @var array<string, string> */
    private array $fallback;

    public function __construct(private readonly string $langDir, private readonly string $lang)
    {
        $this->fallback = self::loadFile($langDir, self::DEFAULT);
        $this->messages = $lang === self::DEFAULT ? $this->fallback : self::loadFile($langDir, $lang);
    }

    public static function resolve(?string $requested): string
    {
        return in_array($requested, self::LANGUAGES, true) ? $requested : self::DEFAULT;
    }

    /**
     * @return array<string, string>
     */
    public static function loadFile(string $langDir, string $lang): array
    {
        $file = $langDir . '/' . self::resolve($lang) . '.php';
        $messages = is_file($file) ? require $file : [];

        return is_array($messages) ? $messages : [];
    }

    public function lang(): string
    {
        return $this->lang;
    }

    /**
     * Přeloží klíč a dosadí parametry ve tvaru {nazev}. Výsledek není escapovaný.
     *
     * @param array<string, string|int> $params
     */
    public function t(string $key, array $params = []): string
    {
        $text = $this->messages[$key] ?? $this->fallback[$key] ?? $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }
}
