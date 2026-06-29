<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des produits cafétéria.
 */
final class Product extends Model
{
    protected static string $table = 'products';

    /**
     * Produits actifs et disponibles, triés par catégorie puis ordre.
     *
     * @return list<array<string,mixed>>
     */
    public static function available(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT p.*, c.name AS category_name
                 FROM products p
                 LEFT JOIN product_categories c ON c.id = p.category_id
                 WHERE p.is_active = 1 AND p.is_available = 1
                 ORDER BY c.`order` ASC, p.`order` ASC, p.name ASC'
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Recherche un produit par son identifiant.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $id): ?array
    {
        try {
            $stmt = static::pdo()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Décrémente atomiquement le stock d'un produit.
     *
     * La condition `stock >= quantité` empêche tout stock négatif ; la requête
     * ne modifie rien si le stock est insuffisant.
     *
     * @return bool Vrai si le stock a bien été décrémenté.
     */
    public static function decrementStock(string $productId, int $quantity): bool
    {
        $stmt = static::pdo()->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([$quantity, $productId, $quantity]);

        return $stmt->rowCount() === 1;
    }

    // -----------------------------------------------------------------
    //  Méthodes d'administration.
    // -----------------------------------------------------------------

    /**
     * Tous les produits (admin), triés par catégorie puis ordre.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT p.*, c.name AS category_name
                 FROM products p
                 LEFT JOIN product_categories c ON c.id = p.category_id
                 ORDER BY c.`order` ASC, p.`order` ASC, p.name ASC'
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Crée ou met à jour un produit (upsert par id).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'prod_' . bin2hex(random_bytes(10));
        }

        $fields = [
            'id'           => $id,
            'name'         => $data['name'] ?? '',
            'description'  => $data['description'] ?? null,
            'price'        => $data['price'] ?? 0,
            'image'        => $data['image'] ?? null,
            'category_id'  => ($data['category_id'] ?? '') !== '' ? $data['category_id'] : null,
            'stock'        => (int) ($data['stock'] ?? 0),
            'is_available' => !empty($data['is_available']) ? 1 : 0,
            'is_active'    => isset($data['is_active']) ? ((int) $data['is_active']) : 1,
            'order'        => (int) ($data['order'] ?? 0),
        ];

        self::upsert('products', $fields, $isNew);

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Helper d'upsert générique (utilisé par les modèles d'admin).
     *
     * @param array<string,mixed> $fields
     */
    protected static function upsert(string $table, array $fields, bool $isNew): void
    {
        if ($isNew) {
            $cols = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', array_keys($fields)));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO ' . $table . ' (' . $cols . ') VALUES (' . $placeholders . ')'
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
            $stmt = static::pdo()->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }
    }
}
