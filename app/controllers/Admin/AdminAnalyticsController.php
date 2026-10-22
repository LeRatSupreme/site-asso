<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Compta\ComptaCalc;
use App\Core\Compta\Forecast\SlowPeriods;
use App\Models\Analytics;
use App\Models\ReapproDemande;
use App\Models\Sale;
use App\Models\Setting;

/**
 * Dashboard Analytics Pro (Fonctionnalité 14, refonte moteur étape 9).
 *
 * Tous les filtres sont passés en GET (partageables par URL) : période
 * rapide ou intervalle custom, granularité, catégorie, moyen de paiement,
 * fenêtre d'apprentissage « fw » et objectif hebdomadaire « goal ».
 * Le même dashboard est servi dans l'espace admin (connexion) et dans le
 * kiosque ADMIN (lien secret à jeton, sans connexion).
 *
 * UNE SEULE SOURCE DE VÉRITÉ pour les prévisions : la fenêtre
 * d'apprentissage (fw jours finissant aujourd'hui) alimente
 * AnalyticsForecastData::dataset(), et le dataset unique nourrit
 * ForecastEngine::forecast() — aucun calcul de prévision ailleurs ; le
 * contrôleur se borne à MAPPER les sorties du moteur vers les structures
 * consommées par les vues (forecast tel quel, day_track, weekday, hourly).
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

    /** Fuseau de référence de l'asso (cohérent avec le cœur de prévision). */
    private const TZ = 'Europe/Paris';

    /** Bornes de l'objectif hebdomadaire (€ / semaine). */
    private const GOAL_MIN = 10.0;
    private const GOAL_MAX = 100000.0;

    /** Objectif par défaut si aucun paramétrage valide en base. */
    private const GOAL_DEFAULT = 500.0;

    /** Abréviations françaises des jours (0 = lundi) — libellés de charts. */
    private const DAY_ABBR = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

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
     * Enregistre l'objectif hebdomadaire (setting « analytics_weekly_goal »)
     * et la période creuse saisie à la main (setting
     * « analytics_periode_creuse », champ optionnel du même formulaire ;
     * champ vide = aucune période creuse).
     *
     * CSRF : vérifié de façon CENTRALISÉE par Router::dispatch pour toute
     * requête POST hors /kiosque/ (App\Core\Csrf::checkRequest → 403 sinon),
     * exactement comme les autres formulaires admin (cf.
     * AdminSettingController::save) : le contrôleur n'a rien à faire.
     */
    public function saveGoal(): void
    {
        $this->guardCompta();

        $goal = $this->parseGoal(trim((string) ($_POST['goal'] ?? '')));

        if ($goal === null) {
            $this->setFlash('error', 'Objectif invalide : saisis un montant en euros supérieur à 0.');
            redirect(url('/admin/analytics'));

            return;
        }

        // Période creuse (vacances scolaires…) : format
        // « facteur;AAAA-MM-JJ/AAAA-MM-JJ;… » — rejet intégral si invalide.
        $creuseRaw = trim((string) ($_POST['periode_creuse'] ?? ''));
        if ($creuseRaw !== '') {
            $creuse = SlowPeriods::parse($creuseRaw);
            if ($creuse === null) {
                $this->setFlash('error', 'Période creuse invalide : format attendu « 0.8;2026-10-17/2026-11-02 » (facteur entre 0,1 et 1, dates valides, 10 plages max).');
                redirect(url('/admin/analytics'));

                return;
            }
            $creuseNorm = SlowPeriods::normalise($creuse);
            Setting::set('analytics_periode_creuse', $creuseNorm);
            $this->audit('analytics.periode_creuse', 'setting', null, ['valeur' => $creuseNorm]);
            $creuseFlash = ' Période creuse enregistrée.';
        } else {
            Setting::set('analytics_periode_creuse', '');
            $creuseFlash = ' Période creuse effacée.';
        }

        Setting::set('analytics_weekly_goal', (string) $goal);
        $this->audit('analytics.objectif', 'setting', null, ['goal' => $goal]);
        $this->setFlash('success', 'Objectif hebdomadaire enregistré : ' . number_format($goal, 2, ',', ' ') . ' € / semaine.' . $creuseFlash);
        redirect(url('/admin/analytics'));
    }

    /**
     * Construit toutes les données de la page (filtres GET, fenêtre de
     * temps, agrégats, prévisions issues du moteur, insights, payload
     * JSON) — partagées entre les rendus admin et kiosque.
     *
     * @return array<string,mixed>
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

        // Instant de référence (Paris) partagé par la fenêtre d'apprentissage
        // et le moteur ; aujourd'hui est JAMAIS dans le futur.
        $now      = $now ?? new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $todayKey = $now->format('Y-m-d');

        // ---- Objectif hebdomadaire (étape 6) : GET « goal » borné, sinon ----
        // le paramétrage enregistré (Setting), sinon 500 €. Le kiosque,
        // sans GET « goal », retombe donc sur le paramétrage lui aussi.
        $goal = $this->weeklyGoal();

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

        // ---- Croissance KPI (étape 9) : périodes de même longueur et ----
        // COMPLÈTES. La période précédente est incomplète si elle mord sur
        // l'avenir (fin >= aujourd'hui) ou si elle précède la première
        // vente importée : les % d'évolution sont alors MASQUÉS (null) et
        // la note explicite part dans meta.croissance_note.
        [$prevFrom, $prevTo] = $this->previousWindow($from, $to);
        $prevComplete = $prevTo < $todayKey && $prevFrom >= Analytics::firstSaleDate();
        $croissanceNote = $prevComplete
            ? ''
            : 'Période précédente incomplète : évolution en % non affichée.';
        $kpisDisplay = $this->kpisWithDelta($kpis, $kpisPrev, $prevComplete);

        // ---- Fenêtre COMMUNE d'apprentissage (GET « fw », défaut 30) ----
        // Une seule question « sur combien de jours ? » : cette fenêtre
        // finissant AUJOURD'HUI INCLUS alimente le dataset unique, qui
        // nourrit le moteur — prévision du jour, horaire, jours à venir,
        // semaine, 30 jours, bénéfice et produits sortent tous de CE
        // seul appel. Défaut 30 j (~20 jours ouverts) : au-delà du seuil
        // TREND_MIN_OPEN_DAYS de la porte de tendance, la fenêtre la plus
        // large donne un niveau plus stable (fw=7 reste possible).
        //
        // L'appel au moteur vit dans ReapproDemande (couche modèle) : la
        // carte Réappro de CE page et la LISTE DE COURSES partagent la
        // MÊME demande et le MÊME stock théorique (cohérence exigée le
        // 21/10/2026) ; le contrôleur ne fait que mapper les sorties.
        $demande  = ReapproDemande::calculer($this->filter('fw', ''), $now, $goal);

        $fw        = $demande['fw'];
        $trackFrom = $demande['trackFrom'];
        $dataset   = $demande['dataset'];
        $creuse    = $demande['creuse'];
        $creuseRaw = $demande['creuseRaw'];
        $forecast  = $demande['forecast'];

        // ---- Estimation du jour : mappage SANS recalcul métier ----
        $dayTrack = $this->dayTrackFromForecast($forecast, $fw);

        // ---- Par jour de semaine : séries du moteur (modèle ré-normalisé
        // sur la prochaine occurrence + observé) ; mention de l'ajustement
        // période creuse quand une plage mord sur les 7 prochains jours
        // (les prochaines occurrences des dows ouverts).
        $weekdayObs = Analytics::revenueByWeekday($trackFrom, $todayKey, $category, $payment);
        $weekday    = $this->weekdayFromForecast($forecast, $weekdayObs, (int) $now->format('N') - 1);
        $weekday['creuse_note'] = SlowPeriods::plagesActives($creuse, $now, $now->modify('+7 days')) !== []
            ? 'Prévisions incluant la période creuse active (×' . SlowPeriods::facteurFr((float) ($creuse['facteur'] ?? 1.0)) . ')'
            : null;

        // ---- Par heure : moyenne OBSERVÉE par jour ouvert de la fenêtre ----
        // d'apprentissage + séries du jour (moteur).
        $trackHourly = Analytics::revenueByHour($trackFrom, $todayKey, $category, $payment);
        $hourly      = $this->hourlyFromForecast($forecast, $trackHourly, count($dataset->days));

        // ---- Historique observé du chart (barres CA passé) : données ----
        // observées revenueTrend(..., 'day') sur la fenêtre d'apprentissage,
        // libellés FR « Lun 16/10 » via le helper local (la classe
        // SalesForecast est supprimée).
        $histLabels = [];
        $histValues = [];
        $todayIndex = null;
        foreach (Analytics::revenueTrend($trackFrom, $todayKey, 'day', $category, $payment) as $i => $b) {
            if ((string) $b['bucket'] === $todayKey) {
                $todayIndex = $i;
            }
            try {
                $histLabels[] = self::dayLabelFr(new \DateTimeImmutable((string) $b['bucket']));
            } catch (\Throwable) {
                $histLabels[] = (string) $b['label'];
            }
            $histValues[] = (float) $b['ca'];
        }
        if ($todayIndex === null) {
            // Aucune vente aujourd'hui : le bar du jour reste positionnable.
            $histLabels[] = self::dayLabelFr($now);
            $histValues[] = 0.0;
            $todayIndex   = count($histValues) - 1;
        }
        $historique = ['labels' => $histLabels, 'values' => $histValues, 'today_index' => $todayIndex];

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

        // ---- Méta du modèle (badges, notes, fiabilité) ----
        $meta = [
            'modele'            => $forecast['modele'],
            'confiance'         => $forecast['confiance'],
            'n_jours'           => $forecast['n_jours'],
            'wape_backtest'     => $forecast['wape_backtest'],
            'coverage_backtest' => $forecast['coverage_backtest'],
            'facteur_conforme'  => $forecast['facteur_conforme'],
            'dispersion_r'      => $forecast['dispersion_r'],
            'notes'             => $forecast['notes'],
            'croissance_note'   => $croissanceNote,
        ];

        // ---- Recommandations commerciales (moteur Reco) : carte « Idées ----
        // du moment », partagée par les rendus admin ET kiosque. Toute panne
        // du moteur Reco (table non migrée, cœur en échec) est SILENCIEUSE :
        // la carte affiche alors « Recommandations indisponibles » et la
        // page analytics doit toujours se rendre.
        $recoPayload = [
            'idees'        => [],
            'corrections'  => [],
            'meta'         => ['indisponible' => true, 'raison' => 'non initialisé'],
            'insuffisant'  => false,
            'indisponible' => true,
            'raison'       => 'non initialisé',
            'fenetre'      => ['from' => '', 'to' => '', 'jours_ouverts' => 0],
        ];
        try {
            $recoPayload = AdminRecoController::payloadPourAnalytics();
            AdminRecoController::persistIdees(
                array_merge($recoPayload['idees'], $recoPayload['corrections']),
                $recoPayload['meta'],
                $recoPayload['fenetre']
            );
        } catch (\Throwable) {
            // indisponible : cf. payload par défaut ci-dessus
        }

        // ---- Payload JSON pour Chart.js ----
        $payload = [
            'kpis'      => $kpisDisplay,
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
            'weekday'    => $weekday,
            'hourly'     => $hourly,
            'semaine'    => $forecast['semaine'],
            'mois30'     => $forecast['mois30'],
            'benefice'   => $forecast['benefice'],
            'produits_reappro' => $forecast['produits'],
            'anomalies'  => $forecast['anomalies'],
            'goal'       => $goal,
            'meta'       => $meta,
            'historique' => $historique,
            'periode_creuse' => [
                'raw'     => $creuseRaw,
                'actives' => array_map(
                    static fn (array $plage): string => SlowPeriods::libellePlage($plage, (float) ($creuse['facteur'] ?? 1.0)),
                    SlowPeriods::plagesActives($creuse, $now, $now->modify('+30 days'))
                ),
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
            'kpis'           => $kpisDisplay,
            'insights'       => $insights,
            'forecast'       => $forecast,
            'day_track'      => $dayTrack,
            'weekday'        => $weekday,
            'hourly'         => $hourly,
            'semaine'        => $forecast['semaine'],
            'mois30'         => $forecast['mois30'],
            'benefice'       => $forecast['benefice'],
            'produits_reappro' => $forecast['produits'],
            'anomalies'      => $forecast['anomalies'],
            'reappro_meta'   => $demande['meta'],
            'goal'           => $goal,
            'meta'           => $meta,
            'reco'           => $recoPayload,
            'historique'     => $historique,
            'periode_creuse' => [
                'raw'     => $creuseRaw,
                'actives' => array_map(
                    static fn (array $plage): string => SlowPeriods::libellePlage($plage, (float) ($creuse['facteur'] ?? 1.0)),
                    SlowPeriods::plagesActives($creuse, $now, $now->modify('+30 days'))
                ),
            ],
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
     * Fenêtre d'apprentissage (jours calendaires) : whitelist ['7','14','30'],
     * défaut 30 (≈ 20 jours ouverts — au-dessus du seuil d'activation de la
     * tendance Hyper::TREND_MIN_OPEN_DAYS, niveau plus stable ; fw=7 reste
     * possible). GET invalide ou absent → défaut. Public statique : testable
     * en unitaire sans base ; LA RÈGLE vit dans ReapproDemande::fenetre
     * (consommée aussi par la liste de courses — même fenêtre partout).
     */
    public static function fenetreApprentissage(?string $fwRaw): int
    {
        return ReapproDemande::fenetre($fwRaw);
    }

    /**
     * Objectif hebdomadaire à afficher : GET « goal » (float > 0, borné
     * [10 ; 100 000]) sinon le paramétrage enregistré, sinon 500 €.
     */
    private function weeklyGoal(): float
    {
        $asked = $this->parseGoal($this->filter('goal', ''));
        if ($asked !== null) {
            return $asked;
        }

        return $this->parseGoal(Setting::get('analytics_weekly_goal', '500')) ?? self::GOAL_DEFAULT;
    }

    /**
     * Analyse un objectif saisi/demandé : nombre > 0 borné à
     * [GOAL_MIN ; GOAL_MAX] (virgule décimale et espace de milliers
     * acceptés), null si invalide ou non positif.
     */
    private function parseGoal(string $raw): ?float
    {
        $norm = str_replace([' ', ','], ['', '.'], trim($raw));
        if ($norm === '' || !is_numeric($norm)) {
            return null;
        }

        $goal = (float) $norm;
        if ($goal <= 0.0) {
            return null;
        }

        return min(self::GOAL_MAX, max(self::GOAL_MIN, $goal));
    }

    /**
     * Estimation du jour : mappage DIRECT des sorties du moteur, sans
     * aucun recalcul métier (une seule source de vérité : ForecastEngine).
     * Les % et intervalles horaires sont masqués (null) trop tôt dans la
     * journée, sans modèle fiable (confiance « insuffisant ») ou sans
     * attendu exploitable.
     *
     * @param array<string,mixed> $forecast
     * @return array<string,mixed>
     */
    private function dayTrackFromForecast(array $forecast, int $fw): array
    {
        $horaire = is_array($forecast['horaire'] ?? null) ? $forecast['horaire'] : [];
        $reelCa  = array_values(is_array($horaire['reel_ca'] ?? null) ? $horaire['reel_ca'] : []);
        $sParts  = array_values(is_array($horaire['s'] ?? null) ? $horaire['s'] : []);

        $prevision = is_numeric($forecast['prevision_jour'] ?? null) ? (float) $forecast['prevision_jour'] : null;

        // Cumul observé heure par heure (24 entrées).
        $cumActual = [];
        $run = 0.0;
        foreach ($reelCa as $v) {
            $run += (float) $v;
            $cumActual[] = $run;
        }

        // Attendu cumulé : prévision pleine jour × part horaire cumulée S(h) ;
        // sans prévision (jour fermé…) → 24 zéros.
        $cumExpected = [];
        if ($prevision !== null) {
            foreach ($sParts as $sh) {
                $cumExpected[] = $prevision * (float) $sh;
            }
        } else {
            $cumExpected = array_fill(0, 24, 0.0);
        }

        $confiance = (string) ($forecast['confiance'] ?? 'insuffisant');
        $tropTot   = (bool) ($forecast['trop_tot'] ?? true);
        $attenduAH = is_numeric($forecast['attendu_a_h'] ?? null) ? (float) $forecast['attendu_a_h'] : null;

        // AUCUN % affiché trop tôt, sans modèle fiable, ni attendu nul.
        $deltaPct = null;
        if (!$tropTot && $confiance !== 'insuffisant' && $attenduAH !== null && $attenduAH > 0.0) {
            $deltaPct = round(((float) ($horaire['reel_a_h'] ?? 0.0) - $attenduAH) / $attenduAH * 100.0, 1);
        }

        // Intervalle 80 % pro-rata du jour (× s_h), masqué trop tôt.
        $intervalle = is_array($forecast['intervalle_80'] ?? null) ? $forecast['intervalle_80'] : null;
        $sH = is_numeric($horaire['s_h'] ?? null) ? (float) $horaire['s_h'] : 0.0;
        $intervalleH = null;
        if (!$tropTot && $intervalle !== null && $sH > 0.0) {
            $intervalleH = [
                'lo' => (float) ($intervalle['lo'] ?? 0.0) * $sH,
                'hi' => (float) ($intervalle['hi'] ?? 0.0) * $sH,
            ];
        }

        $dansIntervalle = ($forecast['dans_intervalle'] ?? null) === null
            ? null
            : (bool) $forecast['dans_intervalle'];

        return [
            'window'          => $fw,
            'hour'            => (int) ($horaire['now_hour'] ?? 0),
            'trop_tot'        => $tropTot,
            'estimate'        => $prevision,
            'cum_actual'      => $cumActual,
            'cum_expected'    => $cumExpected,
            'actual_so_far'   => (float) ($horaire['reel_a_h'] ?? 0.0),
            'expected_so_far' => $attenduAH,
            'delta_pct'       => $deltaPct,
            'projection_eod'  => is_numeric($forecast['projection_fin_journee'] ?? null)
                ? (float) $forecast['projection_fin_journee'] : null,
            'ecart_eur'       => is_numeric($forecast['ecart_vs_attendu'] ?? null)
                ? (float) $forecast['ecart_vs_attendu'] : null,
            'intervalle_h'    => $intervalleH,
            'dans_intervalle' => $dansIntervalle,
            'confiance'       => $confiance,
            'notes'           => array_values(is_array($forecast['notes'] ?? null) ? $forecast['notes'] : []),
        ];
    }

    /**
     * Carte « Par jour de semaine » : séries du MOTEUR mappées telles
     * quelles — ca = CA attendu de la prochaine occurrence (modèle),
     * obs = moyenne brute observée du dow (null sans occurrence),
     * days_count = occ (jours passés vus), se/next_date tels quels,
     * meilleur jour depuis meilleur_jour (null = non significatif, la
     * vue affiche « Pas de différence significative ») ; quantités et
     * transactions OBSERVÉES sur la fenêtre d'apprentissage (Analytics).
     *
     * @param array<string,mixed>                       $forecast
     * @param array<int,array{ca:float,qty:int,tx:int}> $observed
     * @return array<string,mixed>
     */
    private function weekdayFromForecast(array $forecast, array $observed, int $todayIndex): array
    {
        $ca = [];
        $obs = [];
        $obsCreuse = [];
        $obsNCreuse = [];
        $se = [];
        $nextDate = [];
        $qty = [];
        $tx = [];
        $daysCount = [];
        $js = is_array($forecast['jours_semaine'] ?? null) ? $forecast['jours_semaine'] : [];

        for ($dow = 0; $dow < 7; $dow++) {
            $entry  = is_array($js[$dow] ?? null) ? $js[$dow] : [];
            $isOpen = (bool) ($entry['is_open'] ?? false);
            $ca[$dow] = $isOpen ? (float) ($entry['ca'] ?? 0.0) : null;
            $obsRaw = $entry['obs_ca'] ?? null;
            $obs[$dow] = ($isOpen && $obsRaw !== null) ? (float) $obsRaw : null;
            // Observé « dont période creuse » : moyenne et nb des jours du
            // dow dont la date est en plage creuse (null si aucun) — le
            // tooltip de la vue les ajoute à la ligne Observé.
            $obsCreuseRaw = $entry['obs_ca_creuse'] ?? null;
            $obsCreuse[$dow] = ($isOpen && $obsCreuseRaw !== null) ? (float) $obsCreuseRaw : null;
            $obsNRaw = $entry['obs_n_creuse'] ?? null;
            $obsNCreuse[$dow] = ($isOpen && $obsNRaw !== null) ? (int) $obsNRaw : null;
            $seRaw = $entry['se'] ?? null;
            $se[$dow] = ($isOpen && $seRaw !== null) ? (float) $seRaw : null;
            $nextRaw = $entry['next_date'] ?? null;
            $nextDate[$dow] = ($isOpen && $nextRaw !== null) ? (string) $nextRaw : null;
            $daysCount[$dow] = (int) ($entry['occ'] ?? 0);
            $qty[$dow] = (int) ($observed[$dow]['qty'] ?? 0);
            $tx[$dow]  = (int) ($observed[$dow]['tx'] ?? 0);
        }

        $best = null;
        $meilleur = is_array($forecast['meilleur_jour'] ?? null) ? $forecast['meilleur_jour'] : null;
        if ($meilleur !== null) {
            $bestDow = (int) ($meilleur['dow'] ?? 0);
            $best = [
                'index' => $bestDow,
                'label' => (string) ($meilleur['label'] ?? ''),
                'ca'    => (float) ($meilleur['ca'] ?? 0.0),
                'n'     => $daysCount[$bestDow],
            ];
        }

        return [
            'labels'      => self::DAY_ABBR,
            'ca'          => $ca,          // modèle : attendu prochaine occurrence
            'obs'         => $obs,         // observé : moyenne brute (null sans occ)
            'obs_creuse'  => $obsCreuse,   // observé EN plage creuse (null si aucun)
            'obs_n_creuse' => $obsNCreuse, // nb de jours observés en plage creuse
            'se'          => $se,
            'next_date'   => $nextDate,
            'qty'         => $qty,
            'tx'          => $tx,
            'days_count'  => $daysCount,
            'today_index' => $todayIndex,
            'best'        => $best,
        ];
    }

    /**
     * Carte « Par heure » : CA/hour OBSERVÉ moyen sur les jours ouverts
     * de la fenêtre d'apprentissage (Analytics ÷ jours ouverts) et séries
     * du jour issues du moteur (réel, attendu, projeté, profil S).
     *
     * @param array<string,mixed>                       $forecast
     * @param array<int,array{ca:float,qty:int,tx:int}> $trackHourly
     * @return array<string,mixed>
     */
    private function hourlyFromForecast(array $forecast, array $trackHourly, int $openDays): array
    {
        $horaire   = is_array($forecast['horaire'] ?? null) ? $forecast['horaire'] : [];
        $reelCa    = array_values(is_array($horaire['reel_ca'] ?? null) ? $horaire['reel_ca'] : []);
        $attenduCa = array_values(is_array($horaire['attendu_ca'] ?? null) ? $horaire['attendu_ca'] : []);
        $projeteCa = array_values(is_array($horaire['projete_ca'] ?? null) ? $horaire['projete_ca'] : []);
        $s         = array_values(is_array($horaire['s'] ?? null) ? $horaire['s'] : []);

        $caAvg = [];
        for ($h = 0; $h < 24; $h++) {
            $caAvg[] = $openDays > 0 ? (float) ($trackHourly[$h]['ca'] ?? 0.0) / $openDays : 0.0;
        }

        // Complète à 24 entrées par robustesse (le moteur en fournit 24).
        $pad = static function (array $series): array {
            while (count($series) < 24) {
                $series[] = 0.0;
            }

            return array_slice($series, 0, 24);
        };

        return [
            'labels'         => array_map(static fn (int $h): string => $h . 'h', range(0, 23)),
            'ca_avg'         => $caAvg,
            'today_ca'       => $pad($reelCa),
            'today_expected' => $pad($attenduCa),
            'today_projete'  => $pad($projeteCa),
            'now_hour'       => (int) ($horaire['now_hour'] ?? 0),
            'trop_tot'       => (bool) ($horaire['trop_tot'] ?? false),
            's'              => $pad($s),
        ];
    }

    /**
     * Libellé court français d'un jour : « Lun 16/10 » (remplace
     * SalesForecast::dayLabel, classe supprimée).
     */
    private static function dayLabelFr(\DateTimeImmutable $date): string
    {
        return self::DAY_ABBR[(int) $date->format('N') - 1] . ' ' . $date->format('j/n');
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

    /**
     * Fenêtre précédente équivalente (même longueur, collée juste avant
     * $from) — mêmes conventions que Analytics::kpisPrevious().
     *
     * @return array{0:string,1:string} [prevFrom, prevTo]
     */
    private function previousWindow(string $from, string $to): array
    {
        try {
            $start = new \DateTimeImmutable($from);
            $end   = new \DateTimeImmutable($to);
        } catch (\Throwable) {
            return [$from, $from];
        }

        $days     = (int) $start->diff($end)->days + 1;
        $prevTo   = $start->modify('-1 day')->format('Y-m-d');
        $prevFrom = $start->modify('-' . $days . ' days')->format('Y-m-d');

        return [$prevFrom, $prevTo];
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
     * Ajoute à chaque KPI son delta vs période précédente ; deltas tous
     * null si la période précédente est incomplète ($deltasAllowed = false,
     * cf. meta.croissance_note) ou à base nulle.
     *
     * @return array<string,mixed>
     */
    private function kpisWithDelta(array $current, array $previous, bool $deltasAllowed = true): array
    {
        $deltas = [];
        foreach (['ca', 'profit', 'qty', 'transactions', 'members', 'avg_basket', 'margin'] as $key) {
            $deltas[$key . '_delta'] = $deltasAllowed
                ? $this->pctDelta((float) ($current[$key] ?? 0), (float) ($previous[$key] ?? 0))
                : null;
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
