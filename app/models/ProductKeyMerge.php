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
            //    Lecture en deux temps : un INSERT ... SELECT depuis la
            //    table product_stocks elle-même rend « stock » ambigu
            //    dans ON DUPLICATE KEY UPDATE (erreur 1052 MySQL) — on
            //    lit donc le stock source, puis un INSERT ... VALUES sans
            //    SELECT l'ajoute à la cible.
            $stmt = $pdo->prepare('SELECT stock FROM product_stocks WHERE product_key = ?');
            $stmt->execute([$source]);
            $sourceStock = $stmt->fetchColumn();
            $stocks = 0;
            if ($sourceStock !== false) {
                $stmt = $pdo->prepare(
                    'INSERT INTO product_stocks (product_key, stock) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock), updated_at = NOW()'
                );
                $stmt->execute([$target, (int) $sourceStock]);
                $stocks = 1;
            }
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

    /**
     * Statistiques par clé produit (nombre de lignes par table) : sert à
     * la prévisualisation de la fusion dans la vue Inventaire — l'utilisateur
     * voit ce que déplacerait chaque fusion AVANT de la valider.
     *
     * Clé absente du résultat = clé inconnue de toutes les tables.
     *
     * @return array<string, array{sales:int, purchases:int, losses:int, counts:int, costs:int, aliases:int, stock:?int, discontinued:bool}>
     */
    public static function keyStats(): array
    {
        $queries = [
            'sales'     => 'SELECT product_key, COUNT(*) FROM sales GROUP BY product_key',
            'purchases' => 'SELECT product_key, COUNT(*) FROM purchases GROUP BY product_key',
            'losses'    => 'SELECT product_key, COUNT(*) FROM losses GROUP BY product_key',
            'counts'    => 'SELECT product_key, COUNT(*) FROM inventory_counts GROUP BY product_key',
            'costs'     => 'SELECT product_key, COUNT(*) FROM product_costs GROUP BY product_key',
            'aliases'   => 'SELECT product_key, COUNT(*) FROM product_aliases GROUP BY product_key',
        ];

        $stats = [];
        try {
            foreach ($queries as $field => $sql) {
                /** @var list<list<string|int>> $rows */
                $rows = self::pdo()->query($sql)->fetchAll(\PDO::FETCH_NUM);
                foreach ($rows as $row) {
                    $key = trim((string) $row[0]);
                    if ($key === '') {
                        continue;
                    }
                    $stats[$key] ??= self::emptyStat();
                    $stats[$key][$field] = (int) $row[1];
                }
            }
        } catch (\Throwable) {
            // Silencieux : la carte de fusion doit s'afficher même sans base.
        }

        foreach (ProductStock::allMap() as $key => $stock) {
            $stats[$key] ??= self::emptyStat();
            $stats[$key]['stock'] = $stock;
        }
        foreach (ProductDiscontinued::keys() as $key) {
            $stats[$key] ??= self::emptyStat();
            $stats[$key]['discontinued'] = true;
        }

        ksort($stats, SORT_STRING);

        return $stats;
    }

    /**
     * Compteurs à zéro pour une clé nouvellement rencontrée.
     *
     * @return array{sales:int, purchases:int, losses:int, counts:int, costs:int, aliases:int, stock:?int, discontinued:bool}
     */
    private static function emptyStat(): array
    {
        return [
            'sales'        => 0,
            'purchases'    => 0,
            'losses'       => 0,
            'counts'       => 0,
            'costs'        => 0,
            'aliases'      => 0,
            'stock'        => null,
            'discontinued' => false,
        ];
    }
}
