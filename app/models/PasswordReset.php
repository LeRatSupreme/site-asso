<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des tokens de réinitialisation de mot de passe (à usage unique).
 *
 * Le token stocké est haché (SHA-256) : seul le token en clair transite par
 * l'e-mail, jamais sa version hachée. Chaque token expire et est à usage unique.
 */
final class PasswordReset extends Model
{
    protected static string $table = 'password_resets';

    /** Durée de validité d'un token (heures). */
    public const EXPIRES_HOURS = 1;

    /**
     * Crée un token pour un utilisateur et renvoie le token en clair.
     */
    public static function createToken(string $userId): string
    {
        // Invalide les tokens précédents non utilisés.
        $stmt = static::pdo()->prepare(
            'UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);

        $token = bin2hex(random_bytes(32));
        $id = 'pr_' . bin2hex(random_bytes(10));

        $stmt = static::pdo()->prepare(
            'INSERT INTO password_resets (id, user_id, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR))'
        );
        $stmt->execute([$id, $userId, hash('sha256', $token), self::EXPIRES_HOURS]);

        return $token;
    }

    /**
     * Valide un token en clair et renvoie l'utilisateur associé, ou null.
     *
     * @return array<string,mixed>|null
     */
    public static function validate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $stmt = static::pdo()->prepare(
            'SELECT pr.id AS reset_id, pr.user_id, u.*
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ?
               AND pr.used_at IS NULL
               AND pr.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Marque un token comme consommé (à usage unique).
     */
    public static function consume(string $resetId): void
    {
        $stmt = static::pdo()->prepare(
            'UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$resetId]);
    }
}
