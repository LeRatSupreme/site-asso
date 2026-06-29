<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des utilisateurs.
 *
 * @table users
 */
final class User extends Model
{
    protected static string $table = 'users';

    /**
     * Nombre d'utilisateurs actifs (membres de l'association).
     */
    public static function countActive(): int
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT COUNT(*) FROM users WHERE is_active = 1'
            );

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
