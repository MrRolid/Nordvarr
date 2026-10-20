<?php

declare(strict_types=1);

namespace Nordvarr\Inventory;

use Nordvarr\Security\SecretMasker;
use Throwable;

/**
 * Export a import celého inventáře v JSON (objekty, externí id, atributy,
 * tagy, vztahy, dokumentace s revizemi, události). Tajné údaje se maskují.
 * Import nahradí celý inventář; formát je tentýž jako u exportu.
 */
final class Transfer
{
    public const FORMAT = 'nordvarr-inventory';
    public const VERSION = 1;

    public function __construct(private readonly Inventory $inventory)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $inv = $this->inventory;
        $objects = [];
        foreach ($inv->objects->all() as $row) {
            $id = (string) $row['id'];
            $doc = $inv->docs->current($id);
            $objects[] = [
                'id' => $id,
                'type' => $row['type'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'description' => SecretMasker::maskText((string) $row['description']),
                'lifecycle' => $row['lifecycle'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'external_ids' => $inv->externalIds->forObject($id),
                'attributes' => array_map(static fn (array $a): array => [
                    'key' => $a['key'],
                    'value' => SecretMasker::maskAttribute((string) $a['key'], (string) $a['value']),
                    'is_json' => (bool) $a['is_json'],
                    'source' => $a['source'],
                    'updated_at' => $a['updated_at'],
                ], $inv->attributes->forObject($id)),
                'tags' => $inv->tags->forObject($id),
                'docs' => $doc === null ? null : [
                    'markdown' => SecretMasker::maskText((string) $doc['markdown']),
                    'updated_at' => $doc['updated_at'],
                    'updated_by' => $doc['updated_by'],
                ],
                'doc_revisions' => array_map(static fn (array $r): array => [
                    'id' => $r['id'],
                    'author' => $r['author'],
                    'created_at' => $r['created_at'],
                    'markdown' => SecretMasker::maskText((string) $r['markdown']),
                ], array_reverse($inv->docs->revisions($id))),
            ];
        }

        $relationships = array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'from_id' => $r['from_id'],
            'to_id' => $r['to_id'],
            'kind' => $r['kind'],
            'note' => $r['note'],
            'source' => $r['source'],
            'created_at' => $r['created_at'],
        ], $inv->relationships->all());

        $events = array_map(fn (array $e): array => [
            'id' => $e['id'],
            'source' => $e['source'],
            'source_event_id' => $e['source_event_id'],
            'type' => $e['type'],
            'occurred_at' => $e['occurred_at'],
            'summary' => SecretMasker::maskText((string) $e['summary']),
            'details' => SecretMasker::maskText((string) $e['details']),
            'created_at' => $e['created_at'],
            'objects' => $inv->events->objectIds((string) $e['id']),
        ], $inv->events->all());

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => Inventory::now(),
            'objects' => $objects,
            'relationships' => $relationships,
            'events' => $events,
        ];
    }

    /**
     * Nahradí celý inventář obsahem exportu.
     *
     * @param array<string, mixed> $data
     * @return array{objects: int, relationships: int, events: int}
     */
    public function import(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT || ($data['version'] ?? null) !== self::VERSION) {
            throw new ValidationException(['file' => 'invalid_format']);
        }
        foreach (['objects', 'relationships', 'events'] as $key) {
            if (!is_array($data[$key] ?? null) || !array_is_list($data[$key])) {
                throw new ValidationException(['file' => 'invalid_format']);
            }
        }

        $pdo = $this->inventory->pdo;
        try {
            return $this->inventory->transaction(function () use ($data, $pdo): array {
                $pdo->exec('DELETE FROM events');
                $pdo->exec('DELETE FROM objects');
                $pdo->exec('DELETE FROM tags');
                $inv = $this->inventory;

                foreach ($data['objects'] as $o) {
                    $id = (string) $o['id'];
                    $stmt = $pdo->prepare(
                        'INSERT INTO objects (id, type, name, slug, description, lifecycle, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$id, $o['type'], $o['name'], $o['slug'], $o['description'] ?? '',
                        $o['lifecycle'] ?? 'active', $o['created_at'], $o['updated_at']]);
                    foreach ($o['external_ids'] ?? [] as $x) {
                        $inv->externalIds->add($id, (string) $x['source'], (string) $x['external_id']);
                    }
                    $attr = $pdo->prepare(
                        'INSERT INTO object_attributes (object_id, key, value, is_json, source, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    foreach ($o['attributes'] ?? [] as $a) {
                        $attr->execute([$id, $a['key'], $a['value'], !empty($a['is_json']) ? 1 : 0, $a['source'], $a['updated_at']]);
                    }
                    foreach ($o['tags'] ?? [] as $tag) {
                        $normalized = Vocabulary::normalizeTag((string) $tag);
                        if ($normalized === null) {
                            throw new ValidationException(['file' => 'invalid_tag']);
                        }
                        $inv->tags->attach($id, $normalized);
                    }
                    if (is_array($o['docs'] ?? null)) {
                        $inv->docs->restoreCurrent($id, (string) $o['docs']['markdown'], (string) $o['docs']['updated_at'], (string) $o['docs']['updated_by']);
                    }
                    foreach ($o['doc_revisions'] ?? [] as $r) {
                        $inv->docs->addRevision($id, (string) $r['author'], (string) $r['created_at'], (string) $r['markdown'], (string) $r['id']);
                    }
                }

                foreach ($data['relationships'] as $r) {
                    if ($r['kind'] === 'contains' && $inv->relationships->wouldCreateContainsCycle((string) $r['from_id'], (string) $r['to_id'])) {
                        throw new ValidationException(['file' => 'contains_cycle']);
                    }
                    $inv->relationships->add((string) $r['from_id'], (string) $r['to_id'], (string) $r['kind'],
                        (string) ($r['note'] ?? ''), (string) $r['source'], (string) $r['id'], (string) $r['created_at']);
                }

                foreach ($data['events'] as $e) {
                    $inv->events->create([
                        'source' => (string) $e['source'],
                        'source_event_id' => (string) $e['source_event_id'],
                        'type' => (string) $e['type'],
                        'occurred_at' => (string) $e['occurred_at'],
                        'summary' => (string) $e['summary'],
                        'details' => (string) ($e['details'] ?? ''),
                    ], array_map('strval', $e['objects'] ?? []), (string) $e['id'], (string) $e['created_at']);
                }

                return [
                    'objects' => count($data['objects']),
                    'relationships' => count($data['relationships']),
                    'events' => count($data['events']),
                ];
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Chybějící pole, porušená omezení databáze apod.: soubor není platný export.
            throw new ValidationException(['file' => 'invalid_content']);
        }
    }
}
