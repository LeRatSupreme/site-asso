<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Comptages physiques d'inventaire (table `inventory_counts`).
 *
 * Stock théorique = dernier comptage + achats − ventes depuis la date
 * du comptage. L'écart (gap = compté − théorique) révèle pertes,
 * casses, offerts ou erreurs de saisie.
 */
final class InventoryCount extends Model
{
    protected static string $table = 'inventory_counts';

    /**
     * Enregistre un comptage physique.
     *
     * 1. calcule le stock théorique actuel ;
     * 2. calcule l'écart (gap = compté − théorique) ;
     * 3. insère la ligne de comptage ;
     * 4. met à jour le stock de référence du produit.
     *
     * @return string Identifiant du comptage créé.
     */
    public static function record(string $productKey, int $countedQty, ?string $note, ?string $userId): string
    {
        // Théorique actuel (0 si le produit n'a jamais été compté).
        $theoretical = self::theoreticalStock($productKey) ?? 0;
        $gap = $countedQty - $theoretical;

        $id = 'inv_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO inventory_counts
                (id, counted_at, product_key, counted_qty, theoretical_qty, gap, note, created_by, created_at)
             VALUES (?,?,NOW(),?,?,?,?,?,NOW())'
        )->execute([$id, $productKey, $countedQty, $theoretical, $gap, $note, $userId]);

        // Le comptage devient la nouvelle référence de stock du produit.
        ProductStock::set($productKey, max(0, $countedQty));

        return $id;
    }

    /**
     * Date du dernier comptage d'un produit (null si jamais compté).
     */
    public static function lastCountAt(string $productKey): ?string
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT MAX(counted_at) FROM inventory_counts WHERE product_key = ?'
            );
            $stmt->execute([$productKey]);
            $at = $stmt->fetchColumn();
        } catch (\Throwable) {
            return null;
        }

        return $at ? (string) $at : null;
    }

    /**
     * Dernier comptage connu par produit.
     *
     * @return array<string,array{at:string, qty:int, gap:int}> Clé = product_key.
     */
    public static function lastCountsMap(): array
    {
        $sql = 'SELECT i.product_key, i.counted_at, i.counted_qty, i.gap
                FROM inventory_counts i
                JOIN (
                    SELECT product_key, MAX(counted_at) AS m
                    FROM inventory_counts
                    GROUP BY product_key
                ) last ON i.product_key = last.product_key AND i.counted_at = last.m';

        try {
            $rows = self::pdo()->query($sql)->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['product_key']] = [
                'at'  => (string) $r['counted_at'],
                'qty' => (int) $r['counted_qty'],
                'gap' => (int) $r['gap'],
            ];
        }

        return $map;
    }

    /**
     * Stock théorique actuel d'un produit (null si jamais compté).
     *
     * Théorique = quantité du dernier comptage + achats − ventes
     * depuis la date de ce comptage.
     */
    public static function theoreticalStock(string $productKey): ?int
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT counted_at, counted_qty
                 FROM inventory_counts
                 WHERE product_key = ?
                 ORDER BY counted_at DESC
                 LIMIT 1'
            );
            $stmt->execute([$productKey]);
            $last = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        if ($last === false) {
            return null;
        }

        return self::theoreticalFromLast($productKey, (string) $last['counted_at'], (int) $last['counted_qty']);
    }

    /**
     * Stocks théoriques actuels de tous les produits déjà comptés.
     *
     * @return array<string,int> Clé = product_key, valeur = stock théorique.
     */
    public static function theoreticalStocksMap(): array
    {
        $map = [];
        foreach (self::lastCountsMap() as $productKey => $last) {
            $map[$productKey] = self::theoreticalFromLast($productKey, $last['at'], $last['qty']);
        }

        return $map;
    }

    /**
     * Tous les comptages, du plus récent au plus ancien.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $limit = 100): array
    {
        $limit = max(1, (int) $limit);

        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query('SELECT * FROM inventory_counts ORDER BY counted_at DESC LIMIT ' . $limit)
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Comptages présentant un écart non nul sur les N derniers jours,
     * de l'écart le plus grand au plus petit (en valeur absolue).
     *
     * @return list<array<string,mixed>>
     */
    public static function recentGaps(int $days = 30): array
    {
        $days = max(1, (int) $days);

        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query(
                    'SELECT * FROM inventory_counts
                     WHERE counted_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)
                       AND gap <> 0
                     ORDER BY ABS(gap) DESC'
                )
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Calcule le stock théorique depuis un dernier comptage connu :
     * quantité comptée + achats − ventes depuis la date du comptage.
     */
    private static function theoreticalFromLast(string $productKey, string $at, int $qty): int
    {
        $day = substr($at, 0, 10);

        return $qty
            + Purchase::qtySince($productKey, $day)
            - Sale::soldQtySince($productKey, $day);
    }
}
