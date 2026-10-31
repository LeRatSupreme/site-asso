<?php

declare(strict_types=1);

/**
 * Dashboard Analytics Pro — filtres, KPI, graphiques, heatmap, tableau, insights.
 *
 * Servie dans l'espace admin (connexion) ET en kiosque ADMIN (lien secret,
 * sans connexion) : en mode kiosque, les liens admin sont neutralisés et
 * une nav de retour est affichée en bas de page.
 *
 * @var array<string,mixed> $filters
 * @var array<string,string> $periods
 * @var list<string> $categories
 * @var bool $hasSales
 * @var array<string,mixed> $kpis
 * @var list<array<string,mixed>> $insights
 * @var array<string,mixed> $forecast      Moteur ForecastEngine::forecast() TEL QUEL (prevision_jour, intervalle_80, attendu_a_h, trop_tot, projection_fin_journee, ecart_vs_attendu, dans_intervalle, confiance, modele, n_jours, wape_backtest, hier, jours, horaire, jours_semaine, semaine, mois30, benefice, produits, anomalies, notes…)
 * @var array<string,mixed> $day_track     Estimation du jour (window, hour, trop_tot, estimate, cum_actual, cum_expected, actual_so_far, expected_so_far, delta_pct|null, projection_eod, ecart_eur, intervalle_h|null, dans_intervalle|null, confiance, notes)
 * @var array<string,mixed> $weekday       Par jour de semaine {labels, ca (modèle : attendu prochaine occurrence), lo/hi (bande ~80 %), obs (moyenne brute observée|null), se, next_date, qty, tx, days_count (occ), today_index, best?{index,label,ca,n}|null, creuse_note?string|null}
 * @var array<string,mixed> $hourly        Par heure {labels, ca_avg, peak_hour, today_ca, today_expected, today_projete, now_hour, trop_tot, s}
 * @var array<string,mixed> $calendrier    Contexte calendrier universitaire de la période affichée (CalendarContext::pourPeriode : plage_publiee{debut,fin,stale}, semaines_mortes[{lundi,vendredi,debut_fr,fin_fr,label,hors_horizon}], journee{date,cours,creneaux,prochain_cours,prochain_cours_fr}) — bandeau « vacances » + annotations des graphiques (mission E2).
 * @var array<string,mixed> $reappro_meta  Base énoncée de la carte Réappro {demande,fenetre,creuse,calendrier,stock,date_stock} (ReapproDemande::meta ; « calendrier » non null ⇔ suggestions modulées, facteur < 1).
 * @var float $goal                        Objectif hebdomadaire affiché (GET ?goal= ou sauvegarde POST /admin/analytics/objectif)
 * @var list<string> $products             Produits distincts (sélecteur tendance)
 * @var string $productSelected            Produit suivi par la tendance hebdo
 * @var list<array{week:string,ca:float,qty:int}> $productWeekly Tendance hebdo du produit
 * @var array{ca:float,qty:int} $product_totals            Totaux de la tendance produit (contrôleur, lot 4)
 * @var array{label:?string,pct:?float,total:float} $category_share Part du CA de la 1re catégorie (contrôleur, lot 4)
 * @var array<string,mixed> $payment_share Part carte/liquide + libellés pré-composés (contrôleur, lot 4)
 * @var array{qty:int,ca:float,profit:float,margin_pct:?float} $table_totals Totaux du tableau produit (contrôleur, lot 4)
 * @var array{max:float,day_totals:list<float>} $heatmap_meta Échelle et totaux de la heatmap (contrôleur, lot 4)
 * @var ?float $goal_prob_pct              Probabilité d'atteinte AFFICHÉE (prob × 100 borné, contrôleur, lot 4)
 * @var ?float $hier_ecart                 Écart € réel vs attendu d'hier (contrôleur, lot 4)
 * @var string $croissance_note            Note d'affichage des deltas (contrôleur, lot 4)
 * @var string $activeTab                  Onglet actif de l'espace Ventes ('today'|'analyses'|'annual')
 * @var string $json  Payload JSON injecté dans un îlot <script type="application/json">.
 * @var bool $kiosk   Vrai si rendu dans le layout kiosque (défaut : false).
 * @var string $token Jeton kiosque ADMIN (utilisé si $kiosk).
 */

$kiosk = $kiosk ?? false;

// Lien « Reset » : retour aux filtres par défaut, en conservant l'espace
// (admin ou kiosque avec jeton dans le chemin).
$resetUrl = $kiosk
    ? url('/kiosque/admin/analytics/' . rawurlencode($token))
    : url('/admin/analytics');

$iconSvg = static function (string $name): string {
    $icons = [
        'star'      => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.9 6.3 6.9.6-5.2 4.6 1.6 6.8L12 17.3 5.8 20.9l1.6-6.8L2.2 8.9l6.9-.6z"/></svg>',
        'trend-up'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 8-8M21 7v6h-6"/></svg>',
        'alert'     => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L1 21h22zM12 9v5M12 17.5v.5"/></svg>',
        'clock'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    ];
    return $icons[$name] ?? $icons['star'];
};

// Onglets de l'espace Ventes (partiel partagé avec /admin/compta et le
// bilan annuel — lot 4) : cette page est l'onglet « Analyses ».
$activeTab = $activeTab ?? 'analyses';

// ---- Contexte calendrier universitaire (mission E2) ----
// Bandeau + annotations « vacances » : les données viennent UNIQUEMENT du
// contrôleur (CalendarContext::pourPeriode — contrat documenté dans son
// docblock) ; la vue ne recalcule RIEN du calendrier EDT, elle choisit
// seulement si une semaine morte en cours ou imminente de la période
// mérite un bandeau discret, et la formule avec les libellés du backend
// (aucun nom de vacances en dur).
$calJournee = is_array($calendrier['journee'] ?? null) ? $calendrier['journee'] : [];
$calMortes  = array_values(array_filter(
    is_array($calendrier['semaines_mortes'] ?? null) ? $calendrier['semaines_mortes'] : [],
    'is_array'
));
$calBanner  = null;
$calToday   = (string) ($calJournee['date'] ?? '');
if ($calMortes !== [] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $calToday) === 1) {
    // Lundi de la semaine courante — même convention que CalendarContext
    // (lundi → vendredi) : couvre aussi le samedi/dimanche d'une semaine
    // morte entamée.
    $calLundiCourant = (new DateTimeImmutable($calToday))->modify('monday this week')->format('Y-m-d');
    foreach ($calMortes as $calSem) {
        if (!is_array($calSem)) {
            continue;
        }
        $calLun = (string) ($calSem['lundi'] ?? '');
        if ($calLun === $calLundiCourant) {
            // Vacances EN COURS : la reprise est le prochain jour de cours publié.
            $calReprise = trim((string) ($calJournee['prochain_cours_fr'] ?? ''));
            $calBanner  = $calReprise !== ''
                ? 'Vacances universitaires — reprise des cours le ' . $calReprise
                : "Vacances universitaires — aucune reprise de cours publiée pour l'instant.";
            break;
        }
        if ($calLun !== '' && strcmp($calLun, $calToday) > 0) {
            // Première semaine morte imminente de la période affichée.
            $calBanner = 'Vacances universitaires — semaine sans cours du '
                . (string) ($calSem['debut_fr'] ?? $calLun) . ' au '
                . (string) ($calSem['fin_fr'] ?? (string) ($calSem['vendredi'] ?? ''));
            break;
        }
    }
}
?>
<?php require AEIC_VIEWS . '/partials/_tabs_ventes.php'; ?>

