<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Agrégats pour le dashboard Analytics Pro (Fonctionnalité 14, refonte).
 *
 * Toutes les méthodes acceptent une fenêtre temporelle [from, to] (dates
 * incluses au format YYYY-MM-DD) et des filtres optionnels (catégorie,
 * moyen de paiement). Les requêtes lisent des données existantes (sales,
 * event_registrations, users, poll_votes) sans jamais les modifier.
 *
 * Toutes les méthodes utilisent PDO préparé, try/catch et renvoient des
 * tableaux simples (jamais null).
 */
final class Analytics extends Model
{
    protected static string $table = 'sales';

    /**
     * Coût unitaire applicable à une vente (lot valide à la date de la vente).
     * Renvoie 0 si aucun lot n'est défini pour le produit.
     *
     * Reproduction fidèle du sous-requête de Sale::COST_SUBQUERY.
     */
    private const COST_SUBQUERY = '
        SELECT pc.cost_price
        FROM product_costs pc
        WHERE pc.product_key = COALESCE(sales.product_key, sales.description)
        ORDER BY
            CASE
                WHEN pc.valid_from <= DATE(sales.sold_at)
                     AND (pc.valid_to IS NULL OR DATE(sales.sold_at) < pc.valid_to)
                THEN 0
                ELSE 1
            END,
            pc.valid_from DESC
        LIMIT 1';

    /**
     * Construit la clause WHERE de fenêtre + filtres optionnels.
     *
     * @param list<string> $args  Tableau de paramètres à remplir (par référence).
     * @return array{sql:string, args:list<string>}
     */
    private static function buildWhere(
        string $from,
        string $to,
        string $category = '',
        string $payment = ''
    ): array {
        $where = ['DATE(sold_at) >= ?', 'DATE(sold_at) <= ?'];
        $args = [$from, $to];

        if ($category !== '' && $category !== 'all') {
            $where[] = 'COALESCE(NULLIF(category, ""), "Non classé") = ?';
            $args[] = $category;
        }
        if ($payment !== '' && $payment !== 'all') {
            $where[] = 'payment_method = ?';
            $args[] = $payment;
        }

        return ['sql' => implode(' AND ', $where), 'args' => $args];
    }

    // -----------------------------------------------------------------
    //  KPI
    // -----------------------------------------------------------------

    /**
     * Date de la première vente importée (borne basse de la période "Tout").
     */
    public static function firstSaleDate(): string
    {
        try {
            $min = (string) self::pdo()->query('SELECT DATE(MIN(sold_at)) FROM sales')->fetchColumn();
        } catch (\Throwable) {
            $min = '';
        }

        return $min !== '' && $min !== 'NULL' ? $min : date('Y-01-01');
    }

    /**
     * KPI principaux sur la période sélectionnée.
     *
     * @return array{ca:float, profit:float, qty:int, transactions:int, members:int, avg_basket:float, margin:float}
     */
    public static function kpis(
        string $from,
        string $to,
        string $category = '',
        string $payment = ''
    ): array {
        $w = self::buildWhere($from, $to, $category, $payment);

        // CA, bénéfice, quantité, transactions issues des ventes.
        $sql = 'SELECT
                    COALESCE(SUM(price_ttc), 0) AS ca,
                    COALESCE(SUM(
                        CASE WHEN is_custom_amount = 0
                             THEN price_ttc - IFNULL((' . self::COST_SUBQUERY . '), 0) * quantity
                             ELSE 0 END
                    ), 0) AS profit,
                    COALESCE(SUM(quantity), 0) AS qty,
                    COUNT(*) AS transactions
                FROM sales
                WHERE ' . $w['sql'];

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($w['args']);
            $row = $stmt->fetch() ?: [];
        } catch (\Throwable) {
            $row = [];
        }

