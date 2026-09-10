<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Achats réels de réapprovisionnement (table `purchases`).
 *
 * Traçe ce qui a été réellement commandé/reçu (à distinguer du
 * « à commander » calculé par la page Réappro) ; sert au calcul
 * du stock théorique.
 */
final class Purchase extends Model
{
    protected static string $table = 'purchases';

    /**
     * Crée un achat réel.
     *
     * Le total TTC est calculé : quantité × coût unitaire.
     *
     * @param array<string,mixed> $data purchased_at (« YYYY-MM-DD »),
     *                                  product_key, quantity, unit_cost,
     *                                  supplier, notes, created_by
     *
     * @return string Identifiant créé ('' si données invalides).
     */
    public static function create(array $data): string
    {
        $purchasedAt = substr((string) ($data['purchased_at'] ?? ''), 0, 10);
        $productKey = trim((string) ($data['product_key'] ?? ''));
        $quantity = (int) ($data['quantity'] ?? 0);
        $unitCost = (float) ($data['unit_cost'] ?? 0);

        if ($purchasedAt === '' || $productKey === '' || $quantity < 1) {
            return '';
        }

        $totalTtc = $quantity * $unitCost;
        $supplier = ($data['supplier'] ?? '') !== '' ? (string) $data['supplier'] : null;
        $notes = ($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null;
        $createdBy = ($data['created_by'] ?? '') !== '' ? (string) $data['created_by'] : null;

        $id = 'purchase_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO purchases
                (id, purchased_at, supplier, product_key, quantity, unit_cost, total_ttc, notes, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())'
        )->execute([$id, $purchasedAt, $supplier, $productKey, $quantity, $unitCost, $totalTtc, $notes, $createdBy]);

        return $id;
    }

    /**
     * Supprime un achat.
     */
    public static function delete(string $id): bool
    {
        $stmt = self::pdo()->prepare('DELETE FROM purchases WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Derniers achats, du plus récent au plus ancien.
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 100): array
    {
        $limit = max(1, (int) $limit);

        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query('SELECT * FROM purchases ORDER BY purchased_at DESC, created_at DESC LIMIT ' . $limit)
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Achats d'une plage de jours (bornes incluses), du plus récent au
     * plus ancien.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array<string,mixed>>
     */
    public static function between(?string $fromDay, ?string $toDay, int $limit = 200): array
    {
        $limit = max(1, (int) $limit);

        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'purchased_at >= ?';
            $args[] = $fromDay;
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'purchased_at <= ?';
            $args[] = $toDay;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT * FROM purchases ' . $whereSql . ' ORDER BY purchased_at DESC, created_at DESC LIMIT ' . $limit
            );
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Total TTC des achats sur une plage de jours (bornes incluses).
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     */
    public static function totalBetween(?string $fromDay, ?string $toDay): float
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'purchased_at >= ?';
            $args[] = $fromDay;
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'purchased_at <= ?';
            $args[] = $toDay;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(total_ttc), 0) FROM purchases ' . $whereSql
            );
            $stmt->execute($args);

            return (float) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Quantité totale achetée pour un produit depuis un jour donné (inclus).
     *
     * @param string $day Jour « YYYY-MM-DD » (borne inférieure incluse).
     */
    public static function qtySince(string $productKey, string $day): int
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(quantity), 0)
                 FROM purchases
                 WHERE product_key = ? AND purchased_at >= ?'
            );
            $stmt->execute([$productKey, $day]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Total TTC des achats sur une plage de jours (bornes incluses).
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     */
    public static function totalForPeriod(?string $fromDay, ?string $toDay): float
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'purchased_at >= ?';
            $args[] = $fromDay;
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'purchased_at <= ?';
            $args[] = $toDay;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(total_ttc), 0) FROM purchases ' . $whereSql
            );
            $stmt->execute($args);

            return (float) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
