<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Comptages physiques d'inventaire (table `inventory_counts`).
 *
 * Stock théorique = dernier comptage + achats − ventes − pertes depuis la
 * date du comptage. L'écart (gap = compté − théorique) révèle pertes,
 * casses, offerts ou erreurs de saisie non encore journalisées.
 *
 * Un achat ou une perte établit une base 0 : le stock théorique existe
 * même sans comptage préalable (0 + Σ achats − Σ ventes − Σ pertes
 * depuis l'origine).
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
             VALUES (?,NOW(),?,?,?,?,?,?,NOW())'
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
     * Stock théorique actuel d'un produit (null si aucune donnée).
     *
     * Théorique = quantité du dernier comptage + achats − ventes − pertes
     * depuis la date de ce comptage. Un achat ou une perte établit une
     * base 0 : le stock théorique existe même sans comptage préalable
     * (0 + mouvements depuis l'origine). Null uniquement si le produit
     * n'a ni comptage, ni achat, ni perte.
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
            // Jamais compté : base 0 posée par un achat ou une perte.
            return self::hasMovement($productKey)
                ? self::theoreticalFromLast($productKey, null, 0)
                : null;
        }

        return self::theoreticalFromLast($productKey, (string) $last['counted_at'], (int) $last['counted_qty']);
    }

    /**
     * Stocks théoriques actuels.
     *
     * Couvre les produits déjà comptés (dernier comptage + mouvements
     * depuis cette date) ET les produits jamais comptés mais établis par
     * un achat ou une perte : un achat ou une perte établit une base 0 —
     * le stock théorique existe même sans comptage préalable (0 + Σ
     * achats − Σ ventes − Σ pertes depuis l'origine).
     *
     * @return array<string,int> Clé = product_key, valeur = stock théorique.
     */
    public static function theoreticalStocksMap(): array
    {
        $map = [];
        foreach (self::lastCountsMap() as $productKey => $last) {
            $map[$productKey] = self::theoreticalFromLast($productKey, $last['at'], $last['qty']);
        }

        // Clés présentes en achats/pertes sans aucun comptage : base 0,
        // théorique = 0 + mouvements depuis l'origine.
        foreach (self::movedWithoutCount(array_flip(array_keys($map))) as $productKey) {
            $map[$productKey] = self::theoreticalFromLast($productKey, null, 0);
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
     * Calcule le stock théorique depuis un point de référence connu :
     * quantité de référence + achats − ventes − pertes depuis cette
     * référence.
     *
     * Les ventes (DATETIME) sont comparées à l'heure exacte du comptage :
     * une vente antérieure au comptage est déjà reflétée dans la quantité
     * comptée. Les achats et pertes (colonnes DATE) sont comparés au jour
     * du comptage.
     *
     * Date de référence null = produit jamais compté mais établi par un
     * achat ou une perte : la quantité de référence vaut 0 et tous les
     * mouvements comptent depuis l'origine (bornes 1970, jamais atteintes
     * par les données réelles — donc tout l'historique est pris).
     */
    private static function theoreticalFromLast(string $productKey, ?string $at, int $qty): int
    {
        $day = $at !== null ? substr($at, 0, 10) : '1970-01-01';
        $moment = $at ?? '1970-01-01 00:00:00';

        return $qty
            + Purchase::qtySince($productKey, $day)
            - Sale::soldQtySince($productKey, $moment)
            - Loss::qtySince($productKey, $day);
    }

    /**
     * Clés présentes en achats ou pertes sans aucun comptage : leur base 0
     * est établie par le mouvement, le stock théorique doit exister.
     *
     * @param array<string,int> $countedKeys Clés déjà comptées (flip).
     *
     * @return list<string>
     */
    private static function movedWithoutCount(array $countedKeys): array
    {
        try {
            /** @var list<array-key,mixed> $rows */
            $rows = self::pdo()
                ->query(
                    'SELECT DISTINCT product_key FROM purchases
                     UNION
                     SELECT DISTINCT product_key FROM losses'
                )
                ->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $k) {
            $key = (string) $k;
            if ($key !== '' && !isset($countedKeys[$key])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Le produit a-t-il au moins un achat ou une perte (base 0 posée) ?
     */
    private static function hasMovement(string $productKey): bool
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT EXISTS(SELECT 1 FROM purchases WHERE product_key = ?)
                     OR EXISTS(SELECT 1 FROM losses WHERE product_key = ?)'
            );
            $stmt->execute([$productKey, $productKey]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }
}
