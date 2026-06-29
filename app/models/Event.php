<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des événements.
 *
 * @table events
 */
final class Event extends Model
{
    protected static string $table = 'events';

    /**
     * Événements publiés, à venir (triés par date croissante).
     *
     * @return list<array<string,mixed>>
     */
    public static function upcoming(int $limit = 3): array
    {
        $sql = 'SELECT * FROM events
                WHERE is_published = 1 AND date >= NOW()
                ORDER BY is_featured DESC, date ASC
                LIMIT ' . (int) $limit;

        $stmt = static::pdo()->query($sql);

        /** @var list<array<string,mixed>> $result */
        $result = $stmt->fetchAll();

        return $result;
    }

    /**
     * Tous les événements publiés à venir (agenda).
     *
     * @return list<array<string,mixed>>
     */
    public static function publishedUpcoming(): array
    {
        $stmt = static::pdo()->query(
            'SELECT * FROM events
             WHERE is_published = 1 AND date >= NOW()
             ORDER BY date ASC'
        );

        /** @var list<array<string,mixed>> $result */
        $result = $stmt->fetchAll();

        return $result;
    }

    /**
     * Événements publiés passés (archives), triés par date décroissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function publishedPast(int $limit = 12): array
    {
        $sql = 'SELECT * FROM events
                WHERE is_published = 1 AND date < NOW()
                ORDER BY date DESC
                LIMIT ' . (int) $limit;

        $stmt = static::pdo()->query($sql);

        /** @var list<array<string,mixed>> $result */
        $result = $stmt->fetchAll();

        return $result;
    }

    /**
     * Recherche un événement publié par son slug.
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        $stmt = static::pdo()->prepare(
            'SELECT * FROM events WHERE slug = ? AND is_published = 1 LIMIT 1'
        );
        $stmt->execute([$slug]);

        $row = $stmt->fetch();

        return $row ?: null;
    }
}
