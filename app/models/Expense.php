<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Dépenses de l'association (table `expenses`).
 *
 * Charges hors coût d'achat matière : matériel, événements, frais divers.
 * Le résultat net = bénéfice cafétéria − dépenses.
 */
final class Expense extends Model
{
    protected static string $table = 'expenses';

    /**
     * Catégories de dépenses disponibles.
     *
     * - MATIERE   : matière première cafétéria
     * - MATERIEL  : matériel
     * - EVENEMENT : événements
     * - FRAIS     : frais bancaires & abonnements
     * - DIVERS    : divers
     */
    public const CATEGORIES = ['MATIERE', 'MATERIEL', 'EVENEMENT', 'FRAIS', 'DIVERS'];

    /**
     * Crée une dépense.
     *
     * @param array<string,mixed> $data spent_at (« YYYY-MM-DD »), category,
     *                                  label, amount_ttc, amount_ht, vat,
     *                                  supplier, notes, created_by
     *
     * @return string Identifiant créé ('' si données invalides).
     */
    public static function create(array $data): string
    {
        $spentAt = substr((string) ($data['spent_at'] ?? ''), 0, 10);
        $label = trim((string) ($data['label'] ?? ''));
        $amountTtc = (float) ($data['amount_ttc'] ?? 0);

        if ($spentAt === '' || $label === '' || $amountTtc <= 0) {
            return '';
        }

        // Catégorie normalisée en majuscules, repli sur DIVERS si inconnue.
        $category = strtoupper(trim((string) ($data['category'] ?? '')));
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'DIVERS';
        }

        $rawHt = $data['amount_ht'] ?? null;
        $amountHt = ($rawHt === null || $rawHt === '') ? null : (float) $rawHt;

        $rawVat = $data['vat'] ?? null;
        $vat = ($rawVat === null || $rawVat === '') ? null : (float) $rawVat;

        $supplier = ($data['supplier'] ?? '') !== '' ? (string) $data['supplier'] : null;
        $notes = ($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null;
        $createdBy = ($data['created_by'] ?? '') !== '' ? (string) $data['created_by'] : null;

        $id = 'expense_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO expenses
                (id, spent_at, category, label, amount_ttc, amount_ht, vat, supplier, notes, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
        )->execute([$id, $spentAt, $category, $label, $amountTtc, $amountHt, $vat, $supplier, $notes, $createdBy]);

        return $id;
    }

    /**
     * Supprime une dépense.
     */
    public static function delete(string $id): bool
    {
        $stmt = self::pdo()->prepare('DELETE FROM expenses WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Dépenses d'une période (année obligatoire, mois optionnel),
     * de la plus récente à la plus ancienne.
     *
     * @return list<array<string,mixed>>
     */
    public static function forPeriod(?int $year, ?int $month): array
    {
        $where = [];
        $args = [];
        if ($year !== null) {
            $where[] = 'YEAR(spent_at) = ?';
            $args[] = $year;
        }
        if ($month !== null) {
            $where[] = 'MONTH(spent_at) = ?';
            $args[] = $month;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT * FROM expenses ' . $whereSql . ' ORDER BY spent_at DESC, created_at DESC'
            );
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Totaux d'une période (année obligatoire, mois optionnel).
     *
     * @return array{ttc:float, ht:float, count:int}
     */
    public static function aggregates(?int $year, ?int $month): array
    {
        $where = [];
        $args = [];
        if ($year !== null) {
            $where[] = 'YEAR(spent_at) = ?';
            $args[] = $year;
        }
        if ($month !== null) {
            $where[] = 'MONTH(spent_at) = ?';
            $args[] = $month;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(amount_ttc), 0) AS ttc,
                        COALESCE(SUM(amount_ht), 0) AS ht,
                        COUNT(*) AS count
                 FROM expenses ' . $whereSql
            );
            $stmt->execute($args);
            $row = $stmt->fetch() ?: [];
        } catch (\Throwable) {
            return ['ttc' => 0.0, 'ht' => 0.0, 'count' => 0];
        }

        return [
            'ttc'   => (float) ($row['ttc'] ?? 0),
            'ht'    => (float) ($row['ht'] ?? 0),
            'count' => (int) ($row['count'] ?? 0),
        ];
    }

    /**
     * Dépenses groupées par catégorie, du plus lourd au plus léger.
     *
     * @return list<array{category:string, ttc:float, count:int}>
     */
    public static function byCategory(?int $year, ?int $month): array
    {
        $where = [];
        $args = [];
        if ($year !== null) {
            $where[] = 'YEAR(spent_at) = ?';
            $args[] = $year;
        }
        if ($month !== null) {
            $where[] = 'MONTH(spent_at) = ?';
            $args[] = $month;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT category,
                        COALESCE(SUM(amount_ttc), 0) AS ttc,
                        COUNT(*) AS count
                 FROM expenses ' . $whereSql . '
                 GROUP BY category
                 ORDER BY ttc DESC'
            );
            $stmt->execute($args);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'category' => (string) $r['category'],
                'ttc'      => (float) $r['ttc'],
                'count'    => (int) $r['count'],
            ];
        }

