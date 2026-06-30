<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des pages CMS.
 */
final class Page extends Model
{
    protected static string $table = 'pages';

    /**
     * Recherche une page publiée par son slug.
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        $stmt = static::pdo()->prepare(
            'SELECT * FROM pages WHERE slug = ? AND is_published = 1 LIMIT 1'
        );
        $stmt->execute([$slug]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Recherche dans les pages publiées (titre).
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $like, int $limit = 3): array
    {
        $sql = 'SELECT * FROM pages
                WHERE is_published = 1 AND title LIKE ?
                ORDER BY title ASC
                LIMIT ' . (int) $limit;

        try {
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([$like]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Toutes les pages (admin), triées par titre.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query('SELECT * FROM pages ORDER BY title ASC');

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Recherche une page par son slug, sans filtre de publication (admin).
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlugAny(string $slug): ?array
    {
        $stmt = static::pdo()->prepare('SELECT * FROM pages WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Crée ou met à jour une page (upsert par id).
     *
     * @param array<string,mixed> $data
     * @return string L'identifiant de la page.
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'page_' . bin2hex(random_bytes(10));
        }

        $fields = [
            'id'               => $id,
            'slug'             => $data['slug'] ?? '',
            'title'            => $data['title'] ?? '',
            'content'          => $data['content'] ?? null,
            'meta_title'       => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'is_published'     => !empty($data['is_published']) ? 1 : 0,
        ];

        if ($isNew) {
            $cols = '`' . implode('`, `', array_keys($fields)) . '`';
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO pages (' . $cols . ') VALUES (' . $placeholders . ')'
            );
            $stmt->execute(array_values($fields));
        } else {
            $set = [];
            foreach (array_keys($fields) as $col) {
                if ($col === 'id') {
                    continue;
                }
                $set[] = $col . ' = ?';
            }
            $values = array_values(array_diff_key($fields, ['id' => null]));
            $values[] = $id;
            $stmt = static::pdo()->prepare('UPDATE pages SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM pages WHERE id = ?');
        $stmt->execute([$id]);
    }
}
