<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\ComptaCalc;
use App\Core\Compta\SalesForecast;
use App\Models\Analytics;
use App\Models\Sale;
use App\Models\Setting;

/**
 * Dashboard Analytics Pro (Fonctionnalité 14, refonte).
 *
 * Tous les filtres sont passés en GET (partageables par URL) : période
 * rapide ou intervalle custom, granularité, catégorie, moyen de paiement.
 * Le même dashboard est servi dans l'espace admin (connexion) et dans le
 * kiosque ADMIN (lien secret à jeton, sans connexion).
 */
final class AdminAnalyticsController extends AdminBaseController
{
    /**
     * Périodes rapides disponibles (clé => libellé).
     */
    private const PERIODS = [
        '1d'   => '1 jour',
        'week' => 'Semaine actuelle',
        '7d'   => '7 derniers jours',
        '30d'  => '30 derniers jours',
        'all'  => 'Tout',
        'custom' => 'Personnalisé',
    ];

    public function index(): void
    {
        // ADMIN + TRESORERIE (lecture seule d'agrégats compta).
        $this->guardCompta();

        $this->renderAdmin('admin/analytics/index', $this->buildPage() + ['title' => 'Analytics']);
    }

    /**
     * Version KIOSQUE du dashboard Analytics : accès par lien secret avec
     * le JETON ADMIN (données financières complètes), sans connexion —
     * le jeton EST l'authentification. Rendu : vue compacte dédiée
     * téléphone à onglets (la vue desktop reste intacte pour l'admin).
     */
    public function kiosk(string $token): void
    {
        if (!$this->adminTokenOk($token)) {
            $this->deny();

            return;
        }

        $this->renderKiosk('admin/analytics/kiosk', $this->buildPage('7d') + [
            'title' => 'Analytique',
            'kiosk' => true,
            'token' => $token,
        ]);
    }

    /**
     * Construit toutes les données de la page (filtres GET, fenêtre de
     * temps, agrégats, prévisions, insights, payload JSON) — partagées
     * entre les rendus admin et kiosque.
     *
     * @return array{filters:array<string,mixed>,periods:array<string,string>,categories:list<string>,hasSales:bool,kpis:array<string,mixed>,insights:list<array<string,mixed>>,forecast:array<string,mixed>,day_track:array<string,mixed>,weekday:array<int,array{ca:float,qty:int,tx:int}>,hourly:array<int,array{ca:float,qty:int,tx:int}>,products:list<string>,productSelected:string,productWeekly:list<array{week:string,ca:float,qty:int}>,json:string}
     */
    private function buildPage(string $defaultPeriod = 'week', ?\DateTimeImmutable $now = null): array
    {
        // ---- Lecture des filtres GET (valeurs sûres) ----
        // $defaultPeriod : sans « period » en GET, le dashboard ouvre sur
        // la semaine actuelle ; le kiosque téléphone garde « 7 derniers
        // jours » via son appel explicite.
        $period      = $this->filter('period', $defaultPeriod);
        $granularity = $this->filter('granularity', '');
        $category    = $this->filter('category', 'all');
        $payment     = $this->filter('payment', 'all');
        $fromInput   = $this->filter('from', '');
        $toInput     = $this->filter('to', '');

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

        // ---- Prévisions & rythmes (jours de semaine, heures, produit) ----
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone(SalesForecast::TZ));

        // CA quotidien zéro-rempli, indépendant de la granularité affichée.
        $dayTrend = $granularity === 'day'
            ? $trend
            : Analytics::revenueTrend($from, $to, 'day', $category, $payment);

        $dailyCa = [];
        foreach ($dayTrend as $b) {
            $dailyCa[$b['bucket']] = (float) $b['ca'];
        }

        $forecast = SalesForecast::forecast($dailyCa, $now);

        // Historique quotidien (libellés français) + position du bar du jour.
        $histLabels = [];
        $histValues = [];
        $todayKey   = $now->format('Y-m-d');
        $todayIndex = false;
        foreach ($dayTrend as $i => $b) {
            if ($b['bucket'] === $todayKey) {
                $todayIndex = $i;
            }
            $histLabels[] = SalesForecast::dayLabel(new \DateTimeImmutable($b['bucket']));
            $histValues[] = (float) $b['ca'];
        }
        if ($todayIndex === false) {
            $histLabels[] = SalesForecast::dayLabel($now);
            $histValues[] = (float) ($dailyCa[$todayKey] ?? 0.0);
            $todayIndex   = count($histValues) - 1;
        }

