<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des options de sondage.
 *
 * @table poll_options
 */
final class PollOption extends Model
{
    protected static string $table = 'poll_options';

    /**
     * Crée une option pour un sondage et renvoie son identifiant.
     */
    public static function save(string $pollId, string $label, int $order): string
    {
        $id = 'opt_' . bin2hex(random_bytes(10));

        $stmt = static::pdo()->prepare(
            'INSERT INTO poll_options (`id`, `poll_id`, `label`, `order`) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$id, $pollId, $label, $order]);

        return $id;
    }

    /**
     * Supprime toutes les options d'un sondage.
     */
    public static function deleteByPoll(string $pollId): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM poll_options WHERE poll_id = ?');
        $stmt->execute([$pollId]);
    }
}
