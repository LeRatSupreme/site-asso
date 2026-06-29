<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des membres du bureau (page /team).
 */
final class TeamMember extends Model
{
    protected static string $table = 'team_members';

    /**
     * Membres actifs, triés par ordre d'affichage (mis en avant en premier).
     *
     * @return list<array<string,mixed>>
     */
    public static function active(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT * FROM team_members
                 WHERE is_active = 1
                 ORDER BY is_highlight DESC, `order` ASC, prenom ASC'
            );

            /** @var list<array<string,mixed>> $result */
            $result = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return $result;
    }

    /**
     * Membres actifs mis en avant (bureau restreint), triés par ordre.
     *
     * @return list<array<string,mixed>>
     */
    public static function highlighted(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT * FROM team_members
                 WHERE is_active = 1 AND is_highlight = 1
                 ORDER BY `order` ASC, prenom ASC'
            );

            /** @var list<array<string,mixed>> $result */
            $result = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return $result;
    }
}
