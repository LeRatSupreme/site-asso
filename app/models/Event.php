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
     * Tous les événements publiés, triés par date croissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function published(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT * FROM events
                 WHERE is_published = 1
                 ORDER BY date ASC'
            );
            /** @var list<array<string,mixed>> $result */
            $result = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return $result;
    }

    /**
     * Événements publiés à venir (date >= NOW()), triés par date croissante.
     *
     * @param int $limit Nombre max de résultats (0 = illimité).
     * @return list<array<string,mixed>>
     */
    public static function upcoming(int $limit = 0): array
    {
        $sql = 'SELECT * FROM events
                WHERE is_published = 1 AND date >= NOW()
                ORDER BY date ASC';

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
     * Événements publiés passés (date < NOW()), triés par date décroissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function past(int $limit = 12): array
    {
        $sql = 'SELECT * FROM events
                WHERE is_published = 1 AND date < NOW()
                ORDER BY date DESC
                LIMIT ' . (int) $limit;

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
     * Événements à venir mis en avant : is_featured en priorité puis les autres,
     * triés par date croissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function featured(int $limit = 3): array
    {
        $sql = 'SELECT * FROM events
                WHERE is_published = 1 AND date >= NOW()
                ORDER BY is_featured DESC, date ASC
                LIMIT ' . (int) $limit;

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
     * Recherche un événement publié par son slug.
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT * FROM events WHERE slug = ? AND is_published = 1 LIMIT 1'
            );
            $stmt->execute([$slug]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Nombre total d'événements publiés.
     */
    public static function count(): int
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT COUNT(*) FROM events WHERE is_published = 1'
            );
            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Nombre d'inscriptions à un événement (table event_registrations).
     */
    public static function registrationsCount(string $eventId): int
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT COUNT(*) FROM event_registrations WHERE event_id = ?'
            );
            $stmt->execute([$eventId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Liste des 10 premiers inscrits (prénom + nom) à un événement.
     *
     * @return list<array<string,mixed>>
     */
    public static function registrationsNames(string $eventId, int $limit = 10): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT u.prenom, u.nom
                 FROM event_registrations r
                 INNER JOIN users u ON u.id = r.user_id
                 WHERE r.event_id = ?
                 ORDER BY r.created_at ASC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute([$eventId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Variantes d'un événement (menus/options) avec leurs choix,
     * triées par ordre d'affichage.
     *
     * @return list<array<string,mixed>> Chaque ligne : variant + choices (list).
     */
    public static function variants(string $eventId): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT * FROM event_variants
                 WHERE event_id = ?
                 ORDER BY `order` ASC'
            );
            $stmt->execute([$eventId]);

            /** @var list<array<string,mixed>> $variants */
            $variants = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        // On attache les choix de chaque variante.
        foreach ($variants as &$variant) {
            $variant['choices'] = static::variantChoices((string) $variant['id']);
        }
        unset($variant);

        return $variants;
    }

    /**
     * Choix d'une variante, triés par ordre.
     *
     * @return list<array<string,mixed>>
     */
    public static function variantChoices(string $variantId): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT * FROM event_variant_choices
                 WHERE variant_id = ?
                 ORDER BY `order` ASC'
            );
            $stmt->execute([$variantId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Photos liées à un événement (galerie).
     *
     * @return list<array<string,mixed>>
     */
    public static function photos(string $eventId): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT * FROM photos
                 WHERE event_id = ?
                 ORDER BY created_at ASC'
            );
            $stmt->execute([$eventId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
