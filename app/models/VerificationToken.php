<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des tokens de confirmation d'e-mail à l'inscription (à usage unique).
 *
 * Le token stocké est haché (SHA-256) : seul le token en clair transite par
 * l'e-mail, jamais sa version hachée. Chaque token expire et est à usage unique.
 */
final class VerificationToken extends Model
{
    protected static string $table = 'verification_tokens';

    /** Durée de validité d'un token (heures). */
    public const EXPIRES_HOURS = 24;

    /**
     * Crée un token de vérification pour un utilisateur et renvoie le token en clair.
     */
    public static function createToken(string $userId): string
    {
        // Invalide les tokens précédents non utilisés.
        $stmt = static::pdo()->prepare(
            'UPDATE verification_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);

        $token = bin2hex(random_bytes(32));
        $id = 'vrf_' . bin2hex(random_bytes(10));

        $stmt = static::pdo()->prepare(
            'INSERT INTO verification_tokens (id, user_id, token_hash, expires_at)
             VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? HOUR))'
        );
        $stmt->execute([$id, $userId, hash('sha256', $token), self::EXPIRES_HOURS]);

        return $token;
    }

    /**
     * Valide un token en clair et renvoie l'enregistrement associé, ou null.
     *
     * @return array<string,mixed>|null
     */
    public static function validate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $stmt = static::pdo()->prepare(
            'SELECT t.id AS token_id, t.user_id, u.email, u.prenom, u.email_verified_at
             FROM verification_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ?
               AND t.used_at IS NULL
               AND t.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Marque un token comme consommé (à usage unique).
     */
    public static function consume(string $tokenId): void
    {
        $stmt = static::pdo()->prepare(
            'UPDATE verification_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$tokenId]);
    }
}
