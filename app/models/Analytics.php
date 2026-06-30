<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Agrégats pour le dashboard analytics (Fonctionnalité 14).
 *
 * Toutes les requêtes lisent des données existantes (sales, event_registrations,
 * users) sans jamais les modifier. Les fenêtres temporelles sont calculées en
 * SQL à partir de NOW() pour rester indépendantes de l'horloge PHP.
 */
final class Analytics extends Model
{
    protected static string $table = 'sales';

    /**
     * CA mensuel TTC sur les N derniers mois (incluant le mois courant).
     *
     * @return list<array{label:string, ca:float}> Tri chronologique.
     */
    public static function monthlyRevenue(int $months = 12): array
    {
        $months = max(1, $months);

        $sql = 'SELECT DATE_FORMAT(sold_at, "%Y-%m") AS ym,
                       COALESCE(SUM(price_ttc), 0) AS ca
                FROM sales
                WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL ' . $months . ' MONTH)
                GROUP BY ym
                ORDER BY ym ASC';

        try {
            $rows = static::pdo()->query($sql)->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }

        return self::fillMonths(
            $rows,
            static fn (array $r): string => (string) ($r['ym'] ?? ''),
            static fn (array $r): float => (float) ($r['ca'] ?? 0),
            $months,
            0.0
        );
    }

    /**
     * Top produits (CA) sur les N derniers mois.
     *
     * @return list<array{label:string, ca:float, qty:int}>
     */
    public static function topProducts(int $months = 12, int $limit = 10): array
    {
        $months = max(1, $months);
        $limit = max(1, $limit);

        $sql = 'SELECT COALESCE(NULLIF(product_key, ""), description) AS label,
                       COALESCE(SUM(price_ttc), 0) AS ca,
                       COALESCE(SUM(quantity), 0) AS qty
                FROM sales
                WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL ' . $months . ' MONTH)
                  AND is_custom_amount = 0
                GROUP BY label
                ORDER BY ca DESC
                LIMIT ' . $limit;

        try {
            $rows = static::pdo()->query($sql)->fetchAll();
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
     * Répartition du CA par catégorie sur les N derniers mois (donut).
     *
     * @return list<array{label:string, ca:float}>
     */
    public static function revenueByCategory(int $months = 12): array
    {
        $months = max(1, $months);

        $sql = 'SELECT COALESCE(NULLIF(category, ""), "Non classé") AS label,
                       COALESCE(SUM(price_ttc), 0) AS ca
                FROM sales
                WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL ' . $months . ' MONTH)
                GROUP BY label
                ORDER BY ca DESC';

        try {
            $rows = static::pdo()->query($sql)->fetchAll();
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
     * Inscriptions aux événements par mois (event_registrations.created_at).
     *
     * @return list<array{label:string, value:int}>
     */
    public static function registrationsByMonth(int $months = 6): array
    {
        $months = max(1, $months);

        $sql = 'SELECT DATE_FORMAT(created_at, "%Y-%m") AS ym,
                       COUNT(*) AS value
                FROM event_registrations
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ' . $months . ' MONTH)
                GROUP BY ym
                ORDER BY ym ASC';

        try {
            $rows = static::pdo()->query($sql)->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }

        return self::fillMonths(
            $rows,
            static fn (array $r): string => (string) ($r['ym'] ?? ''),
            static fn (array $r): int => (int) ($r['value'] ?? 0),
            $months,
            0
        );
    }

    /**
     * Nouveaux membres (users) par mois.
     *
     * @return list<array{label:string, value:int}>
     */
    public static function newMembersByMonth(int $months = 6): array
    {
        $months = max(1, $months);

        $sql = 'SELECT DATE_FORMAT(created_at, "%Y-%m") AS ym,
                       COUNT(*) AS value
                FROM users
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ' . $months . ' MONTH)
                GROUP BY ym
                ORDER BY ym ASC';

        try {
            $rows = static::pdo()->query($sql)->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }

        return self::fillMonths(
            $rows,
            static fn (array $r): string => (string) ($r['ym'] ?? ''),
            static fn (array $r): int => (int) ($r['value'] ?? 0),
            $months,
            0
        );
    }

    /**
     * Complète une série mensuelle avec des zéros pour les mois sans données,
     * et renvoie un label lisible (ex. "09/25").
     *
     * @template V
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):string $keyFn
     * @param callable(array<string,mixed>):V $valFn
     * @param V $zero Valeur par défaut pour un mois sans données.
     * @return list<array{label:string, value:V}>
     */
    private static function fillMonths(array $rows, callable $keyFn, callable $valFn, int $months, mixed $zero): array
    {
        $byKey = [];
        foreach ($rows as $r) {
            $k = $keyFn($r);
            if ($k === '') {
                continue;
            }
            $byKey[$k] = $valFn($r);
        }

        $out = [];
        $now = new \DateTimeImmutable('first day of this month');
        for ($i = $months - 1; $i >= 0; $i--) {
            $d = $now->modify('-' . $i . ' months');
            $ym = $d->format('Y-m');
            $out[] = [
                'label' => $d->format('m/y'),
                'value' => array_key_exists($ym, $byKey) ? $byKey[$ym] : $zero,
            ];
        }

        return $out;
    }
}