        // Nouveaux membres (users) inscrits sur la période.
        $members = 0;
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COUNT(*) FROM users WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?'
            );
            $stmt->execute([$from, $to]);
            $members = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $members = 0;
        }

        $ca          = (float) ($row['ca'] ?? 0);
        $profit      = (float) ($row['profit'] ?? 0);
        $transactions = (int) ($row['transactions'] ?? 0);
        $qty         = (int) ($row['qty'] ?? 0);

        return [
            'ca'          => $ca,
            'profit'      => $profit,
            'qty'         => $qty,
            'transactions' => $transactions,
            'members'     => $members,
            'avg_basket'  => $transactions > 0 ? $ca / $transactions : 0.0,
            'margin'      => $ca > 0 ? ($profit / $ca) * 100 : 0.0,
        ];
    }

    /**
     * KPI sur la période précédente équivalente (même durée, juste avant `from`).
     *
     * @return array{ca:float, profit:float, qty:int, transactions:int, members:int, avg_basket:float, margin:float}
     */
    public static function kpisPrevious(
        string $from,
        string $to,
        string $category = '',
        string $payment = ''
    ): array {
        try {
            $start = new \DateTimeImmutable($from);
            $end   = new \DateTimeImmutable($to);
        } catch (\Throwable) {
            return self::emptyKpis();
        }

        $days      = (int) $start->diff($end)->days + 1;
        $prevTo    = $start->modify('-1 day')->format('Y-m-d');
        $prevFrom  = $start->modify('-' . $days . ' days')->format('Y-m-d');

        return self::kpis($prevFrom, $prevTo, $category, $payment);
    }

    /**
     * @return array{ca:float, profit:float, qty:int, transactions:int, members:int, avg_basket:float, margin:float}
     */
    private static function emptyKpis(): array
    {
        return [
            'ca' => 0.0, 'profit' => 0.0, 'qty' => 0, 'transactions' => 0,
            'members' => 0, 'avg_basket' => 0.0, 'margin' => 0.0,
        ];
    }

    // -----------------------------------------------------------------
    //  Tendances
    // -----------------------------------------------------------------

    /**
     * Évolution du CA et du bénéfice selon la granularité.
     *
     * @param string $granularity "day" | "week" | "month"
     * @return list<array{label:string, ca:float, profit:float, bucket:string}>
     */
    public static function revenueTrend(
        string $from,
        string $to,
        string $granularity = 'day',
        string $category = '',
        string $payment = ''
    ): array {
        $w = self::buildWhere($from, $to, $category, $payment);

        [$bucketSql, $mode] = self::bucketExpr($granularity);

        $sql = 'SELECT ' . $bucketSql . ' AS bucket,
                       COALESCE(SUM(price_ttc), 0) AS ca,
                       COALESCE(SUM(
                           CASE WHEN is_custom_amount = 0
                                THEN price_ttc - IFNULL((' . self::COST_SUBQUERY . '), 0) * quantity
                                ELSE 0 END
                       ), 0) AS profit
                FROM sales
                WHERE ' . $w['sql'] . '
                GROUP BY bucket
                ORDER BY bucket ASC';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($w['args']);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }

        // Indexation par bucket puis remplissage des trous chronologiques.
        $byBucket = [];
        foreach ($rows as $r) {
            $b = (string) ($r['bucket'] ?? '');
            if ($b === '') {
                continue;
            }
            $byBucket[$b] = [
                'ca'     => (float) ($r['ca'] ?? 0),
                'profit' => (float) ($r['profit'] ?? 0),
            ];
        }

        $buckets = self::enumerateBuckets($from, $to, $mode);

        $out = [];
        foreach ($buckets as $b) {
            $entry = $byBucket[$b['key']] ?? ['ca' => 0.0, 'profit' => 0.0];
            $out[] = [
                'bucket' => $b['key'],
                'label'  => $b['label'],
                'ca'     => $entry['ca'],
                'profit' => $entry['profit'],
            ];
        }

        return $out;
    }

    /**
     * Expression SQL du bucket selon la granularité.
     *
     * @return array{0:string,1:string}  [expression SQL, mode php]
     */
    private static function bucketExpr(string $granularity): array
    {
        return match ($granularity) {
            'week'  => ['DATE(DATE_SUB(sold_at, INTERVAL WEEKDAY(sold_at) DAY))', 'week'],
            'month' => ['DATE_FORMAT(sold_at, "%Y-%m-01")', 'month'],
            default => ['DATE(sold_at)', 'day'],
        };
    }

    /**
     * Liste continue des buckets entre from et to (jours/semaines/mois).
     *
     * @return list<array{key:string, label:string}>
     */
    private static function enumerateBuckets(string $from, string $to, string $mode): array
    {
        try {
            $start = new \DateTimeImmutable($from);
            $end   = new \DateTimeImmutable($to);
        } catch (\Throwable) {
            return [];
        }

        $buckets = [];
        $cursor  = clone $start;

        while ($cursor <= $end) {
            if ($mode === 'day') {
                $buckets[] = ['key' => $cursor->format('Y-m-d'), 'label' => $cursor->format('d/m')];
                $cursor = $cursor->modify('+1 day');
            } elseif ($mode === 'week') {
                $buckets[] = ['key' => $cursor->format('Y-m-d'), 'label' => 'S ' . $cursor->format('d/m')];
                $cursor = $cursor->modify('+7 days');
            } else {
                $buckets[] = ['key' => $cursor->format('Y-m-01'), 'label' => $cursor->format('m/y')];
                $cursor = $cursor->modify('first day of next month');
            }
        }

        return $buckets;
    }

    // -----------------------------------------------------------------
    //  Répartitions
    // -----------------------------------------------------------------

    /**
     * Top N produits (CA) sur la période.
     *
     * @return list<array{label:string, ca:float, qty:int}>
     */
    public static function topProducts(
        string $from,
        string $to,
        int $limit = 10,
        string $category = '',
        string $payment = ''
    ): array {
        $limit = max(1, $limit);
        $w = self::buildWhere($from, $to, $category, $payment);

        $sql = 'SELECT COALESCE(NULLIF(product_key, ""), description) AS label,
                       COALESCE(SUM(price_ttc), 0) AS ca,
                       COALESCE(SUM(quantity), 0) AS qty
                FROM sales
                WHERE ' . $w['sql'] . '
                  AND is_custom_amount = 0
                GROUP BY label
                ORDER BY ca DESC
                LIMIT ' . $limit;

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($w['args']);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $label = trim((string) ($r['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'ca'    => (float) ($r['ca'] ?? 0),
                'qty'   => (int) ($r['qty'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Répartition du CA par catégorie (donut).
     *
     * @return list<array{label:string, ca:float}>
     */
    public static function revenueByCategory(
        string $from,
        string $to,
        string $payment = ''
    ): array {
        $w = self::buildWhere($from, $to, '', $payment);

        $sql = 'SELECT COALESCE(NULLIF(category, ""), "Non classé") AS label,
                       COALESCE(SUM(price_ttc), 0) AS ca
                FROM sales
                WHERE ' . $w['sql'] . '
                GROUP BY label
                ORDER BY ca DESC';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($w['args']);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'label' => (string) ($r['label'] ?? 'Non classé'),
                'ca'    => (float) ($r['ca'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Répartition des paiements (CA + nb transactions par moyen).
     *
     * @return array{by_ca:array<string,float>, by_count:array<string,int>}
     */
    public static function paymentSplit(
        string $from,
        string $to,
        string $category = ''
    ): array {
        $w = self::buildWhere($from, $to, $category, '');

        $sql = 'SELECT payment_method,
                       COALESCE(SUM(price_ttc), 0) AS ca,
                       COUNT(*) AS nb
                FROM sales
                WHERE ' . $w['sql'] . '
                GROUP BY payment_method';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($w['args']);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }

        $byCa    = ['CARTE' => 0.0, 'LIQUIDE' => 0.0];
        $byCount = ['CARTE' => 0, 'LIQUIDE' => 0];

        foreach ($rows as $r) {
            $method = (string) ($r['payment_method'] ?? '');
            if ($method === '') {
                continue;
            }
            $byCa[$method]    = (float) ($r['ca'] ?? 0);
            $byCount[$method] = (int) ($r['nb'] ?? 0);
        }

        return ['by_ca' => $byCa, 'by_count' => $byCount];
    }

    /**
     * Heatmap CA × (jour de la semaine, heure).
     *
     * @return array<int,array<int,float>>  [dayIndex0to6][hour0to23] = ca
     */
    public static function salesByDayHour(
        string $from,
        string $to,
        string $category = '',
        string $payment = ''
    ): array {
        $w = self::buildWhere($from, $to, $category, $payment);

        $sql = 'SELECT (WEEKDAY(sold_at)) AS d, HOUR(sold_at) AS h,
                       COALESCE(SUM(price_ttc), 0) AS ca
                FROM sales
                WHERE ' . $w['sql'] . '
                GROUP BY d, h';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($w['args']);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }

        $matrix = [];
        for ($d = 0; $d < 7; $d++) {
            $matrix[$d] = array_fill(0, 24, 0.0);
        }
        foreach ($rows as $r) {
            $d = (int) ($r['d'] ?? 0);
            $h = (int) ($r['h'] ?? 0);
            if ($d >= 0 && $d < 7 && $h >= 0 && $h < 24) {
                $matrix[$d][$h] += (float) ($r['ca'] ?? 0);
            }
        }

        return $matrix;
    }

    /**
     * Activité de l'association sur les N derniers mois
     * (inscriptions événements, nouveaux membres, votes aux sondages).
     *
     * @return array{labels:list<string>, registrations:list<int>, members:list<int>, votes:list<int>}
     */
    public static function activityTrend(int $months = 6): array
    {
        $months = max(1, $months);

        $regs   = self::monthlyCount('event_registrations', 'created_at', $months);
        $mems   = self::monthlyCount('users', 'created_at', $months);
        $votes  = self::monthlyCount('poll_votes', 'created_at', $months);

        return [
            'labels'         => array_column($regs, 'label'),
            'registrations'  => array_column($regs, 'value'),
            'members'        => array_column($mems, 'value'),
            'votes'          => array_column($votes, 'value'),
        ];
    }

    /**
     * Comptage mensuel sur une table (colonne created_at), avec complément
     * des mois manquants par des zéros.
     *
     * @return list<array{label:string, value:int}>
     */
    private static function monthlyCount(string $table, string $column, int $months): array
    {
        $sql = 'SELECT DATE_FORMAT(' . $column . ', "%Y-%m") AS ym,
                       COUNT(*) AS value
                FROM ' . $table . '
                WHERE ' . $column . ' >= DATE_SUB(CURDATE(), INTERVAL ' . $months . ' MONTH)
                GROUP BY ym';

        try {
            $rows = self::pdo()->query($sql)->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }

        $byKey = [];
        foreach ($rows as $r) {
            $k = (string) ($r['ym'] ?? '');
            if ($k !== '') {
                $byKey[$k] = (int) ($r['value'] ?? 0);
            }
        }

        $out  = [];
        $now  = new \DateTimeImmutable('first day of this month');
        for ($i = $months - 1; $i >= 0; $i--) {
            $d  = $now->modify('-' . $i . ' months');
            $ym = $d->format('Y-m');
            $out[] = [
                'label' => $d->format('m/y'),
                'value' => array_key_exists($ym, $byKey) ? $byKey[$ym] : 0,
            ];
        }

        return $out;
    }

    /**
     * Évolution de la valeur du stock (somme stock × coût) sur les N derniers
     * mois. La table product_stocks ne stockant pas d'historique, on renvoie la
     * valeur actuelle répétée (utile comme repère) — sans erreur si la table
     * est vide.
     *
     * @return array{labels:list<string>, values:list<float>}
     */
    public static function stockTrend(int $months = 6): array
    {
        $months = max(1, $months);

        $sql = 'SELECT COALESCE(SUM(ps.stock * IFNULL(pc.cost_price, 0)), 0) AS value
                FROM product_stocks ps
                LEFT JOIN product_costs pc ON pc.product_key = ps.product_key
                   AND (pc.valid_to IS NULL OR pc.valid_to > CURDATE())
                ORDER BY pc.valid_from DESC';

        try {
            $value = (float) (self::pdo()->query($sql)->fetchColumn() ?: 0);
        } catch (\Throwable) {
            $value = 0.0;
        }

        $labels = [];
        $values = [];
        $now    = new \DateTimeImmutable('first day of this month');
        for ($i = $months - 1; $i >= 0; $i--) {
            $labels[] = $now->modify('-' . $i . ' months')->format('m/y');
            $values[] = round($value, 2);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    // -----------------------------------------------------------------
    //  Tableau récapitulatif & insights
    // -----------------------------------------------------------------

    /**
     * Tableau détaillé par produit (CA, coût moyen, bénéfice, marge).
     *
     * @return list<array{product:string, category:string, qty:int, ca:float, cost:float, profit:float, margin:float}>
     */
    public static function productTable(
        string $from,
        string $to,
        string $category = '',
        string $payment = ''
    ): array {
        $w = self::buildWhere($from, $to, $category, $payment);

        $sql = 'SELECT
                    COALESCE(NULLIF(product_key, ""), description) AS product,
                    COALESCE(NULLIF(category, ""), "Non classé") AS category,
                    COALESCE(SUM(quantity), 0) AS qty,
                    COALESCE(SUM(price_ttc), 0) AS ca,
                    AVG(IFNULL((' . self::COST_SUBQUERY . '), 0)) AS cost,
                    COALESCE(SUM(
                        price_ttc - IFNULL((' . self::COST_SUBQUERY . '), 0) * quantity
                    ), 0) AS profit
                FROM sales
                WHERE ' . $w['sql'] . '
                  AND is_custom_amount = 0
                GROUP BY product, category
                ORDER BY ca DESC';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($w['args']);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $product = trim((string) ($r['product'] ?? ''));
            if ($product === '') {
                continue;
            }
            $ca     = (float) ($r['ca'] ?? 0);
            $cost   = (float) ($r['cost'] ?? 0);
            $profit = (float) ($r['profit'] ?? 0);

            $out[] = [
                'product'  => $product,
                'category' => (string) ($r['category'] ?? 'Non classé'),
                'qty'      => (int) ($r['qty'] ?? 0),
                'ca'       => $ca,
                'cost'     => $cost,
                'profit'   => $profit,
                'margin'   => $ca > 0 ? ($profit / $ca) * 100 : 0.0,
            ];
        }

        return $out;
    }

    /**
     * Insights automatiques (produit star, meilleure croissance, alerte marge,
     * meilleur jour). Comparaisons vs période précédente équivalente.
     *
     * @return list<array{icon:string, title:string, text:string, tone:string}>
     */
    public static function insights(
        string $from,
        string $to,
        string $category = '',
        string $payment = ''
    ): array {
        $insights = [];

        // 1) Produit star (plus gros volume).
        $top = self::topProducts($from, $to, 1, $category, $payment);
        if ($top !== []) {
            $star   = $top[0];
            $insights[] = [
                'icon'  => 'star',
                'title' => 'Produit star',
                'text'  => sprintf(
                    '%s : %d unités vendues (%s de CA).',
                    $star['label'],
                    $star['qty'],
                    number_format($star['ca'], 2, ',', ' ') . ' €'
                ),
                'tone'  => 'teal',
            ];
        }

        // 2) Plus forte croissance vs période précédente.
        $now      = self::topProducts($from, $to, 50, $category, $payment);
        $previous = self::topProducts(
            self::prevFrom($from, $to),
            self::prevTo($from),
            50,
            $category,
            $payment
        );
        $prevMap = [];
        foreach ($previous as $p) {
            $prevMap[$p['label']] = $p['ca'];
        }

        $bestGrowth  = null;
        $bestGrowthP = 0.0;
        foreach ($now as $p) {
            $prev = $prevMap[$p['label']] ?? 0.0;
            if ($prev > 0 && $p['ca'] > $prev) {
                $growth = (($p['ca'] - $prev) / $prev) * 100;
                if ($growth > $bestGrowthP) {
                    $bestGrowthP = $growth;
                    $bestGrowth  = $p['label'];
                }
            }
        }
        if ($bestGrowth !== null) {
            $insights[] = [
                'icon'  => 'trend-up',
                'title' => 'Plus forte croissance',
                'text'  => sprintf('%s : +%d%% vs période précédente.', $bestGrowth, (int) round($bestGrowthP)),
                'tone'  => 'green',
            ];
        }

        // 3) Alerte marge basse (< 10%).
        $table = self::productTable($from, $to, $category, $payment);
        foreach ($table as $row) {
            if ($row['ca'] > 0 && $row['margin'] < 10) {
                $insights[] = [
                    'icon'  => 'alert',
                    'title' => 'Alerte marge',
                    'text'  => sprintf(
                        '%s a une marge de seulement %d%%.',
                        $row['product'],
                        (int) round($row['margin'])
                    ),
                    'tone'  => 'red',
                ];
                break;
            }
        }

        // 4) Meilleur jour de la semaine (% du CA).
        $matrix = self::salesByDayHour($from, $to, $category, $payment);
        $dayTotals = array_map('array_sum', $matrix);
        $total     = array_sum($dayTotals);
        $days      = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
        if ($total > 0) {
            $bestIdx = 0;
            for ($d = 1; $d < 7; $d++) {
                if ($dayTotals[$d] > $dayTotals[$bestIdx]) {
                    $bestIdx = $d;
                }
            }
            $insights[] = [
                'icon'  => 'clock',
                'title' => 'Meilleur jour',
                'text'  => sprintf(
                    'Le %s représente %d%% du CA.',
                    $days[$bestIdx],
                    (int) round(($dayTotals[$bestIdx] / $total) * 100)
                ),
                'tone'  => 'amber',
            ];
        }

        return $insights;
    }

    private static function prevFrom(string $from, string $to): string
    {
        try {
            $start = new \DateTimeImmutable($from);
            $end   = new \DateTimeImmutable($to);
            $days  = (int) $start->diff($end)->days + 1;

            return $start->modify('-' . $days . ' days')->format('Y-m-d');
        } catch (\Throwable) {
            return $from;
        }
    }

    private static function prevTo(string $from): string
    {
        try {
            return (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
        } catch (\Throwable) {
            return $from;
        }
    }
}
