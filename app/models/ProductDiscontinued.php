<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Produits marqués « plus en vente » (table `product_discontinued`).
 *
 * Produits saisonniers ou discontinués (ex. Redbull Winter) : exclus du
 * comptage à l'aveugle, de la page Inventaire et du réapprovisionnement
 * afin de ne plus encombrer les saisies. L'historique des ventes reste
 * inchangé.
 */
final class ProductDiscontinued extends Model
{
    protected static string $table = 'product_discontinued';

    /**
     * Toutes les clés produits marquées « plus en vente ».
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT product_key FROM product_discontinued')
                ->fetchAll();
        } catch (\Throwable) {
            // Table pas encore migrée / base indisponible : aucun produit masqué.
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = (string) $r['product_key'];
        }

        return $out;
    }

    /**
     * Le produit est-il marqué « plus en vente » ?
     */
    public static function isDiscontinued(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Marque un produit « plus en vente » (upsert sur product_key).
     */
    public static function mark(string $key, ?string $userId): void
    {
        self::pdo()->prepare(
            'INSERT INTO product_discontinued (product_key, updated_by, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by), updated_at = NOW()'
        )->execute([$key, $userId]);
    }

    /**
     * Remet un produit en vente (retire le drapeau).
     */
    public static function resume(string $key): void
    {
        self::pdo()->prepare(
            'DELETE FROM product_discontinued WHERE product_key = ?'
        )->execute([$key]);
    }
}
