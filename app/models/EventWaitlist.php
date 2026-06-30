<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Liste d'attente des événements complets (table `event_waitlist`).
 *
 * File d'attente FIFO par événement : la position est déterminée par
 * l'ordre chronologique d'inscription (colonne `created_at`).
 */
final class EventWaitlist extends Model
{
    protected static string $table = 'event_waitlist';

    /**
     * Ajoute un utilisateur à la file d'attente d'un événement (idempotent).
     *
     * @return int Position dans la file (>= 1).
     */
    public static function add(string $userId, string $eventId): int
    {
        $pdo = static::pdo();

        $stmt = $pdo->prepare('SELECT id FROM event_waitlist WHERE user_id = ? AND event_id = ? LIMIT 1');
        $stmt->execute([$userId, $eventId]);

        if ($stmt->fetchColumn() === false) {
            $id = 'wl_' . bin2hex(random_bytes(10));
            $stmt = $pdo->prepare(
                'INSERT INTO event_waitlist (id, event_id, user_id) VALUES (?, ?, ?)'
            );
            $stmt->execute([$id, $eventId, $userId]);
        }

        return self::position($userId, $eventId);
    }

    /**
     * Position d'un utilisateur dans la file d'attente d'un événement.
     *
     * @return int 0 s'il n'est pas en attente, sinon >= 1.
     */
    public static function position(string $userId, string $eventId): int
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT COUNT(*) FROM event_waitlist
                 WHERE event_id = ?
                   AND created_at <= (
                       SELECT created_at FROM event_waitlist
                       WHERE user_id = ? AND event_id = ? LIMIT 1
                   )'
            );
            $stmt->execute([$eventId, $userId, $eventId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Indique si un utilisateur est en liste d'attente pour un événement.
     */
    public static function isOnList(string $userId, string $eventId): bool
    {
        return self::position($userId, $eventId) > 0;
    }

    /**
     * Supprime l'entrée d'attente d'un utilisateur pour un événement.
     */
    public static function remove(string $userId, string $eventId): void
    {
        $stmt = static::pdo()->prepare(
            'DELETE FROM event_waitlist WHERE user_id = ? AND event_id = ?'
        );
        $stmt->execute([$userId, $eventId]);
    }

    /**
     * Premier utilisateur en attente (le plus ancien), avec ses infos.
     *
     * @return array<string,mixed>|null Ligne {id, user_id, event_id, created_at, prenom, nom, email}.
     */
    public static function first(string $eventId): ?array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT w.id, w.user_id, w.event_id, w.created_at,
                        u.prenom, u.nom, u.email
                 FROM event_waitlist w
                 INNER JOIN users u ON u.id = w.user_id
                 WHERE w.event_id = ?
                 ORDER BY w.created_at ASC
                 LIMIT 1'
            );
            $stmt->execute([$eventId]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Liste complète de la file d'attente (avec position et infos utilisateur).
     *
     * @return list<array<string,mixed>> Lignes {id, user_id, created_at, prenom, nom, position}.
     */
    public static function list(string $eventId): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT w.id, w.user_id, w.created_at,
                        u.prenom, u.nom
                 FROM event_waitlist w
                 INNER JOIN users u ON u.id = w.user_id
                 WHERE w.event_id = ?
                 ORDER BY w.created_at ASC'
            );
            $stmt->execute([$eventId]);

            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $position = 1;
        foreach ($rows as &$row) {
            $row['position'] = $position++;
        }
        unset($row);

        return $rows;
    }

    /**
     * Compte le nombre d'utilisateurs en attente pour un événement.
     */
    public static function count(string $eventId): int
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT COUNT(*) FROM event_waitlist WHERE event_id = ?'
            );
            $stmt->execute([$eventId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
