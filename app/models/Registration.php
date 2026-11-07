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
     * Génère un token unique (48 caractères hexa) pour le check-in QR.
     */
    public static function generateQrToken(): string
    {
        do {
            $token = bin2hex(random_bytes(24)); // 48 caractères, unicité quasi garantie.
            $stmt = static::pdo()->prepare(
                'SELECT 1 FROM event_registrations WHERE qr_token = ? LIMIT 1'
            );
            $stmt->execute([$token]);
        } while ($stmt->fetchColumn() !== false);

        return $token;
    }

    /**
     * Crée une inscription pour un utilisateur à un événement, avec les choix
     * de variantes éventuels. Garantit l'unicité (un user ne s'inscrit qu'une
     * fois par événement) via la contrainte unique DB. Génère un token QR.
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
        $qrToken = self::generateQrToken();

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO event_registrations (id, user_id, event_id, qr_token) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$registrationId, $userId, $eventId, $qrToken]);

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

    /**
     * Token QR d'une inscription (null si non inscrit ou sans token).
     */
    public static function qrTokenForUser(string $userId, string $eventId): ?string
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT qr_token FROM event_registrations WHERE user_id = ? AND event_id = ? LIMIT 1'
            );
            $stmt->execute([$userId, $eventId]);

            $token = $stmt->fetchColumn();

            return $token !== false && $token !== null ? (string) $token : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Valide un token pour un événement et marque l'inscription comme présente.
     *
     * @return array{ok:bool, message:string, name?:string, already?:bool}
     */
    public static function checkInByToken(string $eventId, string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'message' => 'Token vide.'];
        }

        try {
            $stmt = static::pdo()->prepare(
                'SELECT r.id, r.checked_in, u.prenom, u.nom
                 FROM event_registrations r
                 INNER JOIN users u ON u.id = r.user_id
                 WHERE r.event_id = ? AND r.qr_token = ?
                 LIMIT 1'
            );
            $stmt->execute([$eventId, $token]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Erreur de base de données.'];
        }

        if ($row === false) {
            return ['ok' => false, 'message' => 'Token invalide.'];
        }

        $name = trim(((string) ($row['prenom'] ?? '')) . ' ' . ((string) ($row['nom'] ?? '')));

        if (!empty($row['checked_in'])) {
            return [
                'ok'      => true,
                'already' => true,
                'name'    => $name,
                'message' => $name . ' était déjà présent.',
            ];
        }

        $upd = static::pdo()->prepare('UPDATE event_registrations SET checked_in = 1 WHERE id = ?');
        $upd->execute([(string) $row['id']]);

        return [
            'ok'      => true,
            'name'    => $name,
            'message' => $name . ' — Présent',
        ];
    }

    /**
     * Bascule manuellement le statut « présent » d'une inscription (admin).
     *
     * @return bool Nouvel état (true = présent).
     */
    public static function toggleCheckedIn(string $registrationUserId, string $eventId): bool
    {
        $stmt = static::pdo()->prepare(
            'SELECT id, checked_in FROM event_registrations
             WHERE user_id = ? AND event_id = ? LIMIT 1'
        );
        $stmt->execute([$registrationUserId, $eventId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return false;
        }

        $new = empty($row['checked_in']) ? 1 : 0;
        $upd = static::pdo()->prepare('UPDATE event_registrations SET checked_in = ? WHERE id = ?');
        $upd->execute([$new, (string) $row['id']]);

        return (bool) $new;
    }
}
