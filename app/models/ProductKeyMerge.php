<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\StockPublic;

/**
 * Fusion de clés produits (doublons) : déplace TOUTES les données d'une
 * clé source vers une clé cible conservée, dans les 8 tables portant un
 * product_key — purchases, losses, sales, product_aliases,
 * inventory_counts, product_costs (lots de coûts ; PK `id` : simple
 * UPDATE), product_stocks et product_discontinued (PK `product_key` :
 * fusion dédiée).
 *
 * Exemple : « Pulco Citronnade » (saisi par erreur dans les achats) →
 * « pulco » (clé canonique des ventes SumUp).
 *
 * La normalisation (StockPublic::normalizeKey, partagée avec la carte
 * publique) sert à la prévention de doublons à la saisie et au datalist ;
 * la fusion opère elle-même sur les clés BRUTES saisies : ce sont elles
 * qui sont stockées en base, et consolider deux variantes de casse
 * (« Pulco » → « pulco ») est un cas d'usage légitime.
 *
 * Le cache de la carte publique est invalidé DANS merge() après commit :
 * aucun appelant ne peut l'oublier (le théorique a changé de clé).
 */
final class ProductKeyMerge extends Model
{
    protected static string $table = 'product_stocks';

    /**
     * Tables à PK `id` (sales, purchases, losses, product_aliases,
     * inventory_counts, product_costs) : un UPDATE par table suffit —
     * pas de conflit possible (product_aliases : PK id, UNIQUE sur
     * raw_description ; product_costs : plusieurs lots par produit,
     * tous rebasculés sur la cible).
     *
     * @var list<string>
     */
    private const SIMPLE_TABLES = ['purchases', 'losses', 'sales', 'product_aliases', 'inventory_counts', 'product_costs'];

    /**
     * Fusionne la clé source dans la clé cible, transactionnellement :
     * tout est déplacé ou rien ne l'est.
     *
     * @param string     $source Clé doublon à absorber (supprimée partout).
     * @param string     $target Clé canonique conservée.
     * @param string|null $userId Auteur de la fusion (audit du drapeau).
     *
     * @return array<string,int> Nombre de lignes déplacées par table :
     *                           purchases, losses, sales, aliases, counts,
     *                           costs, stocks, discontinued.
     *
     * @throws \InvalidArgumentException Clé source vide ou identique à la cible.
     * @throws \Throwable                Erreur SQL (transaction annulée).
     */
    public static function merge(string $source, string $target, ?string $userId): array
    {
        $source = trim($source);
        $target = trim($target);

        if ($source === '') {
            throw new \InvalidArgumentException('La clé source est vide.');
        }
        if ($source === $target) {
            throw new \InvalidArgumentException('La clé source et la clé cible sont identiques.');
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $moved = [];

            // 1-6. Tables à PK id : déplacement simple des lignes.
            foreach (self::SIMPLE_TABLES as $table) {
                $stmt = $pdo->prepare(
                    'UPDATE `' . $table . '` SET product_key = ? WHERE product_key = ?'
                );
                $stmt->execute([$target, $source]);
                $moved[] = (int) $stmt->rowCount();
            }

            // 7. product_stocks (PK product_key) : fusion ADDITIVE du stock
            //    de référence (source 5 + cible 7 → cible 12), puis
            //    suppression de la ligne source.
            $stmt = $pdo->prepare(
                'INSERT INTO product_stocks (product_key, stock)
                 SELECT ?, stock FROM product_stocks WHERE product_key = ?
                 ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock), updated_at = NOW()'
            );
            $stmt->execute([$target, $source]);
            $stocks = (int) $stmt->rowCount();
            $pdo->prepare('DELETE FROM product_stocks WHERE product_key = ?')->execute([$source]);

            // 8. product_discontinued (PK product_key) : le drapeau « plus
            //    en vente » suit la cible (premier auteur conservé), puis
            //    suppression de la ligne source.
            $stmt = $pdo->prepare(
                'INSERT INTO product_discontinued (product_key, updated_by)
                 SELECT ?, COALESCE(updated_by, ?) FROM product_discontinued WHERE product_key = ?
                 ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by)'
            );
            $stmt->execute([$target, $userId, $source]);
            $discontinued = (int) $stmt->rowCount();
            $pdo->prepare('DELETE FROM product_discontinued WHERE product_key = ?')->execute([$source]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        // La carte publique suit : le théorique a changé de clé.
        StockPublic::invalidate();

        return [
            'purchases'    => $moved[0],
            'losses'       => $moved[1],
            'sales'        => $moved[2],
            'aliases'      => $moved[3],
            'counts'       => $moved[4],
            'costs'        => $moved[5],
            'stocks'       => $stocks,
            'discontinued' => $discontinued,
        ];
    }

    /**
     * Toutes les clés produits existantes : UNION (distinct) du
     * product_key des 7 tables, trié, sans chaîne vide. Sert au datalist
     * de fusion et à la prévention de doublons à la saisie (achats,
     * pertes). Silencieux sans base (retourne []).
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        $sql = 'SELECT product_key FROM purchases
                UNION
                SELECT product_key FROM losses
                UNION
                SELECT product_key FROM sales
                UNION
                SELECT product_key FROM product_aliases
                UNION
                SELECT product_key FROM inventory_counts
                UNION
                SELECT product_key FROM product_stocks
                UNION
                SELECT product_key FROM product_discontinued
                ORDER BY product_key';

        try {
            /** @var list<array-key,mixed> $rows */
            $rows = self::pdo()->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $k) {
            $key = trim((string) $k);
            if ($key !== '') {
                $out[] = $key;
            }
        }

        return $out;
    }
}
