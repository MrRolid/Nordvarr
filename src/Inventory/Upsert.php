<?php

declare(strict_types=1);

namespace Nordvarr\Inventory;

/**
 * Idempotentní upsert objektu ze strojového zdroje (API, ukázková data).
 *
 * Objekt se hledá podle (zdroj, external_id). Když je ve vstupu klíč
 * "attributes" nebo "relationships", bere se jako úplný stav daného zdroje:
 * atributy a odchozí vztahy tohoto zdroje, které ve vstupu chybí, se smažou.
 * Tagy se jen přidávají (jsou sdílené se zbytkem inventáře).
 */
final class Upsert
{
    public function __construct(private readonly Inventory $inventory)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: string, created: bool, changed: bool, changes: array<string, mixed>}
     */
    public function run(array $input, string $source): array
    {
        $data = $this->validate($input);

        return $this->inventory->transaction(function () use ($data, $source): array {
            $inv = $this->inventory;

            // Cíle vztahů musí existovat (nebo jde o tentýž objekt, což je chyba).
            $targets = array_map(static fn (array $r): string => $r['to_external_id'], $data['relationships'] ?? []);
            $missing = $inv->externalIds->missing($source, $targets);
            if ($missing !== []) {
                throw new ValidationException(['relationships' => 'missing_objects'], $missing);
            }

            $changes = [
                'object' => [],
                'attributes' => ['added' => [], 'updated' => [], 'removed' => []],
                'tags' => ['added' => []],
                'relationships' => ['added' => [], 'removed' => []],
                'docs' => false,
            ];

            $object = $inv->objects->findByExternalId($source, $data['external_id']);
            $created = $object === null;
            if ($created) {
                $id = $inv->objects->create([
                    'type' => $data['type'],
                    'name' => $data['name'],
                    'description' => $data['description'] ?? '',
                    'lifecycle' => $data['lifecycle'] ?? 'active',
                ]);
                $inv->externalIds->add($id, $source, $data['external_id']);
                $changes['object'] = ['created'];
            } else {
                $id = (string) $object['id'];
                $fields = ['type' => $data['type'], 'name' => $data['name']];
                foreach (['description', 'lifecycle'] as $optional) {
                    if (isset($data[$optional])) {
                        $fields[$optional] = $data[$optional];
                    }
                }
                $changes['object'] = $inv->objects->update($id, $fields);
            }

            if (isset($data['attributes'])) {
                foreach ($data['attributes'] as $key => [$value, $isJson]) {
                    $result = $inv->attributes->set($id, (string) $key, $value, $isJson, $source);
                    if ($result !== null) {
                        $changes['attributes'][$result][] = (string) $key;
                    }
                }
                foreach ($inv->attributes->keysFromSource($id, $source) as $key) {
                    if (!array_key_exists($key, $data['attributes'])) {
                        $inv->attributes->remove($id, $key, $source);
                        $changes['attributes']['removed'][] = $key;
                    }
                }
            }

            foreach ($data['tags'] ?? [] as $tag) {
                if ($inv->tags->attach($id, $tag)) {
                    $changes['tags']['added'][] = $tag;
                }
            }

            if (isset($data['relationships'])) {
                $wanted = [];
                foreach ($data['relationships'] as $rel) {
                    $toId = (string) $inv->externalIds->objectId($source, $rel['to_external_id']);
                    try {
                        $result = $inv->relate($id, $toId, $rel['kind'], $rel['note'], $source);
                    } catch (ValidationException $e) {
                        throw new ValidationException(['relationships' => $e->firstError()]);
                    }
                    $wanted[$result['id']] = true;
                    if ($result['created']) {
                        $changes['relationships']['added'][] = $rel['kind'] . ':' . $rel['to_external_id'];
                    }
                }
                foreach ($inv->relationships->outgoingFromSource($id, $source) as $existing) {
                    if (!isset($wanted[(string) $existing['id']])) {
                        $inv->relationships->delete((string) $existing['id']);
                        $changes['relationships']['removed'][] = $existing['kind'] . ':' . $existing['to_id'];
                    }
                }
            }

            if (isset($data['docs_markdown'])) {
                $changes['docs'] = $inv->docs->save($id, $data['docs_markdown'], 'api:' . $source);
            }

            $changed = $created || $changes['object'] !== [] || $changes['docs']
                || array_merge(...array_values($changes['attributes'])) !== []
                || $changes['tags']['added'] !== []
                || array_merge(...array_values($changes['relationships'])) !== [];
            if ($changed && !$created) {
                $inv->objects->touch($id);
            }

            return ['id' => $id, 'created' => $created, 'changed' => $changed, 'changes' => $changes];
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $errors = Inventory::validateObject($input);
        $externalId = $input['external_id'] ?? null;
        if (!is_string($externalId) || $externalId === '' || mb_strlen($externalId) > 200) {
            $errors['external_id'] = 'invalid_external_id';
        }
        $data = [
            'external_id' => $externalId,
            'type' => $input['type'] ?? null,
            'name' => is_string($input['name'] ?? null) ? trim($input['name']) : null,
        ];
        foreach (['description', 'lifecycle'] as $optional) {
            if (array_key_exists($optional, $input)) {
                $data[$optional] = $input[$optional];
            }
        }

        if (array_key_exists('attributes', $input)) {
            $attributes = $input['attributes'];
            if (!is_array($attributes) || (array_is_list($attributes) && $attributes !== [])) {
                $errors['attributes'] = 'invalid_attributes';
            } else {
                $data['attributes'] = [];
                foreach ($attributes as $key => $value) {
                    $key = (string) $key;
                    if (!Vocabulary::isValidAttributeKey($key) || $value === null) {
                        $errors['attributes'] = 'invalid_attribute:' . $key;
                        continue;
                    }
                    $encoded = Inventory::encodeAttributeValue($value);
                    if (mb_strlen($encoded[0]) > Inventory::MAX_ATTRIBUTE_VALUE) {
                        $errors['attributes'] = 'attribute_too_long:' . $key;
                        continue;
                    }
                    $data['attributes'][$key] = $encoded;
                }
            }
        }

        if (array_key_exists('tags', $input)) {
            if (!is_array($input['tags']) || !array_is_list($input['tags'])) {
                $errors['tags'] = 'invalid_tags';
            } else {
                $data['tags'] = [];
                foreach ($input['tags'] as $tag) {
                    $normalized = is_string($tag) ? Vocabulary::normalizeTag($tag) : null;
                    if ($normalized === null) {
                        $errors['tags'] = 'invalid_tag';
                        continue;
                    }
                    $data['tags'][] = $normalized;
                }
                $data['tags'] = array_values(array_unique($data['tags']));
            }
        }

        if (array_key_exists('relationships', $input)) {
            if (!is_array($input['relationships']) || !array_is_list($input['relationships'])) {
                $errors['relationships'] = 'invalid_relationships';
            } else {
                $data['relationships'] = [];
                foreach ($input['relationships'] as $rel) {
                    $kind = is_array($rel) ? ($rel['kind'] ?? null) : null;
                    $to = is_array($rel) ? ($rel['to_external_id'] ?? null) : null;
                    $note = is_array($rel) ? ($rel['note'] ?? '') : '';
                    if (!in_array($kind, Vocabulary::RELATIONSHIP_KINDS, true) || !is_string($to) || $to === '' || !is_string($note)) {
                        $errors['relationships'] = 'invalid_relationship';
                        continue;
                    }
                    if ($to === $externalId) {
                        $errors['relationships'] = 'self_relationship';
                        continue;
                    }
                    $data['relationships'][] = ['kind' => $kind, 'to_external_id' => $to, 'note' => $note];
                }
            }
        }

        if (array_key_exists('docs_markdown', $input)) {
            if (!is_string($input['docs_markdown']) || mb_strlen($input['docs_markdown']) > Inventory::MAX_DOC) {
                $errors['docs_markdown'] = 'invalid_docs';
            } else {
                $data['docs_markdown'] = $input['docs_markdown'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }
}
