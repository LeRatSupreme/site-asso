<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle de la bibliothèque de médias (table `media`).
 */
final class Media extends Model
{
    protected static string $table = 'media';

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 0): array
    {
        $sql = 'SELECT * FROM media ORDER BY created_at DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        try {
            $stmt = static::pdo()->query($sql);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function create(array $data): string
    {
        $id = 'med_' . bin2hex(random_bytes(12));

        $stmt = static::pdo()->prepare(
            'INSERT INTO media (id, name, url, type, mime_type, alt, size)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['name'] ?? '',
            $data['url'] ?? '',
            $data['type'] ?? 'image',
            $data['mime_type'] ?? null,
            $data['alt'] ?? null,
            $data['size'] ?? null,
        ]);

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM media WHERE id = ?');
        $stmt->execute([$id]);
    }
}