        // Pro-rata du jour : parts horaires réelles du CA sur la période.
        $hourly    = Analytics::revenueByHour($from, $to, $category, $payment);
        $hourTotal = 0.0;
        foreach ($hourly as $row) {
            $hourTotal += $row['ca'];
        }
        $hourShares = [];
        if ($hourTotal > 0) {
            foreach ($hourly as $h => $row) {
                $hourShares[(int) $h] = $row['ca'] / $hourTotal;
            }
        }

        $todayFull = $forecast['today']['forecast_full'];
        if ($todayFull !== null && $todayFull > 0) {
            $soFar = SalesForecast::proRatedForecast($todayFull, $hourShares, $now);
            $forecast['today']['forecast_so_far'] = $soFar;
            $forecast['today']['delta_pct'] = $this->pctDelta((float) $forecast['today']['actual'], $soFar);
        }

        $forecast['history']     = ['labels' => $histLabels, 'values' => $histValues];
        $forecast['today_index'] = $todayIndex;
        $forecast['today']['label'] = SalesForecast::dayLabel($now);
        $forecast['now_hour']    = (int) $now->format('G');

        // ---- Estimation du jour (fenêtre d'analyse au choix) ----
        // Indépendante de la période affichée : la fenêtre fw (7/14/30 j,
        // GET « fw », défaut 14) finit aujourd'hui et sert à la fois à
        // l'estimation (moyenne par jour de semaine sur la fenêtre) et aux
        // parts horaires attendues. Fiable dès min(7, fw-1) jours écoulés :
        // fenêtre complète pour 7 j, une semaine pleine au-delà.
        $fwRaw = $this->filter('fw', '14');
        $fw    = in_array($fwRaw, ['7', '14', '30'], true) ? (int) $fwRaw : 14;

        $trackFrom = $now->modify('-' . ($fw - 1) . ' days')->format('Y-m-d');
        $trackTo   = $now->format('Y-m-d');

        $trackDailyCa = [];
        foreach (Analytics::revenueTrend($trackFrom, $trackTo, 'day', $category, $payment) as $b) {
            $trackDailyCa[$b['bucket']] = (float) $b['ca'];
        }

        $trackForecast = SalesForecast::forecast($trackDailyCa, $now);
        $dayEstimate   = $trackForecast['today']['forecast_full'];

        // Parts horaires du CA sur la fenêtre (normalisées), et CA du jour
        // heure par heure (aujourd'hui inclus dans la fenêtre).
        $trackHourly    = Analytics::revenueByHour($trackFrom, $trackTo, $category, $payment);
        $trackHourTotal = 0.0;
        foreach ($trackHourly as $row) {
            $trackHourTotal += $row['ca'];
        }
        $trackShares = [];
        if ($trackHourTotal > 0) {
            foreach ($trackHourly as $h => $row) {
                $trackShares[(int) $h] = $row['ca'] / $trackHourTotal;
            }
        }

        $todayHourlyCa = [];
        foreach (Analytics::revenueByHour($trackTo, $trackTo, $category, $payment) as $h => $row) {
            $todayHourlyCa[(int) $h] = (float) $row['ca'];
        }

        $dayTrack = [
            'window'    => $fw,
            'estimate'  => $dayEstimate,
            'reliable'  => $trackForecast['elapsed_days'] >= min(7, $fw - 1),
            'track'     => SalesForecast::dayTrack(
                $todayHourlyCa,
                (float) ($dayEstimate ?? 0.0),
                $trackShares,
                $now
            ),
            'now_hour'  => (int) $now->format('G'),
        ];

        $weekday = Analytics::revenueByWeekday($from, $to, $category, $payment);

        // Jours écoulés par jour de semaine dans la fenêtre (pour les
        // moyennes « Par jour de semaine ») : aujourd'hui exclu, comme
        // pour les prévisions. Itère la carte quotidienne zéro-remplie.
        $weekdayCounts = array_fill(0, 7, 0);
        foreach ($dailyCa as $day => $ca) {
            if ($day >= $todayKey) {
                continue;
            }
            try {
                $weekdayCounts[(int) (new \DateTimeImmutable($day))->format('N') - 1]++;
            } catch (\Throwable) {
                continue;
            }
        }

