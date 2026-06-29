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

    /**
     * Tous les membres (admin), triés par ordre.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query('SELECT * FROM team_members ORDER BY `order` ASC, prenom ASC');

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Crée ou met à jour un membre du bureau (upsert par id).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'tm_' . bin2hex(random_bytes(10));
        }

        $fields = [
            'id'           => $id,
            'prenom'       => $data['prenom'] ?? '',
            'nom'          => $data['nom'] ?? '',
            'role'         => $data['role'] ?? '',
            'pole'         => $data['pole'] ?? 'bureau',
            'bio'          => $data['bio'] ?? null,
            'photo'        => $data['photo'] ?? null,
            'is_highlight' => !empty($data['is_highlight']) ? 1 : 0,
            'order'        => (int) ($data['order'] ?? 0),
            'is_active'    => isset($data['is_active']) ? ((int) $data['is_active']) : 1,
        ];

        if ($isNew) {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO team_members (' . $cols . ') VALUES (' . $placeholders . ')'
            );
            $stmt->execute(array_values($fields));
        } else {
            $set = [];
            foreach (array_keys($fields) as $col) {
                if ($col === 'id') {
                    continue;
                }
                $set[] = '`' . $col . '` = ?';
            }
            $values = array_values(array_diff_key($fields, ['id' => null]));
            $values[] = $id;
            $stmt = static::pdo()->prepare('UPDATE team_members SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM team_members WHERE id = ?');
        $stmt->execute([$id]);
    }
}
