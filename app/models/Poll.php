<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des sondages.
 *
 * @table polls
 */
final class Poll extends Model
{
    protected static string $table = 'polls';

    /**
     * Tous les sondages publiés, triés par date de création décroissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function published(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT * FROM polls WHERE is_published = 1 ORDER BY created_at DESC'
            );
            /** @var list<array<string,mixed>> $result */
            $result = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return $result;
    }

    /**
     * Recherche dans les sondages publiés (titre).
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $like, int $limit = 3): array
    {
        $sql = 'SELECT * FROM polls
                WHERE is_published = 1 AND title LIKE ?
                ORDER BY created_at DESC
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
     * Recherche un sondage publié par son slug.
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT * FROM polls WHERE slug = ? AND is_published = 1 LIMIT 1'
            );
            $stmt->execute([$slug]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Recherche un sondage par son slug, publié ou non (admin).
     *
     * @return array<string,mixed>|null
     */
    public static function findAny(string $slug): ?array
    {
        try {
            $stmt = static::pdo()->prepare('SELECT * FROM polls WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Tous les sondages (publiés et brouillons), triés par date décroissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query('SELECT * FROM polls ORDER BY created_at DESC');

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Crée ou met à jour un sondage (upsert par id).
     *
     * @param array<string,mixed> $data
     * @return string L'identifiant du sondage.
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'poll_' . bin2hex(random_bytes(10));
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = self::slugify((string) ($data['title'] ?? ''));
        }

        $closesAt = trim((string) ($data['closes_at'] ?? ''));
        if ($closesAt !== '') {
            $closesAt = date('Y-m-d H:i:00', strtotime($closesAt));
            if ($closesAt === false) {
                $closesAt = null;
            } else {
                $closesAt = (string) $closesAt;
            }
        } else {
            $closesAt = null;
        }

        $fields = [
            'id'           => $id,
            'slug'         => $slug,
            'title'        => $data['title'] ?? '',
            'description'  => $data['description'] !== '' && $data['description'] !== null ? $data['description'] : null,
            'is_published' => !empty($data['is_published']) ? 1 : 0,
            'is_multiple'  => !empty($data['is_multiple']) ? 1 : 0,
            'closes_at'    => $closesAt,
        ];

        if ($isNew) {
            $cols = '`' . implode('`, `', array_keys($fields)) . '`';
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO polls (' . $cols . ') VALUES (' . $placeholders . ')'
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
            $stmt = static::pdo()->prepare('UPDATE polls SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM polls WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Options d'un sondage, triées par ordre d'affichage.
     *
     * @return list<array<string,mixed>>
     */
    public static function options(string $pollId): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT * FROM poll_options WHERE poll_id = ? ORDER BY `order` ASC, id ASC'
            );
            $stmt->execute([$pollId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Nombre total de votes pour un sondage.
     */
    public static function totalVotes(string $pollId): int
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT COUNT(*) FROM poll_votes WHERE poll_id = ?'
            );
            $stmt->execute([$pollId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Nombre de votants distincts pour un sondage.
     */
    public static function totalVoters(string $pollId): int
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT COUNT(DISTINCT user_id) FROM poll_votes WHERE poll_id = ?'
            );
            $stmt->execute([$pollId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Résultats détaillés d'un sondage : pour chaque option, label + nb votes + %.
     *
     * @return list<array<string,mixed>>
     */
    public static function results(string $pollId): array
    {
        $options = self::options($pollId);
        if ($options === []) {
            return [];
        }

        $counts = [];
        try {
            $stmt = static::pdo()->prepare(
                'SELECT option_id, COUNT(*) AS n FROM poll_votes WHERE poll_id = ? GROUP BY option_id'
            );
            $stmt->execute([$pollId]);

            foreach ($stmt->fetchAll() as $row) {
                $counts[(string) $row['option_id']] = (int) $row['n'];
            }
        } catch (\Throwable) {
            $counts = [];
        }

        $total = max(1, self::totalVoters($pollId));

        $results = [];
        foreach ($options as $option) {
            $n = $counts[(string) $option['id']] ?? 0;
            $results[] = [
                'id'        => $option['id'],
                'label'     => $option['label'],
                'order'     => $option['order'],
                'votes'     => $n,
                'percent'   => min(100, (int) round($n / $total * 100)),
            ];
        }

        return $results;
    }

    /**
     * Indique si un utilisateur a déjà voté à un sondage.
     */
    public static function hasVoted(string $pollId, string $userId): bool
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT 1 FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1'
            );
            $stmt->execute([$pollId, $userId]);

            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * IDs des options votées par un utilisateur sur un sondage.
     *
     * @return list<string>
     */
    public static function userVotes(string $pollId, string $userId): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT option_id FROM poll_votes WHERE poll_id = ? AND user_id = ?'
            );
            $stmt->execute([$pollId, $userId]);

            /** @var list<string> $ids */
            $ids = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $optionId) {
                $ids[] = (string) $optionId;
            }

            return $ids;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Indique si un sondage est fermé (closes_at dépassé).
     */
    public static function isClosed(array $poll): bool
    {
        $closesAt = (string) ($poll['closes_at'] ?? '');
        if ($closesAt === '' || $closesAt === '0000-00-00 00:00:00') {
            return false;
        }

        try {
            return new \DateTimeImmutable($closesAt) < new \DateTimeImmutable('now');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Transforme un titre en slug URL-safe (minuscule, sans accents, tirets).
     */
    public static function slugify(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'sondage';
        }

        // Translittération des accents.
        $text = str_replace(
            ['à', 'â', 'ä', 'á', 'ã', 'å', 'ç', 'è', 'é', 'ê', 'ë', 'ę', 'í', 'ì', 'î', 'ï',
             'ñ', 'ó', 'ò', 'ô', 'ö', 'õ', 'ú', 'ù', 'û', 'ü', 'ý', 'ÿ', 'œ', 'æ'],
            ['a', 'a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i',
             'n', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', 'oe', 'ae'],
            mb_strtolower($text)
        );

        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string) $text, '-');

        return $text === '' ? 'sondage' : $text;
    }
}
