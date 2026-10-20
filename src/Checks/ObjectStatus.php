<?php

declare(strict_types=1);

namespace Nordvarr\Checks;

/**
 * Efektivní stav kontrol a objektů.
 *
 * Kontrola je paused, když je vypnutá, její objekt archivovaný nebo v údržbě s pozastavením. Stav objektu je
 * nejhorší stav jeho kontrol; archivovaný objekt je paused, objekt bez kontrol nemá stav.
 */
final class ObjectStatus
{
    /**
     * @param array<string, mixed> $check řádek z CheckRepository (se stavem a lifecycle objektu)
     * @param array<string, true> $paused id objektů v údržbě s pozastavenými kontrolami
     */
    public static function checkStatus(array $check, array $paused = []): string
    {
        if (!$check['enabled'] || ($check['object_lifecycle'] ?? 'active') !== 'active' || isset($paused[(string) $check['object_id']])) {
            return Status::PAUSED;
        }

        return (string) $check['status'];
    }

    /**
     * @param list<array<string, mixed>> $checks
     * @param array<string, true> $paused id objektů v údržbě s pozastavenými kontrolami
     * @return array<string, array{status: string, check: array<string, mixed>|null}> id objektu => stav a nejhorší kontrola
     */
    public static function forObjects(array $checks, array $paused = []): array
    {
        $result = [];
        foreach ($checks as $check) {
            $objectId = (string) $check['object_id'];
            $status = self::checkStatus($check, $paused);
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
     * @param array<string, true> $paused id objektů v údržbě s pozastavenými kontrolami
     * @return array{status: string, check: array<string, mixed>|null}
     */
    public static function forObject(array $object, array $checks, array $paused = []): array
    {
        if ($object['lifecycle'] !== 'active' || isset($paused[(string) $object['id']])) {
            return ['status' => Status::PAUSED, 'check' => null];
        }

        return self::forObjects($checks, $paused)[(string) $object['id']] ?? ['status' => Status::NONE, 'check' => null];
    }
}
