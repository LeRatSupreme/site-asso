<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des catégories de produits cafétéria.
 */
final class ProductCategory extends Model
{
    protected static string $table = 'product_categories';

    /**
     * Catégories actives, triées par ordre d'affichage.
     *
     * @return list<array<string,mixed>>
     */
    public static function active(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT * FROM product_categories WHERE is_active = 1 ORDER BY `order` ASC, name ASC'
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Toutes les catégories (admin), triées par ordre.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query('SELECT * FROM product_categories ORDER BY `order` ASC, name ASC');

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Crée ou met à jour une catégorie (upsert par id).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'cat_' . bin2hex(random_bytes(10));
        }

        $fields = [
            'id'          => $id,
            'name'        => $data['name'] ?? '',
            'description' => $data['description'] ?? null,
            'image'       => $data['image'] ?? null,
            'order'       => (int) ($data['order'] ?? 0),
            'is_active'   => isset($data['is_active']) ? ((int) $data['is_active']) : 1,
        ];

        if ($isNew) {
            $cols = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', array_keys($fields)));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO product_categories (' . $cols . ') VALUES (' . $placeholders . ')'
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
            $stmt = static::pdo()->prepare('UPDATE product_categories SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM product_categories WHERE id = ?');
        $stmt->execute([$id]);
    }
}
