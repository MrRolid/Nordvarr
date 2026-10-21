<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

/**
 * Efektivní stav kontrol a objektů.
 *
 * Kontrola je paused, když je vypnutá nebo její objekt archivovaný. Stav objektu je
 * nejhorší stav jeho kontrol; archivovaný objekt je paused, objekt bez kontrol nemá stav.
 */
final class ObjectStatus
{
    /**
     * @param array<string, mixed> $check řádek z CheckRepository (se stavem a lifecycle objektu)
     */
    public static function checkStatus(array $check): string
    {
        if (!$check['enabled'] || ($check['object_lifecycle'] ?? 'active') !== 'active') {
            return Status::PAUSED;
        }

        return (string) $check['status'];
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @return array<string, array{status: string, check: array<string, mixed>|null}> id objektu => stav a nejhorší kontrola
     */
    public static function forObjects(array $checks): array
    {
        $result = [];
        foreach ($checks as $check) {
            $objectId = (string) $check['object_id'];
            $status = self::checkStatus($check);
            $current = $result[$objectId] ?? ['status' => Status::NONE, 'check' => null];
            if (Status::severity($status) > Status::severity($current['status'])
                || ($status === $current['status'] && $current['check'] !== null && strcmp((string) $check['since'], (string) $current['check']['since']) < 0)) {
                $current = ['status' => $status, 'check' => $check];
            }
            $result[$objectId] = $current;
        }

        return $result;
    }

    /**
     * Stav jednoho objektu.
     *
     * @param array<string, mixed> $object
     * @param list<array<string, mixed>> $checks kontroly objektu
     * @return array{status: string, check: array<string, mixed>|null}
     */
    public static function forObject(array $object, array $checks): array
    {
        if ($object['lifecycle'] !== 'active') {
            return ['status' => Status::PAUSED, 'check' => null];
        }

        return self::forObjects($checks)[(string) $object['id']] ?? ['status' => Status::NONE, 'check' => null];
    }
}
