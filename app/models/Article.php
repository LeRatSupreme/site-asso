<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des articles de blog / actualités.
 *
 * @table articles
 */
final class Article extends Model
{
    protected static string $table = 'articles';

    /**
     * Articles publiés, triés par date de publication décroissante.
     *
     * @param int $limit Nombre max de résultats (0 = illimité).
     * @return list<array<string,mixed>>
     */
    public static function published(int $limit = 0): array
    {
        $sql = 'SELECT * FROM articles
                WHERE is_published = 1
                ORDER BY COALESCE(published_at, created_at) DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        try {
            $stmt = static::pdo()->query($sql);
            /** @var list<array<string,mixed>> $result */
            $result = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return $result;
    }

    /**
     * Recherche un article publié par son slug.
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT * FROM articles WHERE slug = ? AND is_published = 1 LIMIT 1'
            );
            $stmt->execute([$slug]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Recherche un article par son slug, sans filtre de publication (admin).
     *
     * @return array<string,mixed>|null
     */
    public static function findAny(string $slug): ?array
    {
        try {
            $stmt = static::pdo()->prepare('SELECT * FROM articles WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Tous les articles (publiés et brouillons), triés par date décroissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT * FROM articles ORDER BY COALESCE(published_at, created_at) DESC'
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Recherche pleine dans les articles publiés (titre OU extrait).
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $like, int $limit = 5): array
    {
        $sql = 'SELECT * FROM articles
                WHERE is_published = 1
                  AND (title LIKE ? OR excerpt LIKE ?)
                ORDER BY COALESCE(published_at, created_at) DESC
                LIMIT ' . (int) $limit;

        try {
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([$like, $like]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Crée ou met à jour un article (upsert par id).
     *
     * @param array<string,mixed> $data
     * @return string L'identifiant de l'article.
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'art_' . bin2hex(random_bytes(10));
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = self::slugify((string) ($data['title'] ?? ''));
        }

        $category = trim((string) ($data['category'] ?? ''));
        $isPublished = !empty($data['is_published']) ? 1 : 0;

        // published_at = NOW() si publié et pas déjà de date ; sinon on conserve.
        $existingPublishedAt = null;
        if (!$isNew) {
            $existing = self::find($id);
            $existingPublishedAt = $existing['published_at'] ?? null;
        }
        $publishedAt = $isPublished ? ($existingPublishedAt ?? date('Y-m-d H:i:s')) : null;

        $fields = [
            'id'            => $id,
            'slug'          => $slug,
            'title'         => $data['title'] ?? '',
            'excerpt'       => trim((string) ($data['excerpt'] ?? '')) !== '' ? $data['excerpt'] : null,
            'content'       => $data['content'] ?? '',
            'image'         => trim((string) ($data['image'] ?? '')) !== '' ? $data['image'] : null,
            'category'      => $category !== '' ? $category : null,
            'is_published'  => $isPublished,
            'published_at'  => $publishedAt,
        ];

        if ($isNew) {
            $cols = '`' . implode('`, `', array_keys($fields)) . '`';
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO articles (' . $cols . ') VALUES (' . $placeholders . ')'
            );
            $stmt->execute(array_values($fields));
        } else {
            $set = [];
            foreach (array_keys($fields) as $col) {
                if ($col === 'id') {
                    continue;
                }
                $set[] = '`' . $col . '` = ?';
            }
            $values = array_values(array_diff_key($fields, ['id' => null]));
            $values[] = $id;
            $stmt = static::pdo()->prepare('UPDATE articles SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM articles WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Transforme un titre en slug URL-safe (minuscule, sans accents, tirets).
     */
    public static function slugify(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'article';
        }

        $text = str_replace(
            ['à', 'â', 'ä', 'á', 'ã', 'å', 'ç', 'è', 'é', 'ê', 'ë', 'ę', 'í', 'ì', 'î', 'ï',
             'ñ', 'ó', 'ò', 'ô', 'ö', 'õ', 'ú', 'ù', 'û', 'ü', 'ý', 'ÿ', 'œ', 'æ'],
            ['a', 'a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i',
             'n', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', 'oe', 'ae'],
            mb_strtolower($text)
        );

        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string) $text, '-');

        return $text === '' ? 'article' : $text;
    }
}
