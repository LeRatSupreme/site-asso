<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des votes de sondage.
 *
 * @table poll_votes
 */
final class PollVote extends Model
{
    protected static string $table = 'poll_votes';

    /**
     * Enregistre un vote (INSERT IGNORE : pas d'erreur si déjà voté pour l'option).
     */
    public static function cast(string $pollId, string $optionId, string $userId): bool
    {
        $id = 'vote_' . bin2hex(random_bytes(10));

        try {
            $stmt = static::pdo()->prepare(
                'INSERT IGNORE INTO poll_votes (`id`, `poll_id`, `option_id`, `user_id`) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$id, $pollId, $optionId, $userId]);

            return $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Indique si un utilisateur a déjà voté à un sondage.
     */
    public static function hasVoted(string $pollId, string $userId): bool
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT 1 FROM poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1'
            );
            $stmt->execute([$pollId, $userId]);

            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
