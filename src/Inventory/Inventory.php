<?php

declare(strict_types=1);

namespace Nordvarr\Inventory;

use Nordvarr\Repository\AttributeRepository;
use Nordvarr\Repository\DocRepository;
use Nordvarr\Repository\EventRepository;
use Nordvarr\Repository\ExternalIdRepository;
use Nordvarr\Repository\ObjectRepository;
use Nordvarr\Repository\RelationshipRepository;
use Nordvarr\Repository\TagRepository;
use Nordvarr\Time;
use PDO;
use Throwable;

/**
 * Operace nad inventářem společné pro UI, API, import a ukázková data.
 */
final class Inventory
{
    public const MAX_NAME = 200;
    public const MAX_DESCRIPTION = 2000;
    public const MAX_ATTRIBUTE_VALUE = 10000;
    public const MAX_DOC = 100000;
    public const MAX_NOTE = 500;

    public readonly ObjectRepository $objects;
    public readonly ExternalIdRepository $externalIds;
    public readonly AttributeRepository $attributes;
    public readonly TagRepository $tags;
    public readonly RelationshipRepository $relationships;
    public readonly DocRepository $docs;
    public readonly EventRepository $events;

    public function __construct(public readonly PDO $pdo)
    {
        $this->objects = new ObjectRepository($pdo);
        $this->externalIds = new ExternalIdRepository($pdo);
        $this->attributes = new AttributeRepository($pdo);
        $this->tags = new TagRepository($pdo);
        $this->relationships = new RelationshipRepository($pdo);
        $this->docs = new DocRepository($pdo);
        $this->events = new EventRepository($pdo);
    }

    /**
     * Spustí operaci v transakci (nebo uvnitř už běžící transakce).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Ověří základní pole objektu (formulář i API).
     *
     * @param array<string, mixed> $data
     * @return array<string, string> chyby
     */
    public static function validateObject(array $data): array
    {
        $errors = [];
        if (!in_array($data['type'] ?? null, Vocabulary::OBJECT_TYPES, true)) {
            $errors['type'] = 'invalid_type';
        }
        $name = $data['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_NAME) {
            $errors['name'] = 'invalid_name';
        }
        if (isset($data['description']) && (!is_string($data['description']) || mb_strlen($data['description']) > self::MAX_DESCRIPTION)) {
            $errors['description'] = 'invalid_description';
        }
        if (isset($data['lifecycle']) && !in_array($data['lifecycle'], Vocabulary::LIFECYCLES, true)) {
            $errors['lifecycle'] = 'invalid_lifecycle';
        }

        return $errors;
    }

    /**
     * Přidá vztah s kontrolou druhu, smyčky a cyklu u "contains".
     *
     * @return array{id: string, created: bool}
     */
    public function relate(string $fromId, string $toId, string $kind, string $note, string $source): array
    {
        if (!in_array($kind, Vocabulary::RELATIONSHIP_KINDS, true)) {
            throw new ValidationException(['kind' => 'invalid_kind']);
        }
        if ($fromId === $toId) {
            throw new ValidationException(['to' => 'self_relationship']);
        }
        if (mb_strlen($note) > self::MAX_NOTE) {
            throw new ValidationException(['note' => 'invalid_note']);
        }
        if ($kind === 'contains' && $this->relationships->findEdge($fromId, $toId, $kind) === null
            && $this->relationships->wouldCreateContainsCycle($fromId, $toId)) {
            throw new ValidationException(['to' => 'contains_cycle']);
        }
        $result = $this->relationships->add($fromId, $toId, $kind, $note, $source);
        if ($result['created']) {
            $this->objects->touch($fromId);
        }

        return $result;
    }

    /**
     * Převede hodnotu atributu z API na uložený tvar (text nebo JSON).
     *
     * @return array{0: string, 1: bool}
     */
    public static function encodeAttributeValue(mixed $value): array
    {
        if (is_string($value)) {
            return [$value, false];
        }

        return [json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true];
    }

    /**
     * Časová osa objektu: jeho události a události přímých sousedů.
     *
     * @return list<array<string, mixed>>
     */
    public function timeline(string $objectId, int $limit = 50, ?string $since = null): array
    {
        $ids = array_values(array_unique([$objectId, ...$this->relationships->neighborIds($objectId)]));

        return $this->events->timeline($ids, $limit, $since);
    }

    /**
     * Ruční událost z UI (zdroj manual).
     *
     * @return string id události
     */
    public function addManualEvent(string $objectId, string $type, string $summary, string $details, string $occurredAt): string
    {
        $errors = [];
        if (!in_array($type, Vocabulary::EVENT_TYPES, true)) {
            $errors['type'] = 'invalid_type';
        }
        if (trim($summary) === '' || mb_strlen($summary) > 300) {
            $errors['summary'] = 'invalid_summary';
        }
        if (mb_strlen($details) > 4000) {
            $errors['details'] = 'invalid_details';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->events->create([
            'source' => Vocabulary::MANUAL_SOURCE,
            'source_event_id' => \Nordvarr\Uuid::v7(),
            'type' => $type,
            'occurred_at' => $occurredAt,
            'summary' => trim($summary),
            'details' => $details,
        ], [$objectId]);
    }

    public static function now(): string
    {
        return Time::toDb(Time::now());
    }
}
