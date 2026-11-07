<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Produits marqués « stock infini » (table `product_infinite`).
 *
 * Produits que l'association ne réapprovisionne jamais (fournis
 * gracieusement, stock non géré…) : sur la page Réapprovisionnement,
 * l'autonomie affiche « ∞ » et la quantité « À commander » reste à 0,
 * même quand le produit n'a jamais été compté en inventaire (état
 * « À compter »). Le drapeau est réversible en un clic.
 */
final class ProductInfinite extends Model
{
    protected static string $table = 'product_infinite';

    /**
     * Toutes les clés produits marquées « stock infini ».
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT product_key FROM product_infinite')
                ->fetchAll();
        } catch (\Throwable) {
            // Table pas encore migrée / base indisponible : aucun produit infini.
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = (string) $r['product_key'];
        }

        return $out;
    }

    /**
     * Le produit est-il marqué « stock infini » ?
     */
    public static function isInfinite(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Marque le stock du produit infini (upsert sur product_key).
     */
    public static function mark(string $key, ?string $userId): void
    {
        self::pdo()->prepare(
            'INSERT INTO product_infinite (product_key, updated_by, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by), updated_at = NOW()'
        )->execute([$key, $userId]);
    }

    /**
     * Retire le marquage « stock infini ».
     */
    public static function unmark(string $key): void
    {
        self::pdo()->prepare(
            'DELETE FROM product_infinite WHERE product_key = ?'
        )->execute([$key]);
    }

    /**
     * Bascule le drapeau ; renvoie true s'il vient d'être posé.
     */
    public static function toggle(string $key, ?string $userId = null): bool
    {
        if (self::isInfinite($key)) {
            self::unmark($key);

            return false;
        }

        self::mark($key, $userId);

        return true;
    }
}
