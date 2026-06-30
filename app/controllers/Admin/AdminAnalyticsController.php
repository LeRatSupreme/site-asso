<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Analytics;
use App\Models\Sale;

/**
 * Dashboard Analytics Pro (Fonctionnalité 14, refonte).
 *
 * Tous les filtres sont passés en GET (partageables par URL) : période
 * rapide ou intervalle custom, granularité, catégorie, moyen de paiement.
 */
final class AdminAnalyticsController extends AdminBaseController
{
    /**
     * Périodes rapides disponibles (clé => libellé).
     */
    private const PERIODS = [
        '7d'   => '7 derniers jours',
        '30d'  => '30 derniers jours',
        '90d'  => '3 derniers mois',
        '180d' => '6 derniers mois',
        '365d' => '12 derniers mois',
        'ytd'  => 'Année civile',
        'all'  => 'Tout',
        'custom' => 'Personnalisé',
    ];

    public function index(): void
    {
        // ADMIN + TRESORERIE (lecture seule d'agrégats compta).
        $this->guardCompta();

        // ---- Lecture des filtres GET (valeurs sûres) ----
        $period     = $this->filter('period', '30d');
        $granularity = $this->filter('granularity', '');
        $category   = $this->filter('category', 'all');
        $payment    = $this->filter('payment', 'all');
        $fromInput  = $this->filter('from', '');
        $toInput    = $this->filter('to', '');

        [$from, $to] = $this->computeWindow($period, $fromInput, $toInput);

        // Granularité par défaut selon l'étendue de la période.
        if ($granularity === '' || !in_array($granularity, ['day', 'week', 'month'], true)) {
            $granularity = $this->defaultGranularity($from, $to);
        }

        $filters = [
            'period'      => $period,
            'granularity' => $granularity,
            'category'    => $category,
            'payment'     => $payment,
            'from'        => $from,
            'to'          => $to,
            'fromInput'   => $fromInput,
            'toInput'     => $toInput,
        ];

        // ---- Données pour la vue ----
        $kpis     = Analytics::kpis($from, $to, $category, $payment);
        $kpisPrev = Analytics::kpisPrevious($from, $to, $category, $payment);

        $trend     = Analytics::revenueTrend($from, $to, $granularity, $category, $payment);
        $top       = Analytics::topProducts($from, $to, 10, $category, $payment);
        $byCat     = Analytics::revenueByCategory($from, $to, $payment);
        $payments  = Analytics::paymentSplit($from, $to, $category);
        $heatmap   = Analytics::salesByDayHour($from, $to, $category, $payment);
        $activity  = Analytics::activityTrend(6);
        $stock     = Analytics::stockTrend(6);
        $table     = Analytics::productTable($from, $to, $category, $payment);
        $insights  = Analytics::insights($from, $to, $category, $payment);

        // ---- Payload JSON pour Chart.js ----
        $payload = [
            'kpis'      => $this->kpisWithDelta($kpis, $kpisPrev),
            'trend'     => [
                'labels'  => array_column($trend, 'label'),
                'ca'      => array_column($trend, 'ca'),
                'profit'  => array_column($trend, 'profit'),
            ],
            'topProducts' => [
                'labels' => array_column($top, 'label'),
                'ca'     => array_column($top, 'ca'),
                'qty'    => array_column($top, 'qty'),
            ],
            'byCategory' => [
                'labels' => array_column($byCat, 'label'),
                'ca'     => array_column($byCat, 'ca'),
            ],
            'payments'   => $payments,
            'heatmap'    => $heatmap,
            'activity'   => $activity,
            'stock'      => $stock,
            'table'      => $table,
            'insights'   => $insights,
            'filters'    => [
                'from'        => $from,
                'to'          => $to,
                'granularity' => $granularity,
                'category'    => $category,
                'payment'     => $payment,
            ],
        ];

        $categories = Sale::distinctCategories();

        $this->renderAdmin('admin/analytics/index', [
            'title'      => 'Analytics',
            'filters'    => $filters,
            'periods'    => self::PERIODS,
            'categories' => $categories,
            'hasSales'   => Sale::count() > 0,
            'kpis'       => $this->kpisWithDelta($kpis, $kpisPrev),
            'insights'   => $insights,
            'payload'    => $payload,
            'json'       => json_encode(
                $payload,
                JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    /**
     * Récupère un paramètre GET nettoyé (ou la valeur par défaut).
     */
    private function filter(string $key, string $default = ''): string
    {
        $val = trim((string) ($_GET[$key] ?? ''));

        return $val !== '' ? $val : $default;
    }

    /**
     * Calcule l'intervalle [from, to] selon la période sélectionnée.
     *
     * @return array{0:string,1:string}
     */
    private function computeWindow(string $period, string $fromInput, string $toInput): array
    {
        $today = date('Y-m-d');

        if ($period === 'custom' && $this->validDate($fromInput) && $this->validDate($toInput)) {
            return $fromInput <= $toInput ? [$fromInput, $toInput] : [$toInput, $fromInput];
        }

        return match ($period) {
            '7d'   => [date('Y-m-d', strtotime('-6 days')), $today],
            '30d'  => [date('Y-m-d', strtotime('-29 days')), $today],
            '90d'  => [date('Y-m-d', strtotime('-89 days')), $today],
            '180d' => [date('Y-m-d', strtotime('-179 days')), $today],
            '365d' => [date('Y-m-d', strtotime('-364 days')), $today],
            'ytd'  => [date('Y-01-01'), $today],
            'all'  => [$this->firstSaleDate(), $today],
            default => [date('Y-m-d', strtotime('-29 days')), $today],
        };
    }

    private function validDate(string $d): bool
    {
        return $d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
    }

    /**
     * Date de la première vente importée (pour la période "Tout").
     */
    private function firstSaleDate(): string
    {
        return Analytics::firstSaleDate();
    }

    /**
     * Granularité par défaut selon l'étendue de la période.
     */
    private function defaultGranularity(string $from, string $to): string
    {
        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;

        if ($days <= 45) {
            return 'day';
        }
        if ($days <= 180) {
            return 'week';
        }

        return 'month';
    }

    /**
     * Ajoute à chaque KPI son delta vs période précédente.
     *
     * @return array<string,mixed>
     */
    private function kpisWithDelta(array $current, array $previous): array
    {
        $deltas = [];
        foreach (['ca', 'profit', 'qty', 'transactions', 'members', 'avg_basket', 'margin'] as $key) {
            $deltas[$key . '_delta'] = $this->pctDelta(
                (float) ($current[$key] ?? 0),
                (float) ($previous[$key] ?? 0)
            );
        }

        return array_merge($current, $deltas);
    }

    /**
     * Variation en % entre deux valeurs (null si base nulle).
     */
    private function pctDelta(float $current, float $previous): ?float
    {
        if ($previous == 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
