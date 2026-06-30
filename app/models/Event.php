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
     * Recherche pleine dans les événements publiés (titre OU excerpt).
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $like, int $limit = 5): array
    {
        $sql = 'SELECT * FROM events
                WHERE is_published = 1
                  AND (title LIKE ? OR excerpt LIKE ?)
                ORDER BY date DESC
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
     * Nombre d'événements publiés.
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

    // -----------------------------------------------------------------
    //  Méthodes d'administration (tous statuts confondus).
    // -----------------------------------------------------------------

    /**
     * Tous les événements (publiés et brouillons), triés par date décroissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query('SELECT * FROM events ORDER BY date DESC');

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Recherche un événement par son slug, sans filtre de publication (admin).
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlugAny(string $slug): ?array
    {
        try {
            $stmt = static::pdo()->prepare('SELECT * FROM events WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Crée ou met à jour un événement (upsert par id).
     *
     * @param array<string,mixed> $data
     * @return string L'identifiant de l'événement.
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'evt_' . bin2hex(random_bytes(10));
        }

        $fields = [
            'id'            => $id,
            'slug'          => $data['slug'] ?? '',
            'title'         => $data['title'] ?? '',
            'excerpt'       => $data['excerpt'] ?? null,
            'description'   => $data['description'] ?? null,
            'image'         => $data['image'] ?? null,
            'date'          => $data['date'] ?? date('Y-m-d H:i:s'),
            'end_date'      => $data['end_date'] ?? null,
            'location'      => $data['location'] ?? null,
            'category'      => ($data['category'] ?? '') !== '' ? $data['category'] : null,
            'sumup_link'    => $data['sumup_link'] ?? null,
            'price'         => $data['price'] !== '' && $data['price'] !== null ? $data['price'] : null,
            'max_capacity'  => $data['max_capacity'] !== '' && $data['max_capacity'] !== null ? $data['max_capacity'] : null,
            'is_featured'   => !empty($data['is_featured']) ? 1 : 0,
            'is_published'  => !empty($data['is_published']) ? 1 : 0,
            'show_map'      => !empty($data['show_map']) ? 1 : 0,
            'map_lat'       => ($data['map_lat'] ?? '') !== '' ? $data['map_lat'] : null,
            'map_lon'       => ($data['map_lon'] ?? '') !== '' ? $data['map_lon'] : null,
        ];

        if ($isNew) {
            $cols = '`' . implode('`, `', array_keys($fields)) . '`';
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO events (' . $cols . ') VALUES (' . $placeholders . ')'
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
            $stmt = static::pdo()->prepare('UPDATE events SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM events WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Géocode une adresse/lieu en (lat, lon) via Nominatim (OpenStreetMap).
     *
     * Retourne ['lat' => string, 'lon' => string] ou null si échoc/non trouvé.
     */
    public static function geocode(string $location): ?array
    {
        $q = trim($location);
        if ($q === '') {
            return null;
        }

        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . rawurlencode($q);
        $ctx = stream_context_create([
            'http' => [
                'header'        => "User-Agent: AEIC/1.0 (calais.aeic@gmail.com)\r\n",
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
        ]);
        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || $data === []) {
            return null;
        }

        return [
            'lat' => (string) ($data[0]['lat'] ?? ''),
            'lon' => (string) ($data[0]['lon'] ?? ''),
        ];
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