        // Moyennes horaires (CA / jours écoulés) et séries du jour pour
        // la carte « Par heure » : profil attendu (parts de la fenêtre
        // « estimation du jour », cohérent avec day_track) et CA réel
        // du jour heure par heure.
        $elapsedDays  = (int) $forecast['elapsed_days'];
        $hourlyCaCols = array_column($hourly, 'ca');
        $hourlyCaAvg  = array_map(
            static fn (float $ca): float => $elapsedDays > 0 ? $ca / $elapsedDays : 0.0,
            $hourlyCaCols
        );
        $todayCaSeries = array_map(
            static fn (int $h): float => (float) ($todayHourlyCa[$h] ?? 0.0),
            range(0, 23)
        );

        // Produit suivi (sélecteur GET, défaut = produit star de la période).
        $products = Sale::distinctProducts();
        $wanted   = $this->filter('product', '');
        if ($wanted !== '' && in_array($wanted, $products, true)) {
            $productSelected = $wanted;
        } elseif ($top !== []) {
            $productSelected = (string) $top[0]['label'];
        } elseif ($products !== []) {
            $productSelected = (string) $products[0];
        } else {
            $productSelected = '';
        }
        $productWeekly = $productSelected !== ''
            ? Analytics::productWeeklyTrend($productSelected, $from, $to, $category, $payment)
            : [];

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
            'forecast'   => $forecast,
            'day_track'  => $dayTrack,
            'weekday'    => [
                'labels'      => ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'],
                'ca'          => array_column($weekday, 'ca'),
                'qty'         => array_column($weekday, 'qty'),
                'tx'          => array_column($weekday, 'tx'),
                'ca_avg'      => SalesForecast::weekdayAverages(array_column($weekday, 'ca'), $weekdayCounts),
                'days_count'  => $weekdayCounts,
                'today_index' => (int) $now->format('N') - 1,
                'avg_per_day' => $forecast['global_avg'],
            ],
            'hourly'     => [
                'labels'         => array_map(static fn (int $h): string => $h . 'h', range(0, 23)),
                'ca'             => $hourlyCaCols,
                'qty'            => array_column($hourly, 'qty'),
                'tx'             => array_column($hourly, 'tx'),
                'ca_avg'         => $hourlyCaAvg,
                'today_ca'       => $todayCaSeries,
                'today_expected' => SalesForecast::hourlyProfileToday($dayEstimate, $trackShares),
                'now_hour'       => (int) $now->format('G'),
            ],
            'product_weekly' => [
                'labels' => array_map(
                    static fn (string $week): string => 'S ' . (new \DateTimeImmutable($week))->format('d/m'),
                    array_column($productWeekly, 'week')
                ),
                'ca'     => array_column($productWeekly, 'ca'),
                'qty'    => array_column($productWeekly, 'qty'),
            ],
            'products'        => $products,
            'product_selected' => $productSelected,
            'filters'    => [
                'from'        => $from,
                'to'          => $to,
                'granularity' => $granularity,
                'category'    => $category,
                'payment'     => $payment,
            ],
        ];

        return [
            'filters'        => $filters,
            'periods'        => self::PERIODS,
            'categories'     => Sale::distinctCategories(),
            'hasSales'       => Sale::count() > 0,
            'kpis'           => $this->kpisWithDelta($kpis, $kpisPrev),
            'insights'       => $insights,
            'forecast'       => $forecast,
            'day_track'      => $dayTrack,
            'weekday'        => $weekday,
            'hourly'         => $hourly,
            'products'       => $products,
            'productSelected' => $productSelected,
            'productWeekly'  => $productWeekly,
            'json'           => (string) json_encode(
                $payload,
                JSON_NUMERIC_CHECK
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE
            ),
        ];
    }

    /**
     * Jeton kiosque ADMIN (données financières complètes) : totalement
     * indépendant du jeton membres — même logique que KioskComptageController.
     */
    private function adminTokenOk(string $token): bool
    {
        $expected = trim((string) Setting::get('admin_kiosk_token', ''));
        $given    = trim($token);

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Réponse 403 pour un lien kiosque invalide ou révoqué.
     */
    private function deny(): void
    {
        http_response_code(403);
        echo '<h1>Erreur 403 — Lien invalide ou révoqué.</h1>';
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
            '1d'   => [$today, $today],
            'week' => array_values(ComptaCalc::currentWeekWindow()),
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
