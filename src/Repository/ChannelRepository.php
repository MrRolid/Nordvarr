<?php

declare(strict_types=1);

namespace Nordvarr\Repository;

use Nordvarr\Time;
use Nordvarr\Uuid;
use PDO;

/**
 * Notifikační kanály a jejich pravidla (tagy).
 */
final class ChannelRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $tags
     */
    public function create(string $name, string $type, array $config, bool $enabled, array $tags): string
    {
        $id = Uuid::v7();
        $now = Time::toDb(Time::now());
        $this->pdo->prepare('INSERT INTO channels (id, name, type, config, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $name, $type, self::encode($config), $enabled ? 1 : 0, $now, $now]);
        $this->setRules($id, $tags);

        return $id;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $tags
     */
    public function update(string $id, string $name, array $config, bool $enabled, array $tags): void
    {
        $this->pdo->prepare('UPDATE channels SET name = ?, config = ?, enabled = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, self::encode($config), $enabled ? 1 : 0, Time::toDb(Time::now()), $id]);
        $this->setRules($id, $tags);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM channels WHERE id = ?')->execute([$id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM channels WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->decode($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_map([$this, 'decode'], $this->pdo->query('SELECT * FROM channels ORDER BY created_at, id')->fetchAll());
    }

    /**
     * @param list<string> $tags
     */
    private function setRules(string $id, array $tags): void
    {
        $this->pdo->prepare('DELETE FROM channel_rules WHERE channel_id = ?')->execute([$id]);
        $insert = $this->pdo->prepare('INSERT INTO channel_rules (channel_id, tag) VALUES (?, ?)');
        foreach (array_unique($tags) as $tag) {
            $insert->execute([$id, $tag]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function encode(array $config): string
    {
        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        $config = json_decode((string) $row['config'], true);
        $row['config'] = is_array($config) ? $config : [];
        $row['enabled'] = (bool) $row['enabled'];
        $stmt = $this->pdo->prepare('SELECT tag FROM channel_rules WHERE channel_id = ? ORDER BY tag');
        $stmt->execute([$row['id']]);
        $row['tags'] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return $row;
    }
}
