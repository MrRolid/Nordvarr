<?php

declare(strict_types=1);

namespace Nordvarr\Inventory;

/**
 * Pevné číselníky inventáře a normalizace jmen (typy objektů, druhy vztahů,
 * typy událostí, tagy, slugy, zdroje).
 */
final class Vocabulary
{
    public const OBJECT_TYPES = ['server', 'vm', 'cluster', 'container', 'service', 'website', 'database', 'domain', 'network_device', 'provider', 'external', 'other'];
    public const RELATIONSHIP_KINDS = ['depends_on', 'contains'];
    public const EVENT_TYPES = ['deploy', 'config', 'maintenance', 'restart', 'incident', 'note', 'other'];
    public const LIFECYCLES = ['active', 'archived'];
    public const MANUAL_SOURCE = 'manual';

    private const TRANSLIT = [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'ë' => 'e', 'í' => 'i',
        'ľ' => 'l', 'ĺ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ř' => 'r', 'ŕ' => 'r',
        'š' => 's', 'ß' => 'ss', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ý' => 'y', 'ž' => 'z',
    ];

    /**
     * Slug z názvu: malá písmena bez diakritiky, číslice a pomlčky.
     */
    public static function slugify(string $name): string
    {
        $slug = strtr(mb_strtolower($name), self::TRANSLIT);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if (strlen($slug) > 80) {
            $slug = rtrim(substr($slug, 0, 80), '-');
        }

        return $slug === '' ? 'objekt' : $slug;
    }

    /**
     * Normalizovaný název tagu, nebo null, když je neplatný.
     */
    public static function normalizeTag(string $tag): ?string
    {
        $tag = mb_strtolower(trim($tag));
        if ($tag === '' || mb_strlen($tag) > 50 || preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._-]*$/u', $tag) !== 1) {
            return null;
        }

        return $tag;
    }

    /**
     * Název zdroje dat (API token, import): malá písmena, číslice, tečka, pomlčka, podtržítko.
     */
    public static function isValidSource(string $source): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,49}$/', $source) === 1;
    }

    public static function isValidAttributeKey(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $key) === 1;
    }
}
