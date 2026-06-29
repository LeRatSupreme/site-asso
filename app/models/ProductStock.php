<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Stocks saisis sur la page Réappro (table `product_stocks`).
 *
 * Découple le stock du tableau des produits cafétéria : n'importe quel
 * produit (même présent uniquement dans les ventes SumUp) peut avoir un
 * stock saisi ici, clé par product_key canonique.
 */
final class ProductStock extends Model
{
    protected static string $table = 'product_stocks';

    /**
     * Tous les stocks saisis, indexés par product_key.
     *
     * @return array<string,int>
     */
    public static function allMap(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT product_key, stock FROM product_stocks')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['product_key']] = (int) $r['stock'];
        }

        return $map;
    }

    /**
     * Stock saisi pour un product_key donné (null si non défini).
     */
    public static function get(string $productKey): ?int
    {
        try {
            $stmt = self::pdo()->prepare('SELECT stock FROM product_stocks WHERE product_key = ?');
            $stmt->execute([$productKey]);
            $row = $stmt->fetch();

            return $row === false ? null : (int) $row['stock'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Enregistre (upsert) le stock d'un product_key.
     */
    public static function set(string $productKey, int $stock): void
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO product_stocks (product_key, stock, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE stock = VALUES(stock), updated_at = NOW()'
        );
        $stmt->execute([$productKey, max(0, $stock)]);
    }
}
