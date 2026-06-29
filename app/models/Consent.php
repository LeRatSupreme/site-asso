<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des consentements RGPD (table `consents`).
 *
 * Chaque consentement (inscription, retrait, photos…) est journalisé avec
 * l'adresse IP et le user-agent afin d'en garder la preuve.
 */
final class Consent extends Model
{
    protected static string $table = 'consents';

    /**
     * Journalise un consentement (ou un retrait).
     *
     * @param array{user_id?:string|null, email?:string|null, ip_address?:string|null, user_agent?:string|null} $context
     */
    public static function log(
        string $type,
        bool $granted = true,
        string $textVersion = 'v1',
        array $context = []
    ): string {
        $id = 'con_' . bin2hex(random_bytes(12));

        $stmt = static::pdo()->prepare(
            'INSERT INTO consents (id, user_id, email, consent_type, text_version, ip_address, user_agent, granted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $context['user_id'] ?? null,
            $context['email'] ?? null,
            $type,
            $textVersion,
            $context['ip_address'] ?? null,
            $context['user_agent'] ?? null,
            $granted ? 1 : 0,
        ]);

        return $id;
    }

    /**
     * Liste les consentements d'un utilisateur (du plus récent au plus ancien).
     *
     * @return list<array<string,mixed>>
     */
    public static function forUser(string $userId): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT * FROM consents WHERE user_id = ? ORDER BY created_at DESC'
            );
            $stmt->execute([$userId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