<div class="analytics-page">

    <div class="analytics-head">
        <div>
            <p class="eyebrow">Analyse</p>
            <h1 class="page-title">Dashboard Analytics</h1>
            <p class="muted">
                Période : <strong><?= e(formatDate($filters['from'], 'd/m/Y')) ?></strong>
                → <strong><?= e(formatDate($filters['to'], 'd/m/Y')) ?></strong>
            </p>
        </div>
    </div>

    <?php if ($calBanner !== null): ?>
    <!-- Bandeau contexte « vacances universitaires » (mission E2) :
         composant existant alert-info (discret, informatif), libellés
         100 % issus du backend, rien si pas de contexte pertinent. -->
    <div class="alert alert-info" role="note"><?= e($calBanner) ?></div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!--  Barre de filtres globaux (GET, partageable)                  -->
    <!-- ============================================================ -->
    <form method="get" class="analytics-filters" id="analytics-filters">
        <div class="af-row">
            <div class="af-group">
                <label class="af-label">Période</label>
                <div class="af-pills" id="period-pills">
                    <?php foreach ($periods as $key => $label): ?>
                        <button type="button" class="af-pill <?= $filters['period'] === $key ? 'is-active' : '' ?>" data-period="<?= e($key) ?>"><?= e($label) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="af-row" id="filter-custom" hidden>
            <div class="af-group">
                <label class="af-label">Du</label>
                <input type="date" name="from" value="<?= e($filters['fromInput']) ?>" class="af-input">
                <label class="af-label">Au</label>
                <input type="date" name="to" value="<?= e($filters['toInput']) ?>" class="af-input">
            </div>
        </div>

        <div class="af-row af-row-filters">
            <div class="af-group">
                <label class="af-label">Granularité</label>
                <select name="granularity" class="af-select">
                    <option value="" <?= $filters['granularity'] === '' ? 'selected' : '' ?>>Auto</option>
                    <option value="day" <?= $filters['granularity'] === 'day' ? 'selected' : '' ?>>Jour</option>
                    <option value="week" <?= $filters['granularity'] === 'week' ? 'selected' : '' ?>>Semaine</option>
                    <option value="month" <?= $filters['granularity'] === 'month' ? 'selected' : '' ?>>Mois</option>
                </select>
            </div>

            <div class="af-group">
                <label class="af-label">Catégorie</label>
                <select name="category" class="af-select">
                    <option value="all" <?= $filters['category'] === 'all' ? 'selected' : '' ?>>Toutes</option>
                    <option value="Boisson" <?= $filters['category'] === 'Boisson' ? 'selected' : '' ?>>Boisson</option>
                    <option value="Nourriture" <?= $filters['category'] === 'Nourriture' ? 'selected' : '' ?>>Nourriture</option>
                    <option value="Spécial" <?= $filters['category'] === 'Spécial' ? 'selected' : '' ?>>Spécial</option>
                    <?php foreach ($categories as $c): ?>
                        <?php if (in_array($c, ['Boisson', 'Nourriture', 'Spécial', 'Non classé'], true)) { continue; } ?>
                        <option value="<?= e($c) ?>" <?= $filters['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="af-group">
                <label class="af-label">Paiement</label>
                <select name="payment" class="af-select">
                    <option value="all" <?= $filters['payment'] === 'all' ? 'selected' : '' ?>>Tous</option>
                    <option value="CARTE" <?= $filters['payment'] === 'CARTE' ? 'selected' : '' ?>>Carte</option>
                    <option value="LIQUIDE" <?= $filters['payment'] === 'LIQUIDE' ? 'selected' : '' ?>>Liquide</option>
                </select>
            </div>

            <div class="af-actions">
                <button type="submit" class="btn btn-primary">Appliquer</button>
                <a class="btn btn-ghost" href="<?= e($resetUrl) ?>">Reset</a>
            </div>
        </div>
        <input type="hidden" name="period" id="period-hidden" value="<?= e($filters['period']) ?>">
    </form>

    <?php if (!$hasSales): ?>
        <p class="card-meta" style="margin-top:1rem">
            Aucune vente importée — les graphiques restent vides jusqu'au premier import
            <?php if ($kiosk): ?>
                (import d'un CSV SumUp : depuis l'espace admin sur PC).
            <?php else: ?>
                (<a href="<?= e(url('/admin/compta/import')) ?>">Importer un CSV SumUp</a>).
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!--  KPI Cards                                                     -->
    <!-- ============================================================ -->
    <div class="grid grid-6 kpi-grid">
        <?php
        // Note de croissance du moteur (variable du contrôleur, lot 4) :
        // si la période précédente est incomplète, les deltas sont null
        // côté payload — on affiche la note, SANS recomposer de delta.
        $croissanceNote = trim((string) ($croissance_note ?? ''));

        $kpiCards = [
            ['label' => "Chiffre d'affaires", 'value' => formatPrice($kpis['ca']),          'delta' => $kpis['ca_delta'] ?? null],
            ['label' => 'Bénéfice net',       'value' => formatPrice($kpis['profit']),
             'sub'   => 'Marge ' . number_format($kpis['margin'], 1, ',', ' ') . '%',       'delta' => $kpis['profit_delta'] ?? null],
            ['label' => 'Volume vendu',       'value' => number_format((float) $kpis['qty'], 0, ',', ' ') . ' u.',
                                                                                                   'delta' => $kpis['qty_delta'] ?? null],
            ['label' => 'Panier moyen',       'value' => formatPrice($kpis['avg_basket']),  'delta' => $kpis['avg_basket_delta'] ?? null],
            ['label' => 'Transactions',       'value' => number_format((int) $kpis['transactions'], 0, ',', ' '),
                                                                                                   'delta' => $kpis['transactions_delta'] ?? null],
            ['label' => 'Nouveaux membres',   'value' => number_format((int) $kpis['members'], 0, ',', ' '),
                                                                                                   'delta' => $kpis['members_delta'] ?? null],
        ];
        foreach ($kpiCards as $kpi):
            $delta = $kpi['delta'];
            $up    = $delta === null ? null : ($delta >= 0);
        ?>
            <div class="card surface glass kpi-card">
                <span class="kpi-label"><?= e($kpi['label']) ?></span>
                <span class="kpi-value"><?= e($kpi['value']) ?></span>
                <?php if (!empty($kpi['sub'])): ?>
                    <span class="kpi-sub"><?= e($kpi['sub']) ?></span>
                <?php endif; ?>
                <?php if ($up !== null): ?>
                    <span class="kpi-delta <?= $up ? 'is-up' : 'is-down' ?>">
                        <?= $up ? '↑' : '↓' ?> <?= number_format(abs($delta), 1, ',', ' ') ?>%
                    </span>
                <?php else: ?>
                    <span class="kpi-delta is-muted"><?= $croissanceNote !== '' ? e($croissanceNote) : '—' ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ============================================================ -->
    <!--  Graphiques                                                    -->
    <!-- ============================================================ -->
    <div class="analytics-grid analytics-grid-2">
        <div class="card surface glass chart-card chart-span-2">
            <div class="chart-head">
                <h2 class="chart-title">Le chiffre d'affaires et le bénéfice, période par période</h2>
                <span class="chart-sub" id="trend-sub"></span>
            </div>
            <?php if ($calMortes !== []): ?>
                <?php
                // Légende courte des semaines mortes de la période (mission
                // E2) : dates du backend (debut_fr/fin_fr) ; le marquage
                // (barres + libellés grisés, script en bas de page) n'existe
                // qu'aux granularités jour/semaine — un mois CONTENANT une
                // semaine morte n'est pas « mort ».
                $calNote = 'Semaines sans cours : ' . implode(' · ', array_map(
                    static fn (array $s): string => (string) ($s['debut_fr'] ?? '') . '–' . (string) ($s['fin_fr'] ?? ''),
                    $calMortes
                )) . (in_array($filters['granularity'], ['day', 'week'], true) ? ' — barres et libellés grisés' : '');
                ?>
                <span class="chart-sub fc-note"><?= e($calNote) ?></span>
            <?php endif; ?>
            <div class="chart-canvas"><canvas id="chart-trend" height="120"></canvas></div>
        </div>

        <?php
        // Liens de fenêtre : GET courant (période, catégorie, paiement,
        // granularité, produit…) avec seul « fw » surchargé. Partagé par
        // les cartes « Prévisions » et « Estimation du jour » : une seule
        // et même fenêtre d'apprentissage pour toutes les prévisions.
        $token = $token ?? '';
        $dtUrl = static function (int $w) use ($kiosk, $token): string {
            $params       = $_GET;
            $params['fw'] = (string) $w;
            $path = $kiosk
                ? '/kiosque/admin/analytics/' . rawurlencode($token)
                : '/admin/analytics';

            return url($path) . '?' . http_build_query($params);
        };

        // ---- Données du moteur (contrat ForecastEngine::forecast()) ----
        // Source unique de vérité : AUCUN chiffre « attendu » recalculé
        // ici ni en JS, tout vient du payload.
        $fcFw       = (int) ($day_track['window'] ?? 30);
        $fcPrev     = isset($forecast['prevision_jour']) ? $forecast['prevision_jour'] : null;
        $fcIv       = isset($forecast['intervalle_80']) && is_array($forecast['intervalle_80']) ? $forecast['intervalle_80'] : null;
        $fcHier     = isset($forecast['hier']) && is_array($forecast['hier']) ? $forecast['hier'] : null;
        $fcConf     = (string) ($forecast['confiance'] ?? 'insuffisant');
        $fcModele   = (string) ($forecast['modele'] ?? '');
        $fcNJours   = (int) ($forecast['n_jours'] ?? 0);
        $fcWape     = isset($forecast['wape_backtest']) ? $forecast['wape_backtest'] : null;
        $fcCoverage = isset($forecast['coverage_backtest']) ? $forecast['coverage_backtest'] : null;
        $fcHor      = isset($forecast['horaire']) && is_array($forecast['horaire']) ? $forecast['horaire'] : [];
        $fcNowHour  = (int) ($fcHor['now_hour'] ?? 0);
        $fcTropTot  = !empty($fcHor['trop_tot']) || !empty($forecast['trop_tot']);
        $fcDans     = isset($forecast['dans_intervalle']) ? $forecast['dans_intervalle'] : null;
        $fcEcart    = isset($forecast['ecart_vs_attendu']) ? $forecast['ecart_vs_attendu'] : null;
        $fcNoteSel  = (string) ($forecast['anomalies']['note_selection'] ?? '');
        $fcInsuf    = ($fcConf === 'insuffisant') || !empty($forecast['anomalies']['donnees_insuffisantes']);

        // Helpers d'affichage partagés par les chips (prévisions/estimation).
        // $label distingue les FOURCHETTES : « fourchette journée 80 % »
        // (prévision pleine journée) vs « fourchette » (à l'heure courante).
        $fcRange = static function (?array $iv, string $label = 'fourchette'): string {
            if ($iv === null || !isset($iv['lo'], $iv['hi']) || $iv['lo'] === null || $iv['hi'] === null) { return ''; }
            return ' (' . $label . ' ' . formatPrice((float) $iv['lo']) . '–' . formatPrice((float) $iv['hi']) . ')';
        };
        $fcSigned = static function ($v): string {
            return $v === null ? '' : (($v >= 0 ? '+' : '-') . formatPrice(abs((float) $v)));
        };
        // Neutralité : DANS la fourchette → statut neutre ; HORS fourchette
        // → anomalie ambre ; null → pas de statut affiché.
        $fcStatut = static function ($dans): array {
            if ($dans === true)  { return ['Dans la fourchette ✓', 'is-muted']; }
            if ($dans === false) { return ['Hors fourchette', 'is-off']; }
            return ['', ''];
        };
        $fcConfLbl = ['elevee' => 'élevée', 'moyenne' => 'moyenne', 'faible' => 'faible'][$fcConf] ?? 'insuffisante';
        $fcFacteur = isset($forecast['facteur_conforme']) && is_numeric($forecast['facteur_conforme'])
            ? (float) $forecast['facteur_conforme'] : null;

        // Info-bulle métriques du modèle (affichée sur les deux cartes).
        // La correction conforme explique la largeur des fourchettes
        // (résidus de backtest × demi-largeur Monte Carlo). Niveau et
        // tendance viennent du bloc debug du moteur (échelles documentées :
        // niveau = € par jour moyen désaisonnalisé, tendance = %/jour).
        $fcDebug    = isset($forecast['debug']) && is_array($forecast['debug']) ? $forecast['debug'] : [];
        $fcNiveau   = isset($fcDebug['niveau_eur']) && is_numeric($fcDebug['niveau_eur']) ? (float) $fcDebug['niveau_eur'] : null;
        $fcTendance = isset($fcDebug['tendance_pct_jour']) && is_numeric($fcDebug['tendance_pct_jour']) ? (float) $fcDebug['tendance_pct_jour'] : null;
        // Porte d'activation de la tendance (debug du moteur) : sous
        // 30 jours ouverts elle est désactivée (b forcé à 0) — on affiche
        // la mention explicite au lieu d'un « Tendance ≈ 0,0 %/jour ».
        // Absent du debug (payload ancien) → affichage historique du %.
        $fcTrendActive = array_key_exists('tendance_active', $fcDebug) ? (bool) $fcDebug['tendance_active'] : null;
        // WAPE détaillé : modèle (main) vs baseline B2 — explique la
        // sélection (« pourquoi baseline_B2 ? ») au lieu d'un seul chiffre.
        $fcAnom       = isset($forecast['anomalies']) && is_array($forecast['anomalies']) ? $forecast['anomalies'] : [];
        $fcWapeModele = isset($fcAnom['wape_modele']) && is_numeric($fcAnom['wape_modele']) ? (float) $fcAnom['wape_modele'] : null;
        $fcWapeBasis  = isset($fcAnom['wape_baseline']) && is_numeric($fcAnom['wape_baseline']) ? (float) $fcAnom['wape_baseline'] : null;
        $fcWapeTxt = $fcWape === null
            ? 'WAPE backtest : —'
            : ($fcModele === 'baseline_B2' && $fcWapeBasis !== null
                ? 'WAPE modèle ' . number_format((float) ($fcWapeModele ?? $fcWape), 1, ',', ' ')
                    . ' % vs baseline B2 ' . number_format($fcWapeBasis, 1, ',', ' ')
                    . ' % → baseline retenue (règle : le modèle actif doit battre la naïve)'
                : 'WAPE modèle ' . number_format((float) $fcWape, 1, ',', ' ') . ' %'
                    . ($fcWapeBasis !== null ? ' (bat la baseline ' . number_format($fcWapeBasis, 1, ',', ' ') . ' %)' : ''));
        // Dispersion NegBin : valeur + mention de la borne R_MAX quand
        // l'estimateur a saturé (r = 200 ≈ Poisson — pas un choix).
        $fcR      = isset($fcDebug['dispersion_r']) && is_numeric($fcDebug['dispersion_r']) ? (float) $fcDebug['dispersion_r'] : null;
        $fcRBorne = array_key_exists('dispersion_r_borne', $fcDebug) ? (bool) $fcDebug['dispersion_r_borne'] : null;
        $fcRTxt = $fcR !== null
            ? ' · dispersion r = ' . number_format($fcR, 0, ',', ' ') . ($fcRBorne === true ? ' (borne quasi-Poisson)' : '')
            : '';
        $fcTooltip = 'Modèle actif : ' . ($fcModele !== '' ? $fcModele : '—')
            . ' · ' . $fcWapeTxt
            . ' sur ' . $fcNJours . ' jours'
            . ' · couverture intervalle : ' . ($fcCoverage === null ? '—' : number_format((float) $fcCoverage, 0, ',', ' ')) . ' %'
            . ($fcFacteur !== null ? ' · Correction conforme : ×' . number_format($fcFacteur, 1, ',', ' ') : '')
            . ($fcNiveau !== null ? ' · Niveau ≈ ' . number_format($fcNiveau, 0, ',', ' ') . ' €' : '')
            . $fcRTxt
            . ($fcTrendActive === false
                ? ' · Tendance désactivée (historique < 6 semaines)'
                : ($fcTendance !== null ? ' · Tendance ≈ ' . number_format($fcTendance, 1, ',', ' ') . ' %/jour' : ''))
            . ($fcNoteSel !== '' ? ' · ' . $fcNoteSel : '');
        // Lot 4 (jargon) : les métriques du modèle (WAPE, dispersion,
        // couverture) ne vivent PLUS en info-bulle mais DANS la section
        // repliée « Détails techniques » en bas de page ; l'info-bulle
        // des cartes renvoie simplement vers cette section.
        $fcInfoTxt = 'Fiabilité du modèle, fourchettes et notes : voir la section « Détails techniques » en bas de page.';

        $fcHasChart = $fcPrev !== null || !empty($forecast['jours']);

        // Chips « Aujourd'hui » / « Hier » : montants, fourchettes et
        // statuts viennent du payload. Pas de delta recomposé ; écart en
        // € masqué si trop tôt (règle 3) ou données insuffisantes (règle 4).
        // Jour fermé (prevision null + note du moteur) : JAMAIS « 0,00 € ».
        $fcFerme = $fcPrev === null
            && in_array("Fermé aujourd'hui.", (array) ($forecast['notes'] ?? []), true);
        $fcChips = [];
        if ($fcPrev !== null) {
            // Fourchette JOURNÉE 80 % explicitement étiquetée, pour ne pas
            // être confondue avec la fourchette à l'heure courante de la
            // carte « Estimation du jour ».
            $txt = "Aujourd'hui : attendu " . formatPrice((float) $fcPrev) . $fcRange($fcIv, 'fourchette journée 80 % :');
            if (!$fcTropTot && !$fcInsuf && $fcEcart !== null) {
                $txt .= ' · vs attendu à ' . $fcNowHour . 'h : ' . $fcSigned($fcEcart);
            }
            $fcChips[] = ['cls' => 'is-muted', 'text' => $txt];
        } elseif ($fcFerme) {
            $fcChips[] = ['cls' => 'is-muted', 'text' => "Fermé aujourd'hui"];
        }
        if ($fcTropTot) {
            $fcChips[] = ['cls' => 'is-muted', 'text' => 'Trop tôt pour comparer (profil < 10 % de la journée)'];
        } elseif ($fcDans !== null) {
            [$stTxt, $stCls] = $fcStatut((bool) $fcDans);
            $fcChips[] = ['cls' => $stCls, 'text' => $stTxt];
        }
        if ($fcHier !== null && isset($fcHier['prevu']) && $fcHier['prevu'] !== null) {
            $hIv = (isset($fcHier['lo'], $fcHier['hi']) && $fcHier['lo'] !== null && $fcHier['hi'] !== null)
                ? ['lo' => $fcHier['lo'], 'hi' => $fcHier['hi']] : null;
            $txt = 'Hier : ' . formatPrice((float) ($fcHier['reel'] ?? 0)) . ' vs ' . formatPrice((float) $fcHier['prevu']);
            if (($hier_ecart ?? null) !== null) {
                $txt .= ' (' . $fcSigned($hier_ecart) . ')';
            }
            $txt .= $fcRange($hIv);
            $fcChips[] = ['cls' => 'is-muted', 'text' => $txt];
            if (isset($fcHier['dans_intervalle']) && $fcHier['dans_intervalle'] !== null) {
                [$stTxt, $stCls] = $fcStatut((bool) $fcHier['dans_intervalle']);
                $fcChips[] = ['cls' => $stCls, 'text' => 'Hier — ' . $stTxt];
            }
        }
        ?>
        <div class="card surface glass chart-card chart-span-2">
            <div class="chart-head">
                <div>
                    <h2 class="chart-title">À quoi ressemblent les prochains jours<span class="fc-info" title="<?= e($fcInfoTxt) ?>" aria-label="<?= e($fcInfoTxt) ?>">ⓘ</span></h2>
                    <span class="chart-sub">Fenêtre d'apprentissage : <?= $fcFw ?> derniers jours</span>
                </div>
                <div class="af-pills dt-pills">
                    <?php foreach ([7 => '7 j', 14 => '14 j', 30 => '30 j'] as $w => $lbl): ?>
                        <a class="af-pill <?= $fcFw === $w ? 'is-active' : '' ?>" href="<?= e($dtUrl($w)) ?>"><?= e($lbl) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="fc-chips dt-chips">
                <?php foreach ($fcChips as $chip): ?>
                    <span class="kpi-delta <?= e($chip['cls']) ?>"><?= e($chip['text']) ?></span>
                <?php endforeach; ?>
                <?php if ($fcModele === 'baseline_B2'): ?>
                    <span class="kpi-delta is-off">Prévision simplifiée active : l'historique récent ne permet pas d'utiliser le modèle avancé</span>
                <?php endif; ?>
                <?php if ($fcInsuf): ?>
                    <span class="kpi-delta is-off">Données insuffisantes (&lt; 7 jours ouverts)</span>
                <?php endif; ?>
            </div>
            <?php if (!$fcHasChart): ?>
                <p class="muted fc-empty">Prévisions indisponibles : aucun jour d'historique écoulé sur la fenêtre.</p>
            <?php else: ?>
                <span class="chart-sub fc-note">Fiabilité du modèle : <?= e($fcConfLbl) ?> · <?= $fcNJours ?> jours ouverts inclus dans l'apprentissage</span>
                <div class="chart-canvas"><canvas id="chart-forecast" height="120"></canvas></div>
            <?php endif; ?>
        </div>

        <?php
        // Carte « Estimation du jour » : suivi live du jour sur la fenêtre
        // d'apprentissage commune (7/14/30 j, GET « fw »). Toutes les
        // valeurs viennent du payload $day_track — delta et fourchette
        // peuvent être null (données insuffisantes / trop tôt) : on
        // n'affiche alors NI l'un NI l'autre.
        // F1 : l'attendu affiché est celui À L'HEURE COURANTE
        // (expected_so_far + intervalle_h), clairement distinct de la
        // fourchette journée 80 % de la carte Prévisions.
        $dtEst   = isset($day_track['estimate']) ? $day_track['estimate'] : null;
        $dtExp   = isset($day_track['expected_so_far']) ? $day_track['expected_so_far'] : null;
        $dtFw    = (int) ($day_track['window'] ?? 30);
        $dtHour  = (int) ($day_track['hour'] ?? ($fcHor['now_hour'] ?? 0));
        $dtTrop  = !empty($day_track['trop_tot']);
        $dtIvH   = isset($day_track['intervalle_h']) && is_array($day_track['intervalle_h']) ? $day_track['intervalle_h'] : null;
        $dtDans  = isset($day_track['dans_intervalle']) ? $day_track['dans_intervalle'] : null;
        $dtDelta = isset($day_track['delta_pct']) ? $day_track['delta_pct'] : null;
        $dtEcart = isset($day_track['ecart_eur']) ? $day_track['ecart_eur'] : null;
        $dtProj  = isset($day_track['projection_eod']) ? $day_track['projection_eod'] : null;
        $dtReel  = isset($day_track['actual_so_far']) ? $day_track['actual_so_far'] : null;
        $dtConf  = (string) ($day_track['confiance'] ?? 'insuffisant');
        $dtInsuf = ($dtConf === 'insuffisant') || !empty($forecast['anomalies']['donnees_insuffisantes']);
        $dtHasEst = $dtEst !== null && (float) $dtEst > 0;
        ?>
        <div class="card surface glass chart-card chart-span-2">
            <div class="chart-head">
                <div>
                    <h2 class="chart-title">La journée en cours comparée à un jour habituel<span class="fc-info" title="<?= e($fcInfoTxt) ?>" aria-label="<?= e($fcInfoTxt) ?>">ⓘ</span></h2>
                    <span class="chart-sub">Fenêtre d'apprentissage : <?= $dtFw ?> derniers jours</span>
                </div>
                <div class="af-pills dt-pills">
                    <?php foreach ([7 => '7 j', 14 => '14 j', 30 => '30 j'] as $w => $lbl): ?>
                        <a class="af-pill <?= $dtFw === $w ? 'is-active' : '' ?>" href="<?= e($dtUrl($w)) ?>"><?= e($lbl) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (!$dtHasEst): ?>
                <p class="muted fc-empty"><?= $fcFerme ? "Fermé aujourd'hui" : 'Pas assez d\'historique sur la fenêtre.' ?></p>
            <?php else: ?>
                <div class="fc-chips dt-chips">
                    <?php if ($dtExp !== null): ?>
                        <span class="kpi-delta is-muted">Attendu à <?= $dtHour ?>h : <?= e(formatPrice((float) $dtExp) . $fcRange($dtIvH)) ?></span>
                    <?php else: ?>
                        <span class="kpi-delta is-muted">Attendu aujourd'hui : <?= e(formatPrice((float) $dtEst) . $fcRange($dtIvH)) ?></span>
                    <?php endif; ?>
                    <span class="kpi-delta is-muted">Réel à <?= $dtHour ?>h : <?= e(formatPrice((float) ($dtReel ?? 0))) ?></span>
                    <?php if ($dtTrop): ?>
                        <span class="kpi-delta is-muted">Trop tôt pour comparer (profil &lt; 10 % de la journée)</span>
                    <?php elseif (!$dtInsuf && $dtEcart !== null): ?>
                        <span class="kpi-delta is-muted">vs attendu à <?= $dtHour ?>h : <?= e($fcSigned($dtEcart)) ?><?= $dtExp !== null ? ' (attendu ' . e(formatPrice((float) $dtExp)) . ')' : '' ?></span>
                    <?php elseif (!$dtInsuf && $dtDelta !== null): ?>
                        <span class="kpi-delta is-muted">vs attendu à <?= $dtHour ?>h : <?= $dtDelta >= 0 ? '+' : '-' ?><?= e(number_format(abs((float) $dtDelta), 1, ',', ' ')) ?> % (attendu <?= $dtExp === null ? '—' : e(formatPrice((float) $dtExp)) ?>)</span>
                    <?php endif; ?>
                    <?php if (!$dtTrop && $dtDans !== null): ?>
                        <?php [$stTxt, $stCls] = $fcStatut((bool) $dtDans); ?>
                        <span class="kpi-delta <?= e($stCls) ?>"><?= e($stTxt) ?></span>
                    <?php endif; ?>
                    <?php if ($dtProj !== null): ?>
                        <span class="kpi-delta is-muted">Projection fin de journée : <?= e(formatPrice((float) $dtProj)) ?></span>
                    <?php endif; ?>
                    <?php if ($dtInsuf): ?>
                        <span class="kpi-delta is-off">Données insuffisantes (&lt; 7 jours ouverts)</span>
                    <?php endif; ?>
                </div>
                <div class="chart-canvas"><canvas id="chart-daytrack" height="120"></canvas></div>
            <?php endif; ?>
        </div>

        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title">Les produits qui font le chiffre d'affaires</h2>
                <span class="chart-sub">Top 10 · cliquer pour le détail</span>
            </div>
            <div class="chart-canvas"><canvas id="chart-top" height="160"></canvas></div>
        </div>

        <?php
        // Titre-conclusion du donut catégorie (part calculée côté
        // contrôleur, lot 4) : « Boisson pèse 62 % du chiffre d'affaires ».
        $catSharePct = $category_share['pct'] ?? null;
        $catShareTxt = $catSharePct === null ? '' : rtrim(rtrim(number_format((float) $catSharePct, 1, ',', ' '), '0'), ',');
        ?>
        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title"><?= ($category_share['label'] ?? null) !== null && $catShareTxt !== ''
                    ? e((string) $category_share['label']) . ' pèse ' . e($catShareTxt) . ' % du chiffre d&#039;affaires'
                    : 'Répartition du chiffre d&#039;affaires par catégorie' ?></h2>
                <span class="chart-sub">CA par catégorie</span>
            </div>
            <div class="chart-canvas chart-canvas-square"><canvas id="chart-category"></canvas></div>
        </div>

        <?php
        // Titre-conclusion du donut paiements (part carte calculée côté
        // contrôleur, lot 4) ; « Répartition des paiements » sans vente.
        $payPctCarte = $payment_share['pct_carte'] ?? null;
        ?>
        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title"><?= $payPctCarte !== null
                    ? 'La carte fait ' . e((string) $payPctCarte) . ' % des encaissements'
                    : 'Répartition des paiements' ?></h2>
                <span class="chart-sub">CA · transactions</span>
            </div>
            <div class="chart-canvas chart-canvas-square"><canvas id="chart-payment"></canvas></div>
        </div>

        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title">Le rythme des ventes au fil de la journée</h2>
                <span class="chart-sub" id="hourly-sub"><?php
                // Sous-titre 100 % serveur (une seule source : le payload du
                // moteur). « Attendu aujourd'hui » = JOURNÉE (prevision_jour +
                // intervalle_80) ; la partie horaire reste DISTINCTE :
                // « Attendu à Nh » depuis day_track.expected_so_far /
                // intervalle_h, ou « — (trop tôt) » quand le profil horaire
                // couvre < 10 % de la journée (attendu horaire masqué).
                // Jour fermé → « Fermé aujourd'hui », jamais « 0,00 € ».
                // Heure de pointe : argmax calculé côté contrôleur
                // (hourly.peak_hour, lot 4).
                $phPrev  = isset($forecast['prevision_jour']) ? $forecast['prevision_jour'] : null;
                $phIv    = isset($forecast['intervalle_80']) && is_array($forecast['intervalle_80']) ? $forecast['intervalle_80'] : null;
                $phFerme = $phPrev === null
                    && in_array("Fermé aujourd'hui.", (array) ($forecast['notes'] ?? []), true);
                $phH    = (int) ($day_track['hour'] ?? 0);
                $phTrop = !empty($day_track['trop_tot']);
                $phExp  = isset($day_track['expected_so_far']) ? $day_track['expected_so_far'] : null;
                $phIvH  = isset($day_track['intervalle_h']) && is_array($day_track['intervalle_h']) ? $day_track['intervalle_h'] : null;
                $phPeak = isset($hourly['peak_hour']) ? $hourly['peak_hour'] : null;
                $phParts = ['Heure de pointe : ' . ($phPeak === null ? '—' : $phPeak . 'h') . ' (observé)'];
                if ($phPrev !== null) {
                    $phParts[] = "Attendu aujourd'hui : " . formatPrice((float) $phPrev) . $fcRange($phIv);
                    if ($phTrop) {
                        $phParts[] = 'Attendu à ' . $phH . 'h : — (trop tôt)';
                    } elseif ($phExp !== null) {
                        $phParts[] = 'Attendu à ' . $phH . 'h : ' . formatPrice((float) $phExp) . $fcRange($phIvH);
                    }
                } elseif ($phFerme) {
                    $phParts[] = "Fermé aujourd'hui";
                }
                echo e(implode(' · ', $phParts));
                ?></span>
            </div>
            <div class="chart-canvas"><canvas id="chart-hourly" height="160"></canvas></div>
        </div>

        <?php
        // ---- Carte « Objectif » : semaine en cours + horizon 30 j ----
        // Valeurs du moteur (forecast.semaine / mois30) et de $goal.
        // La sauvegarde POSTe champ « goal » + CSRF vers
        // /admin/analytics/objectif (motif csrf_field() du repo).
        $sem     = isset($forecast['semaine']) && is_array($forecast['semaine']) ? $forecast['semaine'] : [];
        $goalVal = (float) ($goal ?? ($sem['objectif'] ?? 0));
        $semAtt  = $sem['attendu'] ?? null;
        $semIv   = (($sem['lo'] ?? null) !== null && ($sem['hi'] ?? null) !== null)
            ? ['lo' => $sem['lo'], 'hi' => $sem['hi']] : null;
        $semReel = $sem['reel'] ?? null;
        $semManq = $sem['manquant_par_jour'] ?? null;
        $semRest = (int) ($sem['jours_restants'] ?? 0);
        $m30     = isset($forecast['mois30']) && is_array($forecast['mois30']) ? $forecast['mois30'] : [];
        $m30Att  = $m30['attendu'] ?? null;
        $m30Iv   = (($m30['lo'] ?? null) !== null && ($m30['hi'] ?? null) !== null)
            ? ['lo' => $m30['lo'], 'hi' => $m30['hi']] : null;
        // Libellé honnête : fenêtre de 30 jours CALENDAIRES (debut → fin)
        // avec ses occurrences de vente (jours ouverts, dont période
        // creuse) — l'ancien « Horizon 30 jours » masquait une boucle de
        // 30 jours OUVERTS ≈ 42 dates. Repli sur le libellé simple si le
        // payload est ancien (sans debut/fin).
        $m30Lbl = 'Horizon 30 jours';
        if (isset($m30['debut'], $m30['fin'], $m30['jours_ouverts'])) {
            try {
                $debut30 = (new \DateTimeImmutable((string) $m30['debut']))->format('d/m');
                $fin30   = (new \DateTimeImmutable((string) $m30['fin']))->format('d/m');
                $m30Lbl  = 'Horizon 30 jours (du ' . $debut30 . ' au ' . $fin30
                    . ' · ' . (int) $m30['jours_ouverts'] . ' jours ouverts'
                    . (isset($m30['jours_creuse']) && (int) $m30['jours_creuse'] > 0
                        ? ', dont ' . (int) $m30['jours_creuse'] . ' en période creuse' : '')
                    . ')';
            } catch (\Throwable) {
                // dates invalides : libellé simple
            }
        }
        // prob_atteinte est une FRACTION 0–1 (P(total ≥ objectif)) : le
        // moteur ne renvoie jamais 0–100 — le ×100 (borné) est fait UNE
        // fois côté contrôleur (goal_prob_pct, lot 4) ; ici l'affichage.
        $probPct = $goal_prob_pct ?? null;
        $probFmt = $probPct === null ? '' : number_format($probPct, $probPct < 10 ? 1 : 0, ',', ' ');
        ?>
        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title">Objectif</h2>
                <span class="chart-sub">semaine en cours</span>
            </div>
            <div class="goal-body">
                <div class="goal-prob">
                    <span class="goal-prob-value"><?= $probPct === null ? '—' : e($probFmt) . ' %' ?></span>
                    <span class="goal-prob-label">Probabilité d'atteindre l'objectif hebdo</span>
                    <div class="goal-prob-bar" role="img" aria-label="<?= $probPct === null ? 'Probabilité indisponible' : 'Probabilité d\'atteinte ' . e($probFmt) . ' %' ?>">
                        <div class="goal-prob-fill" style="width:<?= $probPct === null ? 0 : (int) round($probPct) ?>%"></div>
                    </div>
                </div>
                <ul class="goal-list">
                    <li><span>Objectif hebdo</span><strong><?= e(formatPrice($goalVal)) ?></strong></li>
                    <li><span>Déjà encaissé cette semaine</span><strong><?= $semReel === null ? '—' : e(formatPrice((float) $semReel)) ?></strong></li>
                    <li><span>Attendu fin de semaine</span><strong><?= $semAtt === null ? '—' : e(formatPrice((float) $semAtt) . $fcRange($semIv)) ?></strong></li>
                    <?php
                    // FIX F : « Requis/jour » = rythme pour VISER la cible
                    // (objectif − réel)/jours restants, TOUJOURS affiché
                    // quand le réel est sous l'objectif — même si le
                    // modèle prévoit d'atteindre. Le « manque attendu »
                    // (modèle) ne s'affiche que si attendu < objectif.
                    $semReq = $sem['requis_par_jour'] ?? null;
                    $joursLbl = static fn (int $n): string => $n . ' jour' . ($n > 1 ? 's' : '') . ' restant' . ($n > 1 ? 's' : '');
                    ?>
                    <?php if ($semReq !== null && (float) $semReq > 0.0 && $semReel !== null && (float) $semReel < $goalVal): ?>
                        <li><span>Requis/jour pour viser l'objectif</span><strong><?= e(formatPrice((float) $semReq)) ?> (<?= $joursLbl($semRest) ?>)</strong></li>
                    <?php endif; ?>
                    <?php if ($semManq !== null && (float) $semManq > 0.0 && $semRest > 0): ?>
                        <li><span>Manque attendu par jour restant</span><strong><?= e(formatPrice((float) $semManq)) ?> (<?= $joursLbl($semRest) ?>)</strong></li>
                    <?php endif; ?>
                    <li><span><?= e($m30Lbl) ?></span><strong><?= $m30Att === null ? '—' : e(formatPrice((float) $m30Att) . $fcRange($m30Iv)) ?></strong></li>
                </ul>
                <?php if (!$kiosk): ?>
                    <?php
                    // Période creuse saisie à la main (calendrier non
                    // apprenable avec < 12 semaines : Toussaint, hiver…).
                    $creuseRaw = (string) ($periode_creuse['raw'] ?? '');
                    $creuseActives = is_array($periode_creuse['actives'] ?? null) ? $periode_creuse['actives'] : [];
                    ?>
                    <?php if ($creuseActives !== []): ?>
                        <p class="chart-sub" style="margin:-0.4rem 0 0">
                            Période creuse active : <?= e(implode(' · ', $creuseActives)) ?> — prévisions ajustées en conséquence.
                        </p>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url('/admin/analytics/objectif')) ?>" class="goal-form">
                        <?= csrf_field() ?>
                        <label class="af-label" for="goal-input">Objectif hebdomadaire (€)</label>
                        <input type="number" name="goal" id="goal-input" min="0" step="0.01" required
                               value="<?= e(number_format($goalVal, 2, '.', '')) ?>" class="af-input">
                        <label class="af-label" for="creuse-input">Période creuse</label>
                        <input type="text" name="periode_creuse" id="creuse-input" class="af-input" style="min-width:230px"
                               placeholder="0.8;2026-10-17/2026-11-02" value="<?= e($creuseRaw) ?>"
                               title="Facteur;début/fin — ex. 0.8;2026-10-17/2026-11-02 (facteur 0,1 à 1, 10 plages max ; vide = aucune)">
                        <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php
        // ---- Carte « Idées du moment » (moteur de recommandations) : ----
        // partiel partagé avec la vue kiosque ; $reco vient du payload
        // buildPage ('reco'), $kiosk/$token sont déjà dans le scope.
        // Toute panne est déjà avalée côté contrôleur : le partiel rend
        // au pire « Recommandations indisponibles ».
        require AEIC_VIEWS . '/admin/reco/carte.php';
        ?>

        <?php
        // ---- Carte « Bénéfice prévu » : marge à 7 jours par catégorie ----
        // F7 : la marge PRÉVISIONNELLE utilise le prix MÉDIAN de la fenêtre
        // moins le coût ACTUEL (dernier lot) — étiquetée comme telle et
        // distincte de la marge OBSERVÉE du tableau « Détail par produit »
        // (coût à la date de vente).
        $benef      = isset($forecast['benefice']) && is_array($forecast['benefice']) ? $forecast['benefice'] : [];
        $benefCat   = isset($benef['par_categorie']) && is_array($benef['par_categorie']) ? $benef['par_categorie'] : [];
        $benefTot   = $benef['total_marge_7j'] ?? null;
        $benefPctNc = isset($benef['pct_ca_sans_cout_fiable']) ? (float) $benef['pct_ca_sans_cout_fiable'] : 0.0;
        $benefNote  = (string) ($benef['note'] ?? '');
        $benefSus   = isset($benef['suspects']) && is_array($benef['suspects']) ? $benef['suspects'] : [];
        $benefTip   = 'Le coût ACTUEL du dernier lot peut différer du coût historique : la marge prévisionnelle reflète les conditions d\'aujourd\'hui.';
        ?>
        <div class="card surface glass chart-card" title="<?= e($benefTip) ?>">
            <div class="chart-head">
                <h2 class="chart-title">Bénéfice prévu</h2>
                <span class="chart-sub">Marge prévisionnelle (prix médian fenêtre − coût actuel)</span>
            </div>
            <p class="benef-total">
                <span class="kpi-value"><?= $benefTot === null ? '—' : e(formatPrice((float) $benefTot)) ?></span>
                <span class="chart-sub">de marge prévue — horizon 7 jours ouverts</span>
            </p>
            <?php if ($benefPctNc > 0 && $benefNote !== ''): ?>
                <p class="fc-banner"><?= e($benefNote) ?></p>
            <?php endif; ?>
            <?php if ($benefCat !== []): ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr><th>Catégorie</th><th class="num">CA prévu</th><th class="num">Marge</th><th class="num">Marge %</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($benefCat as $cat => $row): ?>
                                <tr>
                                    <td><?= e((string) $cat) ?></td>
                                    <td class="num"><?= e(formatPrice((float) ($row['ca'] ?? 0))) ?></td>
                                    <td class="num"><?= e(formatPrice((float) ($row['marge'] ?? 0))) ?></td>
                                    <td class="num"><?= e(number_format((float) ($row['marge_pct'] ?? 0), 1, ',', ' ')) ?> %</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted fc-empty">Marge indisponible (coûts produits incomplets).</p>
            <?php endif; ?>
            <?php if ($benefSus !== []): ?>
                <ul class="benef-suspects">
                    <?php foreach ($benefSus as $s): ?>
                        <li><strong><?= e((string) ($s['label'] ?? '')) ?></strong> — <?= e((string) ($s['raison'] ?? '')) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php
        // ---- Carte « Réapprovisionnement » : besoins estimés à 7 j ----
        // Tri par suggestion décroissante déjà fait côté payload.
        // Base énoncée (21/10/2026) : même demande (moteur) et même stock
        // (théorique inventaire) que la liste de courses — cohérence
        // garantie par ReapproDemande, partagé par les deux pages.
        $reappro = isset($forecast['produits']) && is_array($forecast['produits']) ? $forecast['produits'] : [];
        $reapproMeta = $reappro_meta ?? [];
        $reapproBasis = 'demande : ' . (string) ($reapproMeta['demande'] ?? 'prévision du moteur')
            . (isset($reapproMeta['fenetre']) ? ' (' . (string) $reapproMeta['fenetre'] . ')' : '')
            . (!empty($reapproMeta['creuse']) ? ', période creuse ' . (string) $reapproMeta['creuse'] : '')
            . ' · stock : ' . (string) ($reapproMeta['stock'] ?? 'théorique inventaire');
        if (!empty($reapproMeta['date_stock'])) {
            $reapproBasis .= ' (comptages jusqu\'au '
                . (new DateTimeImmutable(substr((string) $reapproMeta['date_stock'], 0, 10)))->format('d/m/Y')
                . ')';
        }
        ?>
        <div class="card surface glass chart-card chart-span-2">
            <div class="chart-head">
                <h2 class="chart-title">Réapprovisionnement</h2>
                <span class="chart-sub">besoins estimés — horizon 7 jours ouverts · <?= e($reapproBasis) ?></span>
            </div>
            <?php if (!empty($reapproMeta['calendrier'])): ?>
                <?php
                // Modulation calendrier universitaire (mission E2) : texte
                // factuel du backend (ReapproDemande::meta — « calendrier »
                // non null ⇔ facteur < 1, ex. « Semaine sans cours du
                // 26/10 au 30/10 : suggestions réduites ×0,6 »).
                $calReapproTxt = rtrim(trim((string) $reapproMeta['calendrier']), '.') . '.';
                ?>
                <p class="chart-sub fc-note" title="Quantités suggérées modulées par le calendrier universitaire (semaines sans cours de l'horizon de couverture).">📅 <?= e($calReapproTxt) ?></p>
            <?php endif; ?>
            <?php if ($reappro === []): ?>
                <p class="muted fc-empty">Aucune suggestion de réapprovisionnement.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Produit</th><th>Catégorie</th>
                                <th class="num">Unités 7 j</th><th class="num">Sécurité</th>
                                <th class="num">Stock</th><th class="num">Suggestion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reappro as $p): ?>
                                <?php
                                $sugg = array_key_exists('suggestion', $p) && $p['suggestion'] !== null ? (float) $p['suggestion'] : null;
                                $negatif = !empty($p['stock_negatif']);
                                ?>
                                <tr>
                                    <td><?= e((string) ($p['label'] ?? '')) ?></td>
                                    <td><?= e((string) ($p['category'] ?? '—')) ?></td>
                                    <td class="num"><?= e(number_format((float) ($p['unites'] ?? 0), 0, ',', ' '))
                                        ?> (<?= e(number_format((float) ($p['lo'] ?? 0), 0, ',', ' ')) ?>–<?= e(number_format((float) ($p['hi'] ?? 0), 0, ',', ' ')) ?>)</td>
                                    <td class="num"><?= e(number_format((float) ($p['safety_demand'] ?? 0), 1, ',', ' ')) ?></td>
                                    <td class="num"><?= !array_key_exists('stock', $p) || $p['stock'] === null
                                        ? '—'
                                        : ($negatif
                                            ? '<span class="reappro-neg" title="Stock théorique négatif (survente) — anomalie d\'inventaire à recompter ; la suggestion ne le compte pas">' . e(number_format((float) $p['stock'], 0, ',', ' ')) . ' ⚠</span>'
                                            : e(number_format((float) $p['stock'], 0, ',', ' '))) ?></td>
                                    <td class="num<?= $sugg !== null && $sugg > 0 ? ' reappro-sugg' : '' ?>"><?= $sugg === null ? '—' : e(number_format($sugg, 0, ',', ' ')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php
        // Totaux de la tendance produit : calculés côté contrôleur
        // (product_totals, lot 4 — ex-array_sum en vue).
        $pwCa  = (float) ($product_totals['ca'] ?? 0);
        $pwQty = (int) ($product_totals['qty'] ?? 0);

        // Titre-conclusion : le meilleur jour DU MOTEUR quand il est
        // significatif (insight « Meilleur jour »), sinon mention honnête.
        $wdBestCard = isset($weekday['best']) && is_array($weekday['best']) ? $weekday['best'] : null;
        $wdJoursComplets = ['Lun' => 'lundi', 'Mar' => 'mardi', 'Mer' => 'mercredi', 'Jeu' => 'jeudi', 'Ven' => 'vendredi', 'Sam' => 'samedi', 'Dim' => 'dimanche'];
        ?>
        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title"><?=
                    $wdBestCard !== null && ($wdBestCard['ca'] ?? null) !== null
                        ? 'Le ' . e($wdJoursComplets[(string) ($wdBestCard['label'] ?? '')] ?? mb_strtolower((string) ($wdBestCard['label'] ?? ''))) . ' est le meilleur jour de la semaine'
                        : 'Aucune journée ne domine nettement la semaine'
                ?></h2>
                <!-- F4 : UN seul chiffre — le meilleur jour, UNIQUEMENT si
                     significatif (meilleur_jour du moteur, sinon la carte
                     dit honnêtement que les jours sont indiscernables).
                     Pré-rendu serveur, le JS le reconstruit à l'identique
                     depuis le payload. -->
                <span class="chart-sub" id="weekday-sub"><?php
                    $wdBest = isset($weekday['best']) && is_array($weekday['best']) ? $weekday['best'] : null;
                    $wdSub = ($wdBest !== null && ($wdBest['ca'] ?? null) !== null)
                        ? 'Meilleur jour : ' . e((string) ($wdBest['label'] ?? '—')) . ' — ' . e(formatPrice((float) $wdBest['ca'])) . '/jour attendu (modèle, n=' . (int) ($wdBest['n'] ?? 0) . ')'
                        : 'Pas de différence significative entre les jours (historique court)';
                    if (!empty($weekday['creuse_note'])) {
                        $wdSub .= ' · ' . e((string) $weekday['creuse_note']);
                    }
                    echo $wdSub;
                ?></span>
            </div>
            <div class="chart-canvas"><canvas id="chart-weekday" height="160"></canvas></div>
        </div>

        <?php if ($products !== []): ?>
        <div class="card surface glass chart-card chart-span-2">
            <div class="chart-head">
                <div>
                    <h2 class="chart-title">Semaine après semaine : <?= $productSelected !== '' ? e($productSelected) : 'le produit suivi' ?></h2>
                    <span class="chart-sub">
                        <?= $productSelected !== '' ? e($productSelected) . ' — ' : '' ?>
                        CA <?= e(formatPrice((float) $pwCa)) ?> · <?= number_format((float) $pwQty, 0, ',', ' ') ?> ventes sur la période
                    </span>
                </div>
                <!-- Le sélecteur re-soumet la page en GET en préservant les
                     filtres (période + dates custom, catégorie, paiement). -->
                <form method="get" class="pt-form">
                    <?php if ($filters['period'] === 'custom'): ?>
                        <input type="hidden" name="from" value="<?= e($filters['fromInput']) ?>">
                        <input type="hidden" name="to" value="<?= e($filters['toInput']) ?>">
                    <?php endif; ?>
                    <input type="hidden" name="period" value="<?= e($filters['period']) ?>">
                    <input type="hidden" name="category" value="<?= e($filters['category']) ?>">
                    <input type="hidden" name="payment" value="<?= e($filters['payment']) ?>">
                    <label class="af-label" for="product-select">Produit</label>
                    <select name="product" id="product-select" class="af-select">
                        <?php foreach ($products as $p): ?>
                            <option value="<?= e($p) ?>" <?= $p === $productSelected ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="chart-canvas"><canvas id="chart-product-weekly" height="120"></canvas></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!--  Détails techniques (replié) : métriques du modèle + qualité   -->
    <!--  des données. Toutes les mentions de jargon (WAPE, dispersion, -->
    <!--  couverture) vivent UNIQUEMENT ici, section repliée (lot 4).   -->
    <!-- ============================================================ -->
    <?php
    $anomAtyp  = isset($forecast['anomalies']['jours_atypiques']) && is_array($forecast['anomalies']['jours_atypiques']) ? $forecast['anomalies']['jours_atypiques'] : [];
    $anomCout  = isset($forecast['anomalies']['produits_cout_suspect']) && is_array($forecast['anomalies']['produits_cout_suspect']) ? $forecast['anomalies']['produits_cout_suspect'] : [];
    $anomNotes = isset($forecast['notes']) && is_array($forecast['notes']) ? $forecast['notes'] : [];
    $anomFusion = isset($forecast['anomalies']['cles_fusionnees']) && is_array($forecast['anomalies']['cles_fusionnees']) ? $forecast['anomalies']['cles_fusionnees'] : [];
    $anomSansStock = isset($forecast['anomalies']['produits_sans_stock']) && is_array($forecast['anomalies']['produits_sans_stock']) ? $forecast['anomalies']['produits_sans_stock'] : [];
    $anomNSansStock = (int) ($forecast['anomalies']['n_produits_sans_stock'] ?? 0);
    $anomNegatifs = isset($forecast['anomalies']['stocks_negatifs']) && is_array($forecast['anomalies']['stocks_negatifs']) ? $forecast['anomalies']['stocks_negatifs'] : [];
    ?>
    <details class="card surface glass dataq">
        <summary>
            Détails techniques — <?= (int) ($forecast['anomalies']['n_jours_inclus'] ?? 0) ?> jours inclus
            · <?= count($anomAtyp) ?> jour<?= count($anomAtyp) > 1 ? 's' : '' ?> atypique<?= count($anomAtyp) > 1 ? 's' : '' ?> exclu(s)
            · <?= (int) ($forecast['anomalies']['ventes_zero'] ?? 0) ?> vente(s) à 0 €
            · <?= (int) ($forecast['anomalies']['doublons_ignores'] ?? 0) ?> doublon(s) ignoré(s)
            · <?= count($anomCout) ?> produit(s) à coût suspect
            <?= $anomNSansStock > 0 ? '· ' . $anomNSansStock . ' produit(s) sans stock' : '' ?>
            <?= $anomNegatifs !== [] ? '· ' . count($anomNegatifs) . ' stock(s) négatif(s)' : '' ?>
        </summary>
        <div class="dataq-body">
            <p><strong>Qualité du modèle de prévision :</strong> <?= e($fcTooltip) ?></p>
            <?php if ($fcNoteSel !== ''): ?>
                <p><?= e($fcNoteSel) ?></p>
            <?php endif; ?>
            <?php if ($anomAtyp !== []): ?>
                <p><strong>Jours atypiques exclus :</strong> <?= e(implode(', ', array_map('strval', $anomAtyp))) ?></p>
            <?php endif; ?>
            <?php if ($anomCout !== []): ?>
                <p><strong>Produits à coût suspect :</strong> <?= e(implode(', ', array_map('strval', $anomCout))) ?></p>
            <?php endif; ?>
            <?php if ($anomFusion !== []): ?>
                <p><strong>Clés produit fusionnées (quasi-doublons) :</strong> <?= e(implode(', ', array_map('strval', $anomFusion))) ?> — à consolider via les alias.</p>
            <?php endif; ?>
            <?php if ($anomNSansStock > 0): ?>
                <p><strong><?= $anomNSansStock ?> produit<?= $anomNSansStock > 1 ? 's' : '' ?> non rattaché<?= $anomNSansStock > 1 ? 's' : '' ?> au stock<?= $anomSansStock !== [] ? ' (' . e(implode(', ', array_map('strval', $anomSansStock))) . ')' : '' ?> :</strong> rattache-les via les alias (comptage/achats/pertes) pour des suggestions.</p>
            <?php endif; ?>
            <?php if ($anomNegatifs !== []): ?>
                <p><strong>Stocks théoriques négatifs (survente — à recompter) :</strong>
                    <?= e(implode(', ', array_map(
                        static fn (array $n): string => $n['label'] . ' (' . (int) $n['stock'] . ')',
                        $anomNegatifs
                    ))) ?>.
                    Ces stocks ne majorent pas les suggestions : le besoin déduit max(0, stock).</p>
            <?php endif; ?>
            <?php if ($anomNotes !== []): ?>
                <ul class="dataq-notes">
                    <?php foreach ($anomNotes as $note): ?>
                        <li><?= e((string) $note) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </details>

    <!-- ============================================================ -->
    <!--  Heatmap des ventes par jour × heure                          -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card heatmap-card">
        <div class="chart-head">
            <div>
                <h2 class="chart-title">Les créneaux qui concentrent les ventes</h2>
                <span class="chart-sub" id="heatmap-sub">Jour × heure — <?= e($filters['category'] === 'all' ? 'Toutes catégories' : $filters['category']) ?> · <?= e($filters['payment'] === 'all' ? 'Tous paiements' : $filters['payment']) ?></span>
            </div>
            <div class="hm-filters">
                <select id="hm-cat" class="af-select" onchange="document.getElementById('analytics-filters').submit()">
                    <option value="">Toutes catégories</option>
                    <option value="Boisson">Boisson</option>
                    <option value="Nourriture">Nourriture</option>
                    <option value="Spécial">Spécial</option>
                </select>
            </div>
        </div>
        <div class="heatmap-wrap">
        <div class="heatmap" id="heatmap"></div>
        <div class="hm-legend">
            <span class="hm-legend-label">Faible</span>
            <div class="hm-legend-bar"></div>
            <span class="hm-legend-label">Fort</span>
        </div>
    </div>
    </div>

    <!-- ============================================================ -->
    <!--  Insights automatiques                                         -->
    <!-- ============================================================ -->
    <?php if ($insights !== []): ?>
    <div class="insights-grid">
        <?php foreach ($insights as $ins): ?>
            <div class="card surface glass insight-card insight-<?= e($ins['tone'] ?? 'teal') ?>">
                <span class="insight-icon"><?= $iconSvg($ins['icon'] ?? 'star') ?></span>
                <div>
                    <h3 class="insight-title"><?= e($ins['title'] ?? '') ?></h3>
                    <p class="insight-text"><?= e($ins['text'] ?? '') ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!--  Tableau récapitulatif (tri client + export CSV)              -->
    <!-- ============================================================ -->
    <div class="card surface glass table-card">
        <div class="chart-head">
            <h2 class="chart-title">Détail par produit</h2>
            <button type="button" class="btn btn-ghost btn-sm" id="export-csv">Exporter CSV</button>
        </div>
        <div class="table-wrap">
            <table class="data-table" id="product-table">
                <thead>
                    <tr>
                        <th data-key="product"  data-type="str">Produit</th>
                        <th data-key="category" data-type="str">Catégorie</th>
                        <th data-key="qty"      data-type="num" class="num">Qté</th>
                        <th data-key="ca"       data-type="num" class="num">CA</th>
                        <th data-key="cost"     data-type="num" class="num">Coût moy.</th>
                        <th data-key="profit"   data-type="num" class="num">Bénéfice</th>
                        <th data-key="margin"   data-type="num" class="num" title="Marge observée (coût à la date de vente)">Marge observée %</th>
                    </tr>
                </thead>
                <tbody id="product-table-body"></tbody>
                <tfoot>
                    <tr>
                        <td colspan="2"><strong>Total</strong></td>
                        <td class="num" id="tot-qty"></td>
                        <td class="num" id="tot-ca"></td>
                        <td class="num">—</td>
                        <td class="num" id="tot-profit"></td>
                        <td class="num" id="tot-margin"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>

<?php if ($kiosk): ?>
<!-- Nav de bas de page (kiosque uniquement). -->
<div class="kday-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/' . rawurlencode($token))) ?>">← Kiosque admin</a>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/comptage/jour/' . rawurlencode($token))) ?>">📊 Récap du jour</a>
</div>
<?php endif; ?>

<!-- Chart.js 4.4.7, vendorisé (lot 4) -->
<script src="<?= e(rootAssetVersioned('/assets/vendor/chart.umd.min.js')) ?>" defer></script>
<script src="<?= e(rootAssetVersioned('/assets/js/analytics.js')) ?>" defer></script>
<?php /*
Lot 4 (extraction JS) : les valeurs injectées par PHP restent ici —
l'îlot JSON (payload complet du contrôleur) + la constante BOOT
(valeurs ponctuelles : mode kiosque, URL produits, catégorie courante).
Le JS extrait (public/assets/js/analytics.js, defer) lit ces données
et ne contient aucun calcul métier.
*/ ?>
<script type="application/json" id="analytics-data"><?= $json ?></script>
<script>
const ANALYTICS_BOOT = {
    kiosk: <?= $kiosk ? 'true' : 'false' ?>,
    produitsUrl: '<?= e(url('/admin/compta/produits')) ?>',
    categorie: '<?= e($filters['category']) ?>'
};
</script>
<script>
/* Mission E2 — annotation « semaines sans cours » du graphique des ventes
   de la période (#chart-trend). Source : îlot JSON ci-dessus
   (payload.calendrier, exposé par AdminAnalyticsController depuis
   CalendarContext::pourPeriode) — ce script ne déduit RIEN du calendrier
   EDT, il mappe les semaines mortes sur les buckets de l'axe (mêmes bornes
   qu'Analytics::enumerateBuckets : jours consécutifs depuis filters.from,
   fenêtres de 7 jours en granularité semaine).

   CHOIX DE MÉCANISME (documenté, zéro nouvelle dépendance) : le plugin
   officiel d'annotation Chart.js n'est pas vendorisé ; sans lui, une bande
   de fond (dataset area) ou des pointillés verticaux ne peuvent pas être
   alignés précisément sur un axe catégoriel (bords interpolés au centre
   des barres → demi-barres débordantes). Mécanisme retenu : grisage EXACT
   des barres CA et des libellés d'axe des buckets couverts par une semaine
   morte (couleurs scriptables Chart.js), + légende courte rendue côté
   serveur (chart-sub fc-note ci-dessus) + rappel en tooltip. Sans semaine
   morte affichable, ce script ne modifie RIEN (non-régression).

   Exécution : DOMContentLoaded — les scripts defer (Chart.js, analytics.js)
   ont déjà construit le graphique à ce stade (un defer s'exécute avant
   DOMContentLoaded ; l'inline ne fait qu'enregistrer le listener). */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    if (typeof Chart === 'undefined' || typeof Chart.getChart !== 'function') { return; }
    var island = document.getElementById('analytics-data');
    var data = null;
    try { data = island ? JSON.parse(island.textContent) : null; } catch (err) { data = null; }
    if (!data || !data.calendrier || !Array.isArray(data.calendrier.semaines_mortes)) { return; }
    var mortes = data.calendrier.semaines_mortes;
    if (mortes.length === 0) { return; }
    var chart = Chart.getChart('chart-trend');
    if (!chart) { return; }

    var f = data.filters || {};
    var gran = f.granularity === 'week' ? 'week' : (f.granularity === 'month' ? 'month' : 'day');
    if (gran === 'month') {
        // Un mois CONTENANT une semaine morte n'est pas mort : aucune barre
        // marquée, seule la légende serveur l'indique.
        return;
    }

    var DAY = 86400000;
    function isoMs(s) {
        var p = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ''));
        return p ? Date.UTC(Number(p[1]), Number(p[2]) - 1, Number(p[3])) : NaN;
    }
    var fromMs = isoMs(f.from);
    if (isNaN(fromMs)) { return; }

    // Plages mortes [lundi ; vendredi] en ms UTC (arithmétique par jours
    // entiers — jamais de fuseau ni d'heure d'été dans la comparaison).
    var plages = [];
    mortes.forEach(function (s) {
        var lo = isoMs(s && s.lundi);
        var hi = isoMs(s && s.vendredi);
        if (!isNaN(lo) && !isNaN(hi)) {
            plages.push({ lo: lo, hi: hi, txt: String((s && s.debut_fr) || '') + '–' + String((s && s.fin_fr) || '') });
        }
    });
    if (plages.length === 0) { return; }

    // Barre i → semaine morte couvrante (null si aucune).
    var n = chart.data.labels ? chart.data.labels.length : 0;
    var morteBarre = new Array(n);
    var une = false;
    for (var i = 0; i < n; i++) {
        var t = fromMs + i * (gran === 'week' ? 7 * DAY : DAY);
        morteBarre[i] = null;
        for (var k = 0; k < plages.length; k++) {
            var p = plages[k];
            var couvert = gran === 'day'
                ? (t >= p.lo && t <= p.hi)
                : (p.lo >= t && p.lo <= t + 6 * DAY);
            if (couvert) { morteBarre[i] = p; une = true; break; }
        }
    }
    if (!une) { return; }

    var CA_BG = 'rgba(72,189,211,.55)';
    var CA_BG_MORTE = 'rgba(148,163,184,.35)';
    var bars = chart.data.datasets && chart.data.datasets[0];
    if (bars && bars.type === 'bar') {
        bars.backgroundColor = function (ctx) {
            return morteBarre[ctx.dataIndex] ? CA_BG_MORTE : CA_BG;
        };
    }
    var axeX = chart.options.scales && chart.options.scales.x;
    if (axeX) {
        axeX.ticks = axeX.ticks || {};
        axeX.ticks.color = function (ctx) {
            return morteBarre[ctx.index] ? 'rgba(159,179,200,.5)' : Chart.defaults.color;
        };
    }
    var tooltip = chart.options.plugins && chart.options.plugins.tooltip;
    if (tooltip) {
        tooltip.callbacks = tooltip.callbacks || {};
        tooltip.callbacks.afterBody = function (items) {
            var it = items && items[0];
            var p = it ? morteBarre[it.dataIndex] : null;
            return p ? 'Semaine sans cours : ' + p.txt : '';
        };
    }
    chart.update();
});
</script>
