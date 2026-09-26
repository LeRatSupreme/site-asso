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
     * Sémantique : le montant total saisi fait foi. total_ht est le
     * montant payé pour la ligne (HT si un taux est fourni, déjà TTC si
     * vat_rate vaut null — comportement historique) ; le coût unitaire
     * est une valeur dérivée : unit_cost = total_ht / quantité, arrondi
     * à 3 décimales — c'est lui qui alimente coûts de revient et
     * bénéfices. total_ttc = total_ht × (1 + taux/100) quand un taux
     * est fourni.
     *
     * no_stock = achat « hors stock » : la ligne reste dans la compta
     * (dépense réelle, lot de coût possible) mais n'alimente ni le stock
     * de référence ni le stock théorique (conso bureau, fournitures,
     * essais…).
     *
     * @param array<string,mixed> $data purchased_at (« YYYY-MM-DD »),
     *                                  product_key, quantity, total_ht
     *                                  (montant total), vat_rate (?float,
     *                                  null = déjà TTC), supplier, notes,
     *                                  created_by, no_stock (true = achat
     *                                  « hors stock », hors inventaire)
     *
     * @return string Identifiant créé ('' si données invalides).
     */
    public static function create(array $data): string
    {
        $purchasedAt = substr((string) ($data['purchased_at'] ?? ''), 0, 10);
        $productKey = trim((string) ($data['product_key'] ?? ''));
        $quantity = (int) ($data['quantity'] ?? 0);
        // Le montant saisi fait foi : 3 décimales, stocké tel quel
        // (0,465 € doit rester 0,465 € et non être arrondi à 0,47 €).
        $totalHt = round((float) ($data['total_ht'] ?? 0), 3);
        $vatRate = $data['vat_rate'] ?? null;
        $vatRate = $vatRate === null ? null : (float) $vatRate;
        // no_stock = achat « hors stock » : comptabilisé mais sans effet
        // sur le stock (ni référence, ni théorique).
        $noStock = !empty($data['no_stock']);

        if ($purchasedAt === '' || $productKey === '' || $quantity < 1 || $totalHt <= 0.0) {
            return '';
        }

        // Coût unitaire dérivé du montant total, 3 décimales :
        // 18,60 € les 120 → 0,155 €/unité.
        $unitCost = round($totalHt / $quantity, 3);

        if ($vatRate === null) {
            $totalTtc = $totalHt;
        } else {
            // 3 décimales : 25,152 € HT + TVA 5,5 % = 26,535 € (et non 26,54 €).
            $totalTtc = round($totalHt * (1 + $vatRate / 100), 3);
        }

        $supplier = ($data['supplier'] ?? '') !== '' ? (string) $data['supplier'] : null;
        $notes = ($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null;
        $createdBy = ($data['created_by'] ?? '') !== '' ? (string) $data['created_by'] : null;

        $id = 'purchase_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO purchases
                (id, purchased_at, supplier, product_key, quantity, unit_cost, vat_rate, total_ttc, total_ht, no_stock, notes, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        )->execute([$id, $purchasedAt, $supplier, $productKey, $quantity, $unitCost, $vatRate, $totalTtc, $totalHt, $noStock ? 1 : 0, $notes, $createdBy]);

        // L'achat entre physiquement en stock : la référence de stock
        // (utilisée par le réappro) suit le stock théorique de l'inventaire.
        // Un achat « hors stock » (conso bureau, essais…) n'y touche pas.
        if (!$noStock) {
            ProductStock::adjust($productKey, $quantity);
        }

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
     * Sommes HT / TVA / TTC des achats sur une plage de jours (bornes
     * incluses), en une seule requête.
     *
     * Les lignes historiques (total_ht NULL, saisies avant la TVA) sont
     * considérées comme déjà TTC : COALESCE(total_ht, total_ttc).
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return array{ht:float, ttc:float, vat:float}
     */
    public static function sumsBetween(?string $fromDay, ?string $toDay): array
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
                'SELECT COALESCE(SUM(COALESCE(total_ht, total_ttc)), 0) AS ht,
                        COALESCE(SUM(total_ttc), 0) AS ttc
                 FROM purchases ' . $whereSql
            );
            $stmt->execute($args);
            $row = $stmt->fetch();

            $ht = (float) ($row['ht'] ?? 0);
            $ttc = (float) ($row['ttc'] ?? 0);

            return ['ht' => $ht, 'ttc' => $ttc, 'vat' => round($ttc - $ht, 3)];
        } catch (\Throwable) {
            return ['ht' => 0.0, 'ttc' => 0.0, 'vat' => 0.0];
        }
    }

    /**
     * Quantité totale achetée pour un produit depuis un jour donné (inclus).
     *
     * Les achats « hors stock » (no_stock = 1) sont ignorés : ils ne
     * nourrissent pas le stock théorique.
     *
     * @param string $day Jour « YYYY-MM-DD » (borne inférieure incluse).
     */
    public static function qtySince(string $productKey, string $day): int
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(quantity), 0)
                 FROM purchases
                 WHERE product_key = ? AND purchased_at >= ? AND no_stock = 0'
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

    /**
     * Total TTC des achats par mois pour une année donnée.
     *
     * Utilisé par les budgets : l'enveloppe « Matière » se nourrit des
     * achats de stock réellement enregistrés.
     *
     * @return list<array{m:int, ttc:float}>
     */
    public static function monthlyTotals(int $year): array
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT MONTH(purchased_at) AS m, COALESCE(SUM(total_ttc), 0) AS ttc
                 FROM purchases
                 WHERE YEAR(purchased_at) = ?
                 GROUP BY MONTH(purchased_at)
                 ORDER BY m'
            );
            $stmt->execute([$year]);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
