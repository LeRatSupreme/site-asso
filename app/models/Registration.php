<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des inscriptions aux événements (table `event_registrations`).
 */
final class Registration extends Model
{
    protected static string $table = 'event_registrations';

    /**
     * Crée une inscription pour un utilisateur à un événement, avec les choix
     * de variantes éventuels. Garantit l'unicité (un user ne s'inscrit qu'une
     * fois par événement) via la contrainte unique DB.
     *
     * @param array<string,string> $variantChoices [variant_id => choice_id]
     * @return string Identifiant de l'inscription créée.
     *
     * @throws \PDOException en cas de doublon (déjà inscrit).
     */
    public static function create(string $userId, string $eventId, array $variantChoices = []): string
    {
        $pdo = static::pdo();
        $registrationId = 'reg_' . bin2hex(random_bytes(12));

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO event_registrations (id, user_id, event_id) VALUES (?, ?, ?)'
            );
            $stmt->execute([$registrationId, $userId, $eventId]);

            foreach ($variantChoices as $variantId => $choiceId) {
                $stmt = $pdo->prepare(
                    'INSERT INTO event_registration_choices (id, registration_id, variant_id, choice_id)
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([
                    'rc_' . bin2hex(random_bytes(10)),
                    $registrationId,
                    $variantId,
                    $choiceId,
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $registrationId;
    }

    /**
     * Indique si un utilisateur est inscrit à un événement.
     */
    public static function isRegistered(string $userId, string $eventId): bool
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT 1 FROM event_registrations WHERE user_id = ? AND event_id = ? LIMIT 1'
            );
            $stmt->execute([$userId, $eventId]);

            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Supprime l'inscription d'un utilisateur à un événement.
     */
    public static function unregister(string $userId, string $eventId): void
    {
        $stmt = static::pdo()->prepare(
            'DELETE FROM event_registrations WHERE user_id = ? AND event_id = ?'
        );
        $stmt->execute([$userId, $eventId]);
    }

    /**
     * Événements auxquels un utilisateur est inscrit, avec date et statut
     * (à venir / passé), triés par date.
     *
     * @return list<array<string,mixed>>
     */
    public static function forUser(string $userId): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT e.id, e.slug, e.title, e.date, e.location, r.created_at
                 FROM event_registrations r
                 INNER JOIN events e ON e.id = r.event_id
                 WHERE r.user_id = ?
                 ORDER BY e.date ASC'
            );
            $stmt->execute([$userId]);

            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rows as &$row) {
            $row['is_past'] = ($row['date'] ?? '') < $now;
        }
        unset($row);

        return $rows;
    }

    /**
     * Prochains événements (à venir) auxquels l'utilisateur est inscrit.
     *
     * @return list<array<string,mixed>>
     */
    public static function upcomingForUser(string $userId, int $limit = 3): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT e.id, e.slug, e.title, e.date, e.location
                 FROM event_registrations r
                 INNER JOIN events e ON e.id = r.event_id
                 WHERE r.user_id = ? AND e.date >= NOW()
                 ORDER BY e.date ASC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute([$userId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
