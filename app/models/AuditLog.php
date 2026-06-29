<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle du journal d'audit (table `audit_logs`).
 *
 * Enregistre qui a fait quoi, quand (traçabilité indispensable en comptabilité
 * et pour la gestion des comptes administrateurs).
 */
final class AuditLog extends Model
{
    protected static string $table = 'audit_logs';

    /**
     * Journalise une action.
     *
     * @param array<string,mixed>|null $details
     */
    public static function log(
        string $action,
        ?string $userId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $details = null
    ): string {
        $id = 'aud_' . bin2hex(random_bytes(12));

        $stmt = static::pdo()->prepare(
            'INSERT INTO audit_logs (id, user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $action,
            $entityType,
            $entityId,
            $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            client_ip(),
        ]);

        return $id;
    }

    /**
     * Dernières entrées (les plus récentes d'abord).
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 20): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT a.*, u.prenom, u.nom
                 FROM audit_logs a
                 LEFT JOIN users u ON u.id = a.user_id
                 ORDER BY a.created_at DESC
                 LIMIT ' . (int) $limit
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