        return $out;
    }

    /**
     * Construit le WHERE « entre deux jours » partagé par les variantes
     * Between (bornes incluses, chaque condition seulement si non null).
     *
     * @return array{0:string,1:list<string>} [SQL WHERE avec préfixe, arguments]
     */
    private static function betweenWhere(?string $fromDay, ?string $toDay): array
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'spent_at >= ?';
            $args[] = $fromDay;
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'spent_at <= ?';
            $args[] = $toDay;
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $args];
    }

    /**
     * Dépenses d'une plage de jours (bornes incluses), de la plus récente
     * à la plus ancienne.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array<string,mixed>>
     */
    public static function between(?string $fromDay, ?string $toDay): array
    {
        [$whereSql, $args] = self::betweenWhere($fromDay, $toDay);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT * FROM expenses ' . $whereSql . ' ORDER BY spent_at DESC, created_at DESC'
            );
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Totaux d'une plage de jours (bornes incluses).
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return array{ttc:float, ht:float, count:int}
     */
    public static function aggregatesBetween(?string $fromDay, ?string $toDay): array
    {
        [$whereSql, $args] = self::betweenWhere($fromDay, $toDay);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(amount_ttc), 0) AS ttc,
                        COALESCE(SUM(amount_ht), 0) AS ht,
                        COUNT(*) AS count
                 FROM expenses ' . $whereSql
            );
            $stmt->execute($args);
            $row = $stmt->fetch() ?: [];
        } catch (\Throwable) {
            return ['ttc' => 0.0, 'ht' => 0.0, 'count' => 0];
        }

        return [
            'ttc'   => (float) ($row['ttc'] ?? 0),
            'ht'    => (float) ($row['ht'] ?? 0),
            'count' => (int) ($row['count'] ?? 0),
        ];
    }

    /**
     * Dépenses groupées par catégorie sur une plage de jours (bornes
     * incluses), du plus lourd au plus léger.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array{category:string, ttc:float, count:int}>
     */
    public static function byCategoryBetween(?string $fromDay, ?string $toDay): array
    {
        [$whereSql, $args] = self::betweenWhere($fromDay, $toDay);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT category,
                        COALESCE(SUM(amount_ttc), 0) AS ttc,
                        COUNT(*) AS count
                 FROM expenses ' . $whereSql . '
                 GROUP BY category
                 ORDER BY ttc DESC'
            );
            $stmt->execute($args);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'category' => (string) $r['category'],
                'ttc'      => (float) $r['ttc'],
                'count'    => (int) $r['count'],
            ];
        }

        return $out;
    }

    /**
     * Totaux TTC par mois pour une année donnée.
     *
     * @return list<array{m:int, ttc:float}>
     */
    public static function monthlyTotals(int $year): array
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT MONTH(spent_at) AS m,
                        COALESCE(SUM(amount_ttc), 0) AS ttc
                 FROM expenses
                 WHERE YEAR(spent_at) = ?
                 GROUP BY MONTH(spent_at)
                 ORDER BY m'
            );
            $stmt->execute([$year]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'm'   => (int) $r['m'],
                'ttc' => (float) $r['ttc'],
            ];
        }

        return $out;
    }

    /**
     * Mois disposant de dépenses (pour les sélecteurs de période).
     *
     * @return list<array{value:string, label:string}>
     */
    public static function monthsWithExpenses(): array
    {
        try {
            $rows = self::pdo()
                ->query(
                    'SELECT DISTINCT YEAR(spent_at) AS y, MONTH(spent_at) AS m
                     FROM expenses
                     ORDER BY y DESC, m DESC'
                )
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $months = [];
        foreach ($rows as $r) {
            $months[] = [
                'value' => sprintf('%04d-%02d', (int) $r['y'], (int) $r['m']),
                'label' => sprintf('%02d/%04d', (int) $r['m'], (int) $r['y']),
            ];
        }

        return $months;
    }
}
