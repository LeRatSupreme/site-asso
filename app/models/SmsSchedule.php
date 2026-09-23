<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Messages programmés du rapport SMS (page admin Notifications).
 *
 * Chaque ligne décrit un message : libellé, activation, jours d'envoi
 * (CSV ISO-8601 « 1,2,3,4,5 »), heure, modèle de message et dernier jour
 * envoyé (garde anti-doublon). Les destinataires sont partagés
 * (setting sms_recipients).
 */
final class SmsSchedule extends Model
{
    protected static string $table = 'sms_reports';

    /**
     * Liste des messages, du plus récent au plus ancien.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        $stmt = static::pdo()->query('SELECT * FROM sms_reports ORDER BY created_at DESC, id');

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Messages activés (le filtrage jour/heure/fenêtre est fait par
     * SmsReport::scheduleDue()).
     *
     * @return list<array<string,mixed>>
     */
    public static function enabled(): array
    {
        $stmt = static::pdo()->query('SELECT * FROM sms_reports WHERE is_enabled = 1 ORDER BY send_time');

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Crée un message et renvoie son id.
     *
     * @param array{label?:string, is_enabled?:bool, days?:string, send_time?:string, template?:string} $data
     */
    public static function create(array $data = []): string
    {
        $id = 'sms_' . bin2hex(random_bytes(10));
        $stmt = static::pdo()->prepare(
            'INSERT INTO sms_reports (id, label, is_enabled, days, send_time, template)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            mb_substr(trim((string) ($data['label'] ?? '')), 0, 80),
            !empty($data['is_enabled']) ? 1 : 0,
            (string) ($data['days'] ?? '1,2,3,4,5'),
            (string) ($data['send_time'] ?? '20:00'),
            (string) ($data['template'] ?? ''),
        ]);

        return $id;
    }

    /**
     * Met à jour un message.
     *
     * @param array{label?:string, is_enabled?:bool, days?:string, send_time?:string, template?:string} $data
     */
    public static function updateRow(string $id, array $data): void
    {
        $stmt = static::pdo()->prepare(
            'UPDATE sms_reports
             SET label = ?, is_enabled = ?, days = ?, send_time = ?, template = ?
             WHERE id = ?'
        );
        $stmt->execute([
            mb_substr(trim((string) ($data['label'] ?? '')), 0, 80),
            !empty($data['is_enabled']) ? 1 : 0,
            (string) ($data['days'] ?? '1,2,3,4,5'),
            (string) ($data['send_time'] ?? '20:00'),
            (string) ($data['template'] ?? ''),
            $id,
        ]);
    }

    /**
     * Marque le message comme envoyé pour le jour donné (anti-doublon).
     */
    public static function setLastSent(string $id, string $day): void
    {
        $stmt = static::pdo()->prepare('UPDATE sms_reports SET last_sent_day = ? WHERE id = ?');
        $stmt->execute([$day, $id]);
    }

    public static function delete(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM sms_reports WHERE id = ?');
        $stmt->execute([$id]);
    }
}
