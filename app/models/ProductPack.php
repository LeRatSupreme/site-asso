<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Taille de pack d'achat par produit (table `product_packs`).
 *
 * Le réapprovisionnement se commande par packs (12, 24, parfois 32…) :
 * quand un pack est défini, « À commander » est arrondi au multiple
 * supérieur du pack (besoin brut 2 → commander 12). pack_size 0/absent =
 * commande à l'unité (aucun arrondi).
 */
final class ProductPack extends Model
{
    protected static string $table = 'product_packs';

    /**
     * Connexion PDO partagée (requêtes du module réappro).
     */
    public static function connection(): \PDO
    {
        return self::pdo();
    }

    /**
     * Tailles de pack indexées par clé produit normalisée
     * (minuscule, trimée — même convention que les autres lookups réappro).
     *
     * @return array<string,int>
     */
    public static function sizesMap(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT product_key, pack_size FROM product_packs')
                ->fetchAll();
        } catch (\Throwable) {
            // Table pas encore migrée : aucun pack défini.
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[strtolower(trim((string) $r['product_key']))] = max(0, (int) $r['pack_size']);
        }

        return $out;
    }

    /**
     * Définit la taille de pack d'un produit (upsert sur product_key).
     */
    public static function set(string $key, int $size, ?string $userId): void
    {
        self::pdo()->prepare(
            'INSERT INTO product_packs (product_key, pack_size, updated_by, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE pack_size = VALUES(pack_size), updated_by = VALUES(updated_by), updated_at = NOW()'
        )->execute([$key, $size, $userId]);
    }

    /**
     * Retire le pack d'un produit (retour à la commande à l'unité).
     */
    public static function remove(string $key): void
    {
        self::pdo()->prepare(
            'DELETE FROM product_packs WHERE product_key = ?'
        )->execute([$key]);
    }
}
