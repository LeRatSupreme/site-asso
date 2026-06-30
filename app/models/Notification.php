<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des notifications in-app (table `notifications`).
 */
final class Notification extends Model
{
    protected static string $table = 'notifications';

    /**
     * Notifications d'un utilisateur, triées par date décroissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function forUser(string $userId, bool $unreadOnly = false): array
    {
        $sql = 'SELECT * FROM notifications WHERE user_id = ?';
        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 50';

        try {
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([$userId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Nombre de notifications non lues d'un utilisateur.
     */
    public static function unreadCount(string $userId): int
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'
            );
            $stmt->execute([$userId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Crée une notification pour un utilisateur.
     *
     * @return string Identifiant de la notification créée.
     */
    public static function create(
        string $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $url = null
    ): string {
        $id = 'notif_' . bin2hex(random_bytes(10));

        $stmt = static::pdo()->prepare(
            'INSERT INTO notifications (`id`, `user_id`, `type`, `title`, `body`, `url`, `is_read`)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([$id, $userId, $type, $title, $body, $url]);

        return $id;
    }

    /**
     * Marque une notification comme lue (appartenance vérifiée).
     */
    public static function markAsRead(string $id, string $userId): void
    {
        $stmt = static::pdo()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);
    }

    /**
     * Marque toutes les notifications d'un utilisateur comme lues.
     */
    public static function markAllAsRead(string $userId): void
    {
        $stmt = static::pdo()->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([$userId]);
    }
}
