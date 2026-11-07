<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des promotions cafétéria (promos & ventes spéciales).
 */
final class Promotion extends Model
{
    protected static string $table = 'promotions';

    /**
     * Promotions actuellement actives et publiées.
     *
     * Règles : is_active = 1, starts_at dans le passé, ends_at NULL ou futur.
     *
     * @return list<array<string,mixed>>
     */
    public static function active(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT * FROM promotions
                 WHERE is_active = 1
                   AND starts_at <= NOW()
                   AND (ends_at IS NULL OR ends_at > NOW())
                 ORDER BY created_at DESC'
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Toutes les promotions (admin), triées par date de création.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query('SELECT * FROM promotions ORDER BY created_at DESC');

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Crée ou met à jour une promotion (upsert par id).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data): string
    {
        $id    = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'promo_' . bin2hex(random_bytes(10));
        }

        $startsAt = trim((string) ($data['starts_at'] ?? ''));
        if ($startsAt === '') {
            $startsAt = date('Y-m-d H:i:s');
        } else {
            $ts = strtotime($startsAt);
            $startsAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        }

        $endsAt = trim((string) ($data['ends_at'] ?? ''));
        if ($endsAt === '') {
            $endsAt = null;
        } else {
            $ts = strtotime($endsAt);
            $endsAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        }

        $fields = [
            'id'          => $id,
            'title'       => trim((string) ($data['title'] ?? '')),
            'description' => ($data['description'] ?? '') !== '' ? $data['description'] : null,
            'product_key' => ($data['product_key'] ?? '') !== '' ? $data['product_key'] : null,
            'old_price'   => ($data['old_price'] ?? '') !== '' ? parseFrenchFloat((string) $data['old_price']) : null,
            'new_price'   => parseFrenchFloat((string) ($data['new_price'] ?? '0')),
            'image'       => ($data['image'] ?? '') !== '' ? $data['image'] : null,
            'badge'       => ($data['badge'] ?? '') !== '' ? $data['badge'] : null,
            'starts_at'   => $startsAt,
            'ends_at'     => $endsAt,
            'is_active'   => !empty($data['is_active']) ? 1 : 0,
        ];

        self::upsert($fields, $isNew);

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM promotions WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Helper d'upsert local.
     *
     * @param array<string,mixed> $fields
     */
    private static function upsert(array $fields, bool $isNew): void
    {
        if ($isNew) {
            $cols = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', array_keys($fields)));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO promotions (' . $cols . ') VALUES (' . $placeholders . ')'
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
            $values[] = $fields['id'];
            $stmt = static::pdo()->prepare('UPDATE promotions SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }
    }
}
