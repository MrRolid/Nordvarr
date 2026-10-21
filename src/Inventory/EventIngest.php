<?php

declare(strict_types=1);

namespace Nordvarr\Inventory;

/**
 * Příjem události ze strojového zdroje, idempotentní podle (zdroj, event_id).
 */
final class EventIngest
{
    public function __construct(private readonly Inventory $inventory)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: string, created: bool}
     */
    public function run(array $input, string $source): array
    {
        $errors = [];
        $eventId = $input['event_id'] ?? null;
        if (!is_string($eventId) || $eventId === '' || mb_strlen($eventId) > 200) {
            $errors['event_id'] = 'invalid_event_id';
        }
        $type = $input['type'] ?? null;
        if (!in_array($type, Vocabulary::EVENT_TYPES, true)) {
            $errors['type'] = 'invalid_type';
        }
        $occurredAt = is_string($input['occurred_at'] ?? null)
            ? \Nordvarr\Repository\EventRepository::normalizeTime($input['occurred_at'])
            : null;
        if ($occurredAt === null) {
            $errors['occurred_at'] = 'invalid_occurred_at';
        }
        $summary = $input['summary'] ?? null;
        if (!is_string($summary) || trim($summary) === '' || mb_strlen($summary) > 300) {
            $errors['summary'] = 'invalid_summary';
        }
        $details = $input['details'] ?? '';
        if (!is_string($details) || mb_strlen($details) > 4000) {
            $errors['details'] = 'invalid_details';
        }
        $refs = $input['objects'] ?? null;
        if (!is_array($refs) || !array_is_list($refs) || $refs === []) {
            $errors['objects'] = 'invalid_objects';
            $refs = [];
        }
        foreach ($refs as $ref) {
            if (!is_array($ref) || (!is_string($ref['external_id'] ?? null) && !is_string($ref['id'] ?? null))) {
                $errors['objects'] = 'invalid_objects';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->inventory->transaction(function () use ($source, $eventId, $type, $occurredAt, $summary, $details, $refs): array {
            $inv = $this->inventory;
            $existing = $inv->events->findBySourceId($source, $eventId);
            if ($existing !== null) {
                return ['id' => (string) $existing['id'], 'created' => false];
            }
            $objectIds = [];
            $missing = [];
            foreach ($refs as $ref) {
                $isExternal = is_string($ref['external_id'] ?? null);
                $key = $isExternal ? $ref['external_id'] : $ref['id'];
                $id = $isExternal ? $inv->externalIds->objectId($source, $key) : ($inv->objects->find($key)['id'] ?? null);
                if ($id === null) {
                    $missing[] = $key;
                } else {
                    $objectIds[] = (string) $id;
                }
            }
            if ($missing !== []) {
                throw new ValidationException(['objects' => 'missing_objects'], array_values(array_unique($missing)));
            }
            $id = $inv->events->create([
                'source' => $source,
                'source_event_id' => $eventId,
                'type' => $type,
                'occurred_at' => $occurredAt,
                'summary' => trim($summary),
                'details' => $details,
            ], $objectIds);

            return ['id' => $id, 'created' => true];
        });
    }
}
