<?php

declare(strict_types=1);

/**
 * Analytique — vue TÉLÉPHONE compacte du dashboard, rendue uniquement dans
 * le kiosque ADMIN (lien secret à jeton, sans connexion).
 *
 * Mêmes données que la vue desktop (AdminAnalyticsController::buildPage()),
 * mais mise en page dense : filtres compacts (pills de période à application
 * immédiate + deux selects), bandeau KPI 3×2, puis QUATRE onglets (classes
 * .compta-tabs du Livre comptable, navigation par hash #vue/#repart/…) :
 *   1. « Vue »        : trend CA/bénéfice + top produits + insights.
 *   2. « Répartition » : donuts catégorie & paiements (centres texte) avec
 *      légendes HTML compactes SOUS chaque donut (la légende intégrée de
 *      Chart.js se superposait au graphique sur téléphone).
 *   3. « Heures »     : heatmap INVERSÉE — heures en lignes (00h→23h),
 *      jours en colonnes (grille minmax(0,1fr) : tient à 320px, détail au
 *      tap, zéro scroll-x — verrou overflow-x:clip sur .ka-page).
 *   4. « Produits »   : rangées-cartes triables (select) + totaux + export CSV.
 * PITFALL Chart.js : un canvas dans un onglet hidden a une taille nulle →
 * chaque graphique est initialisé EN RETARDÉ (lazy), à la première
 * activation de son onglet ; le premier onglet l'est immédiatement au
 * chargement. La heatmap et le tableau (DOM pur) sont construits d'office.
 *
 * @var array<string,mixed> $filters        Filtres GET (period, granularity, category, payment, from, to, fromInput, toInput)
 * @var array<string,string> $periods       Périodes rapides (1d … all, custom)
 * @var list<string> $categories            Catégories distinctes des ventes
 * @var bool $hasSales                      Vrai si au moins une vente importée
 * @var array<string,mixed> $kpis           KPI de la période (+ *_delta vs période précédente)
 * @var list<array<string,mixed>> $insights Insights automatiques (icon, tone, title, text)
 * @var array<string,mixed> $forecast       Moteur ForecastEngine::forecast() TEL QUEL (prevision_jour, intervalle_80, trop_tot, ecart_vs_attendu, dans_intervalle, confiance, modele, n_jours, wape_backtest, hier, jours, horaire, semaine, mois30, benefice, produits, anomalies, notes…)
 * @var array<string,mixed> $day_track      Estimation du jour (window, hour, trop_tot, estimate, cum_actual, cum_expected, actual_so_far, delta_pct|null, projection_eod, ecart_eur, intervalle_h|null, dans_intervalle|null, confiance)
 * @var array<string,mixed> $weekday        Par jour de semaine {labels, ca (modèle : attendu prochaine occurrence), lo/hi (bande ~80 %), obs (moyenne brute observée|null), se, next_date, qty, tx, days_count (occ), today_index, best?{index,label,ca,n}|null, creuse_note?string|null}
 * @var array<string,mixed> $hourly         Par heure {labels, ca_avg, peak_hour, today_ca, today_expected, today_projete, now_hour, trop_tot, s}
 * @var float $goal                         Objectif hebdomadaire affiché (GET ?goal= ou sauvegarde)
 * @var array{ca:float,qty:int} $product_totals             Totaux tendance produit (contrôleur, lot 4 — présent dans le payload)
 * @var array{label:?string,pct:?float,total:float} $category_share Part du CA de la 1re catégorie (contrôleur, lot 4)
 * @var array<string,mixed> $payment_share  Part carte/liquide + libellés pré-composés (contrôleur, lot 4)
 * @var ?float $goal_prob_pct               Probabilité d'atteinte AFFICHÉE (prob × 100 borné, contrôleur, lot 4)
 * @var string $croissance_note             Note d'affichage des deltas (contrôleur, lot 4)
 * @var string $activeTab                   Onglet actif de l'espace Ventes ('today'|'analyses'|'annual')
 * @var string $json                        Payload JSON (Chart.js) injecté dans un îlot <script type="application/json">
 * @var string $token                       Jeton kiosque ADMIN (présent dans l'URL)
 */

// Onglets de l'espace Ventes (partiel partagé, lot 4) : version compacte
// kiosque (classe modulatrice .tabs-ventes--compact), onglet « Analyses ».
$activeTab = $activeTab ?? 'analyses';
$tabsVentesCompact = true;
require AEIC_VIEWS . '/partials/_tabs_ventes.php';

// Icônes SVG des insights (reprises de la vue desktop).
$iconSvg = static function (string $name): string {
    $icons = [
        'star'      => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.9 6.3 6.9.6-5.2 4.6 1.6 6.8L12 17.3 5.8 20.9l1.6-6.8L2.2 8.9l6.9-.6z"/></svg>',
        'trend-up'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 17l6-6 4 4 8-8M21 7v6h-6"/></svg>',
        'alert'     => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L1 21h22zM12 9v5M12 17.5v.5"/></svg>',
        'clock'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    ];
    return $icons[$name] ?? $icons['star'];
};
?>

<div class="ka-page">

    <!-- ============================================================ -->
    <!--  En-tête minimal : titre + période courante                    -->
    <!-- ============================================================ -->
    <header class="ka-head">
        <h1 class="ka-title">Analytique</h1>
        <p class="ka-period">
            Du <strong><?= e(formatDate($filters['from'], 'd/m/Y')) ?></strong>
            au <strong><?= e(formatDate($filters['to'], 'd/m/Y')) ?></strong>
        </p>
    </header>

    <!-- ============================================================ -->
    <!--  Filtres compacts (GET sans action : le jeton du chemin est    -->
    <!--  préservé ; un tap = application immédiate, sauf « Personnalisé ») -->
    <!-- ============================================================ -->
    <form method="get" class="ka-filters" id="ka-filters">
        <div class="ka-pills">
            <?php
            // Les périodes longues (3/6/12 mois, année civile) ont été
            // retirées du sélecteur : le kiosque affiche la même liste
            // courte que le dashboard admin.
            foreach ($periods as $key => $label): ?>
                <button type="button" class="ka-pill <?= $filters['period'] === $key ? 'is-active' : '' ?>" data-period="<?= e($key) ?>"><?= e($label) ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Intervalle personnalisé : révélé par la pill « Personnalisé »,
             avec bouton Appliquer (les dates seules s'appliquent dès que
             les deux bornes sont remplies). -->
        <div class="ka-custom" id="ka-custom" <?= $filters['period'] === 'custom' ? '' : 'hidden' ?>>
            <input type="date" name="from" id="ka-from" value="<?= e($filters['fromInput']) ?>" aria-label="Du">
            <span class="ka-custom-sep">→</span>
            <input type="date" name="to" id="ka-to" value="<?= e($filters['toInput']) ?>" aria-label="Au">
            <button type="submit" class="btn btn-primary btn-sm">Appliquer</button>
        </div>

        <div class="ka-selects">
            <div class="ka-field">
                <label for="ka-cat">Catégorie</label>
                <select name="category" id="ka-cat">
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
            <div class="ka-field">
                <label for="ka-pay">Paiement</label>
                <select name="payment" id="ka-pay">
                    <option value="all" <?= $filters['payment'] === 'all' ? 'selected' : '' ?>>Tous</option>
                    <option value="CARTE" <?= $filters['payment'] === 'CARTE' ? 'selected' : '' ?>>Carte</option>
                    <option value="LIQUIDE" <?= $filters['payment'] === 'LIQUIDE' ? 'selected' : '' ?>>Liquide</option>
                </select>
            </div>
        </div>

        <input type="hidden" name="period" id="ka-period-hidden" value="<?= e($filters['period']) ?>">
    </form>

    <?php if (!$hasSales): ?>
        <!-- Pas de lien d'import ici : réservé à l'espace admin sur PC. -->
        <p class="ka-nosales">Aucune vente importée — les graphiques restent vides jusqu'au premier import (depuis l'espace admin sur PC).</p>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!--  Bandeau KPI compact (3 colonnes × 2 rangées)                  -->
    <!-- ============================================================ -->
    <div class="ka-kpis">
        <?php
        // Note de croissance du moteur (variable du contrôleur, lot 4) :
        // si la période précédente est incomplète, les deltas sont null
        // côté payload — on affiche la note, SANS recomposer de delta.
        $croissanceNote = trim((string) ($croissance_note ?? ''));

        $kpiCards = [
            ['label' => 'CA',            'value' => formatPrice($kpis['ca']),          'delta' => $kpis['ca_delta'] ?? null],
            ['label' => 'Bénéfice',      'value' => formatPrice($kpis['profit']),
             'sub'   => 'Marge ' . number_format((float) $kpis['margin'], 1, ',', ' ') . '%',           'delta' => $kpis['profit_delta'] ?? null],
            ['label' => 'Volume',        'value' => number_format((float) $kpis['qty'], 0, ',', ' ') . ' u.',
                                                                                        'delta' => $kpis['qty_delta'] ?? null],
            ['label' => 'Panier moyen',  'value' => formatPrice($kpis['avg_basket']),  'delta' => $kpis['avg_basket_delta'] ?? null],
            ['label' => 'Transactions',  'value' => number_format((int) $kpis['transactions'], 0, ',', ' '),
                                                                                        'delta' => $kpis['transactions_delta'] ?? null],
            ['label' => 'Nouveaux membres', 'value' => number_format((int) $kpis['members'], 0, ',', ' '),
                                                                                        'delta' => $kpis['members_delta'] ?? null],
        ];
        foreach ($kpiCards as $kpi):
            $delta = $kpi['delta'];
            $up    = $delta === null ? null : ($delta >= 0);
        ?>
            <div class="card surface glass ka-kpi">
                <span class="ka-kpi-label"><?= e($kpi['label']) ?></span>
                <span class="ka-kpi-value"><?= e($kpi['value']) ?></span>
                <?php if (!empty($kpi['sub'])): ?>
                    <span class="ka-kpi-sub"><?= e($kpi['sub']) ?></span>
                <?php endif; ?>
                <?php if ($up !== null): ?>
                    <span class="ka-kpi-delta <?= $up ? 'is-up' : 'is-down' ?>">
                        <?= $up ? '↑' : '↓' ?> <?= number_format(abs((float) $delta), 1, ',', ' ') ?>%
                    </span>
                <?php else: ?>
                    <span class="ka-kpi-delta is-muted"><?= $croissanceNote !== '' ? e($croissanceNote) : '—' ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ============================================================ -->
    <!--  Onglets (classes .compta-tabs du Livre comptable)             -->
    <!-- ============================================================ -->
    <nav class="compta-tabs ka-tabs" data-ka-tabs aria-label="Sections de l'analytique">
        <button type="button" class="compta-tab is-active" data-tab="vue">📊 Vue</button>
        <button type="button" class="compta-tab" data-tab="repart">🎯 Répartition</button>
        <button type="button" class="compta-tab" data-tab="heures">⏰ Heures</button>
        <button type="button" class="compta-tab" data-tab="produits">📋 Produits</button>
    </nav>

    <!-- ==================== Onglet 1 : Vue ==================== -->
    <div class="compta-tabpane ka-pane is-active" data-pane="vue">
        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Le CA et le bénéfice, période par période</h2>
                <span class="ka-sub" id="ka-trend-sub"></span>
            </div>
            <div class="ka-chart ka-chart-trend"><canvas id="ka-chart-trend"></canvas></div>
        </section>

        <?php
        // Chips de prévision : VALEURS DU MOTEUR uniquement (contrat
        // ForecastEngine) — montants, fourchettes, statuts fourchette,
        // « trop tôt », données insuffisantes. Aucun delta recomposé.
        // La fenêtre d'apprentissage (7/14/30 j, GET « fw ») est COMMUNE
        // aux prévisions et à l'estimation du jour, jeton préservé.
        $fcUrl = static function (int $w) use ($token): string {
            $params       = $_GET;
            $params['fw'] = (string) $w;

            return url('/kiosque/admin/analytics/' . rawurlencode($token)) . '?' . http_build_query($params);
        };
        $fcFw      = (int) ($day_track['window'] ?? 30);
        $fcPrev    = isset($forecast['prevision_jour']) ? $forecast['prevision_jour'] : null;
        $fcIv      = isset($forecast['intervalle_80']) && is_array($forecast['intervalle_80']) ? $forecast['intervalle_80'] : null;
        $fcHier    = isset($forecast['hier']) && is_array($forecast['hier']) ? $forecast['hier'] : null;
        $fcConf    = (string) ($forecast['confiance'] ?? 'insuffisant');
        $fcModele  = (string) ($forecast['modele'] ?? '');
        $fcNJours  = (int) ($forecast['n_jours'] ?? 0);
        $fcWape    = isset($forecast['wape_backtest']) ? $forecast['wape_backtest'] : null;
        $fcCoverage = isset($forecast['coverage_backtest']) ? $forecast['coverage_backtest'] : null;
        $fcNoteSel = (string) ($forecast['anomalies']['note_selection'] ?? '');
        $fcHor     = isset($forecast['horaire']) && is_array($forecast['horaire']) ? $forecast['horaire'] : [];
        $fcNowHour = (int) ($fcHor['now_hour'] ?? 0);
        $fcTropTot = !empty($fcHor['trop_tot']) || !empty($forecast['trop_tot']);
        $fcDans    = isset($forecast['dans_intervalle']) ? $forecast['dans_intervalle'] : null;
        $fcEcart   = isset($forecast['ecart_vs_attendu']) ? $forecast['ecart_vs_attendu'] : null;
        $fcInsuf   = ($fcConf === 'insuffisant') || !empty($forecast['anomalies']['donnees_insuffisantes']);

        // Fourchettes étiquetées : « fourchette journée 80 % » (carte
        // Prévisions) vs « fourchette » à l'heure courante (Estimation).
        $kRange = static function (?array $iv, string $label = 'fourchette'): string {
            if ($iv === null || !isset($iv['lo'], $iv['hi']) || $iv['lo'] === null || $iv['hi'] === null) { return ''; }
            return ' (' . $label . ' ' . formatPrice((float) $iv['lo']) . '–' . formatPrice((float) $iv['hi']) . ')';
        };
        $kSigned = static function ($v): string {
            return $v === null ? '' : (($v >= 0 ? '+' : '-') . formatPrice(abs((float) $v)));
        };
        // Neutralité : DANS la fourchette → statut neutre ; HORS → ambre.
        $kStatut = static function ($dans): array {
            if ($dans === true)  { return ['Dans la fourchette ✓', 'is-muted']; }
            if ($dans === false) { return ['Hors fourchette', 'is-off']; }
            return ['', ''];
        };
        $kConfLbl = ['elevee' => 'élevée', 'moyenne' => 'moyenne', 'faible' => 'faible'][$fcConf] ?? 'insuffisante';
        $kFacteur = isset($forecast['facteur_conforme']) && is_numeric($forecast['facteur_conforme'])
            ? (float) $forecast['facteur_conforme'] : null;

        // Info-bulle métriques (règle interface 8) : affichée en note
        // compacte (pas de survol au doigt) + attribut title. La
        // correction conforme explique la largeur des fourchettes ; le
        // bloc debug du moteur fournit niveau (€ par jour moyen
        // désaisonnalisé) et tendance (%/jour).
        $kDebug     = isset($forecast['debug']) && is_array($forecast['debug']) ? $forecast['debug'] : [];
        $kNiveau    = isset($kDebug['niveau_eur']) && is_numeric($kDebug['niveau_eur']) ? (float) $kDebug['niveau_eur'] : null;
        $kTendance  = isset($kDebug['tendance_pct_jour']) && is_numeric($kDebug['tendance_pct_jour']) ? (float) $kDebug['tendance_pct_jour'] : null;
        // Porte d'activation de la tendance (debug du moteur) : sous
        // 30 jours ouverts elle est désactivée (b forcé à 0) — mention
        // explicite au lieu d'un « Tendance ≈ 0,0 %/jour ». Absent du
        // debug (payload ancien) → affichage historique du %.
        $kTrendActive = array_key_exists('tendance_active', $kDebug) ? (bool) $kDebug['tendance_active'] : null;
        // WAPE détaillé : modèle (main) vs baseline B2 — la vue explique
        // la sélection (« pourquoi baseline_B2 ? »).
        $kAnom       = isset($forecast['anomalies']) && is_array($forecast['anomalies']) ? $forecast['anomalies'] : [];
        $kWapeModele = isset($kAnom['wape_modele']) && is_numeric($kAnom['wape_modele']) ? (float) $kAnom['wape_modele'] : null;
        $kWapeBasis  = isset($kAnom['wape_baseline']) && is_numeric($kAnom['wape_baseline']) ? (float) $kAnom['wape_baseline'] : null;
        $kWapeTxt = $fcWape === null
            ? 'WAPE backtest : —'
            : ($fcModele === 'baseline_B2' && $kWapeBasis !== null
                ? 'WAPE modèle ' . number_format((float) ($kWapeModele ?? $fcWape), 1, ',', ' ')
                    . ' % vs baseline B2 ' . number_format($kWapeBasis, 1, ',', ' ')
                    . ' % → baseline retenue (règle : le modèle actif doit battre la naïve)'
                : 'WAPE modèle ' . number_format((float) $fcWape, 1, ',', ' ') . ' %'
                    . ($kWapeBasis !== null ? ' (bat la baseline ' . number_format($kWapeBasis, 1, ',', ' ') . ' %)' : ''));
        // Dispersion NegBin : valeur + mention de la borne R_MAX quand
        // l'estimateur a saturé (r = 200 ≈ Poisson — pas un choix).
        $kR      = isset($kDebug['dispersion_r']) && is_numeric($kDebug['dispersion_r']) ? (float) $kDebug['dispersion_r'] : null;
        $kRBorne = array_key_exists('dispersion_r_borne', $kDebug) ? (bool) $kDebug['dispersion_r_borne'] : null;
        $kRTxt = $kR !== null
            ? ' · dispersion r = ' . number_format($kR, 0, ',', ' ') . ($kRBorne === true ? ' (borne quasi-Poisson)' : '')
            : '';
        $kTooltip = 'Modèle actif : ' . ($fcModele !== '' ? $fcModele : '—')
            . ' · ' . $kWapeTxt
            . ' sur ' . $fcNJours . ' jours'
            . ' · couverture intervalle : ' . ($fcCoverage === null ? '—' : number_format((float) $fcCoverage, 0, ',', ' ')) . ' %'
            . ($kFacteur !== null ? ' · Correction conforme : ×' . number_format($kFacteur, 1, ',', ' ') : '')
            . ($kNiveau !== null ? ' · Niveau ≈ ' . number_format($kNiveau, 0, ',', ' ') . ' €' : '')
            . $kRTxt
            . ($kTrendActive === false
                ? ' · Tendance désactivée (historique < 6 semaines)'
                : ($kTendance !== null ? ' · Tendance ≈ ' . number_format($kTendance, 1, ',', ' ') . ' %/jour' : ''))
            . ($fcNoteSel !== '' ? ' · ' . $fcNoteSel : '');
        // Lot 4 (jargon) : les métriques du modèle (WAPE, dispersion,
        // couverture) ne sont plus affichées en note visible ; elles
        // vivent dans la section repliée « Détails techniques » de
        // l'onglet Vue. La note visible renvoie simplement à cette section.
        $kInfoTxt = 'Fiabilité du modèle, fourchettes et notes : voir « Détails techniques » en bas de l\'onglet Vue.';

        $kChips = [];
        // Jour fermé (prevision null + note du moteur) : JAMAIS « 0,00 € ».
        $kFerme = $fcPrev === null
            && in_array("Fermé aujourd'hui.", (array) ($forecast['notes'] ?? []), true);
        if ($fcPrev !== null) {
            // Fourchette JOURNÉE 80 % étiquetée (distincte de la fourchette
            // à l'heure courante affichée dans l'estimation du jour).
            $txt = "Aujourd'hui : attendu " . formatPrice((float) $fcPrev) . $kRange($fcIv, 'fourchette journée 80 % :');
            if (!$fcTropTot && !$fcInsuf && $fcEcart !== null) {
                $txt .= ' · vs attendu à ' . $fcNowHour . 'h : ' . $kSigned($fcEcart);
            }
            $kChips[] = ['cls' => 'is-muted', 'text' => $txt];
        } elseif ($kFerme) {
            $kChips[] = ['cls' => 'is-muted', 'text' => "Fermé aujourd'hui"];
        }
        if ($fcTropTot) {
            $kChips[] = ['cls' => 'is-muted', 'text' => 'Trop tôt pour comparer (profil < 10 % de la journée)'];
        } elseif ($fcDans !== null) {
            [$stTxt, $stCls] = $kStatut((bool) $fcDans);
            $kChips[] = ['cls' => $stCls, 'text' => $stTxt];
        }
        if ($fcHier !== null && isset($fcHier['prevu']) && $fcHier['prevu'] !== null) {
            $hIv = (isset($fcHier['lo'], $fcHier['hi']) && $fcHier['lo'] !== null && $fcHier['hi'] !== null)
                ? ['lo' => $fcHier['lo'], 'hi' => $fcHier['hi']] : null;
            $txt = 'Hier : ' . formatPrice((float) ($fcHier['reel'] ?? 0)) . ' vs ' . formatPrice((float) $fcHier['prevu']);
            if (isset($fcHier['reel']) && $fcHier['reel'] !== null) {
                $txt .= ' (' . $kSigned((float) $fcHier['reel'] - (float) $fcHier['prevu']) . ')';
            }
            $txt .= $kRange($hIv);
            $kChips[] = ['cls' => 'is-muted', 'text' => $txt];
            if (isset($fcHier['dans_intervalle']) && $fcHier['dans_intervalle'] !== null) {
                [$stTxt, $stCls] = $kStatut((bool) $fcHier['dans_intervalle']);
                $kChips[] = ['cls' => $stCls, 'text' => 'Hier — ' . $stTxt];
            }
        }
        ?>
        <section class="card surface glass ka-card ka-fc" title="<?= e($kInfoTxt) ?>">
            <div class="ka-fc-win">
                <span class="ka-fc-win-label">Fenêtre d'apprentissage :</span>
                <div class="ka-pills">
                    <?php foreach ([7 => '7 j', 14 => '14 j', 30 => '30 j'] as $w => $lbl): ?>
                        <a class="ka-fwpill <?= $fcFw === $w ? 'is-active' : '' ?>" href="<?= e($fcUrl($w)) ?>"><?= e($lbl) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php foreach ($kChips as $chip): ?>
                <span class="ka-kpi-delta <?= e($chip['cls']) ?>"><?= e($chip['text']) ?></span>
            <?php endforeach; ?>
            <?php if ($fcModele === 'baseline_B2'): ?>
                <span class="ka-kpi-delta is-off">Prévision simplifiée active : l'historique récent ne permet pas d'utiliser le modèle avancé</span>
            <?php endif; ?>
            <?php if ($fcInsuf): ?>
                <span class="ka-kpi-delta is-off">Données insuffisantes (&lt; 7 jours ouverts)</span>
            <?php endif; ?>
            <span class="ka-fc-note">ⓘ <?= e($kInfoTxt) ?> · Fiabilité : <?= e($kConfLbl) ?></span>
        </section>

        <?php
        // Chips « Estimation du jour » (payload day_track, format plat) :
        // attendu + fourchette, réel à l'heure courante, écart avec base
        // explicite, statut fourchette neutre/hors, « trop tôt » sans
        // delta, données insuffisantes sans %. Puis chip « Objectif
        // semaine » (forecast.semaine) : attendu + probabilité.
        $dtEst   = isset($day_track['estimate']) ? $day_track['estimate'] : null;
        $dtExp   = isset($day_track['expected_so_far']) ? $day_track['expected_so_far'] : null;
        $dtHour  = (int) ($day_track['hour'] ?? ($fcHor['now_hour'] ?? 0));
        $dtTrop  = !empty($day_track['trop_tot']);
        $dtIvH   = isset($day_track['intervalle_h']) && is_array($day_track['intervalle_h']) ? $day_track['intervalle_h'] : null;
        $dtDans  = isset($day_track['dans_intervalle']) ? $day_track['dans_intervalle'] : null;
        $dtDelta = isset($day_track['delta_pct']) ? $day_track['delta_pct'] : null;
        $dtEcart = isset($day_track['ecart_eur']) ? $day_track['ecart_eur'] : null;
        $dtProj  = isset($day_track['projection_eod']) ? $day_track['projection_eod'] : null;
        $dtConf  = (string) ($day_track['confiance'] ?? 'insuffisant');
        $dtInsuf = ($dtConf === 'insuffisant') || !empty($forecast['anomalies']['donnees_insuffisantes']);
        $sem     = isset($forecast['semaine']) && is_array($forecast['semaine']) ? $forecast['semaine'] : [];
        ?>
        <?php if ($dtEst !== null && (float) $dtEst > 0): ?>
        <section class="card surface glass ka-card ka-fc">
            <?php if ($dtExp !== null): ?>
                <!-- F1 : attendu À L'HEURE COURANTE (fourchette à h), distinct de la fourchette journée 80 %. -->
                <span class="ka-kpi-delta is-muted">
                    Attendu à <?= $dtHour ?>h (fenêtre <?= (int) ($day_track['window'] ?? 30) ?> j) : <?= e(formatPrice((float) $dtExp) . $kRange($dtIvH)) ?>
                </span>
            <?php else: ?>
                <span class="ka-kpi-delta is-muted">
                    Attendu aujourd'hui (fenêtre <?= (int) ($day_track['window'] ?? 30) ?> j) : <?= e(formatPrice((float) $dtEst) . $kRange($dtIvH)) ?>
                </span>
            <?php endif; ?>
            <span class="ka-kpi-delta is-muted">Réel à <?= $dtHour ?>h : <?= e(formatPrice((float) ($day_track['actual_so_far'] ?? 0))) ?></span>
            <?php if ($dtTrop): ?>
                <span class="ka-kpi-delta is-muted">Trop tôt pour comparer (profil &lt; 10 % de la journée)</span>
            <?php elseif (!$dtInsuf && $dtEcart !== null): ?>
                <span class="ka-kpi-delta is-muted">vs attendu à <?= $dtHour ?>h : <?= e($kSigned($dtEcart)) ?><?= $dtExp !== null ? ' (attendu ' . e(formatPrice((float) $dtExp)) . ')' : '' ?></span>
            <?php elseif (!$dtInsuf && $dtDelta !== null): ?>
                <span class="ka-kpi-delta is-muted">vs attendu à <?= $dtHour ?>h : <?= $dtDelta >= 0 ? '+' : '-' ?><?= e(number_format(abs((float) $dtDelta), 1, ',', ' ')) ?> % (attendu <?= $dtExp === null ? '—' : e(formatPrice((float) $dtExp)) ?>)</span>
            <?php endif; ?>
            <?php if (!$dtTrop && $dtDans !== null): ?>
                <?php [$stTxt, $stCls] = $kStatut((bool) $dtDans); ?>
                <span class="ka-kpi-delta <?= e($stCls) ?>"><?= e($stTxt) ?></span>
            <?php endif; ?>
            <?php if ($dtProj !== null): ?>
                <span class="ka-kpi-delta is-muted">Projection fin de journée : <?= e(formatPrice((float) $dtProj)) ?></span>
            <?php endif; ?>
            <?php if ($dtInsuf): ?>
                <span class="ka-kpi-delta is-off">Données insuffisantes (&lt; 7 jours ouverts)</span>
            <?php endif; ?>
        </section>
        <?php elseif ($kFerme): ?>
        <section class="card surface glass ka-card ka-fc">
            <span class="ka-kpi-delta is-muted">Fermé aujourd'hui</span>
        </section>
        <?php endif; ?>

        <?php if ($sem !== [] && ($sem['attendu'] ?? null) !== null): ?>
            <?php
            // prob_atteinte est une FRACTION 0–1 : le ×100 (borné) est fait
            // UNE fois côté contrôleur (goal_prob_pct, lot 4) ; ici l'affichage.
            $semProbPct = $goal_prob_pct ?? null;
            $semProbFmt = $semProbPct === null ? '' : number_format($semProbPct, $semProbPct < 10 ? 1 : 0, ',', ' ');
            $semIvK  = (($sem['lo'] ?? null) !== null && ($sem['hi'] ?? null) !== null)
                ? ['lo' => $sem['lo'], 'hi' => $sem['hi']] : null;
            $semRestK = (int) ($sem['jours_restants'] ?? 0);
            ?>
            <section class="card surface glass ka-card ka-fc">
                <span class="ka-kpi-delta is-muted">
                    Objectif semaine : <?= e(formatPrice((float) ($sem['objectif'] ?? ($goal ?? 0)))) ?>
                    · attendu <?= e(formatPrice((float) $sem['attendu']) . $kRange($semIvK)) ?>
                </span>
                <span class="ka-kpi-delta <?= $semProbPct === null ? 'is-muted' : ($semProbPct >= 50 ? 'is-up' : 'is-off') ?>">
                    Probabilité d'atteinte : <?= $semProbPct === null ? '—' : e($semProbFmt) . ' %' ?>
                </span>
                <?php $semReqK = $sem['requis_par_jour'] ?? null; ?>
                <?php if ($semReqK !== null && (float) $semReqK > 0.0 && (float) ($sem['reel'] ?? 0.0) < (float) ($sem['objectif'] ?? 0.0)): ?>
                    <span class="ka-kpi-delta is-muted">
                        Requis <?= e(formatPrice((float) $semReqK)) ?>/jour pour viser l'objectif (<?= $semRestK ?> jour<?= $semRestK > 1 ? 's' : '' ?> restant<?= $semRestK > 1 ? 's' : '' ?>)
                    </span>
                <?php endif; ?>
                <?php if (($sem['manquant_par_jour'] ?? null) !== null && (float) $sem['manquant_par_jour'] > 0.0 && $semRestK > 0): ?>
                    <span class="ka-kpi-delta is-muted">
                        Manque attendu <?= e(formatPrice((float) $sem['manquant_par_jour'])) ?>/jour (<?= $semRestK ?> jour<?= $semRestK > 1 ? 's' : '' ?> restant<?= $semRestK > 1 ? 's' : '' ?>)
                    </span>
                <?php endif; ?>
                <?php
                // Horizon 30 jours CALENDAIRES : libellé honnête (debut →
                // fin, occurrences de vente, dont période creuse) —
                // l'ancienne boucle comptait 30 jours OUVERTS ≈ 42 dates.
                $m30K = isset($forecast['mois30']) && is_array($forecast['mois30']) ? $forecast['mois30'] : [];
                if (isset($m30K['attendu']) && $m30K['attendu'] !== null):
                    $m30IvK = (($m30K['lo'] ?? null) !== null && ($m30K['hi'] ?? null) !== null)
                        ? ['lo' => $m30K['lo'], 'hi' => $m30K['hi']] : null;
                    $m30LblK = 'Horizon 30 jours';
                    if (isset($m30K['debut'], $m30K['fin'], $m30K['jours_ouverts'])) {
                        try {
                            $m30LblK = 'Horizon 30 jours (du ' . (new \DateTimeImmutable((string) $m30K['debut']))->format('d/m')
                                . ' au ' . (new \DateTimeImmutable((string) $m30K['fin']))->format('d/m')
                                . ' · ' . (int) $m30K['jours_ouverts'] . ' jours ouverts'
                                . (isset($m30K['jours_creuse']) && (int) $m30K['jours_creuse'] > 0
                                    ? ', dont ' . (int) $m30K['jours_creuse'] . ' en période creuse' : '')
                                . ')';
                        } catch (\Throwable) {
                            // dates invalides : libellé simple
                        }
                    }
                ?>
                <span class="ka-kpi-delta is-muted">
                    <?= e($m30LblK) ?> : attendu <?= e(formatPrice((float) $m30K['attendu']) . $kRange($m30IvK)) ?>
                </span>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php
        // ---- Carte « Idées du moment » (moteur de recommandations) : ----
        // partiel partagé avec la vue admin ; $reco vient du payload
        // buildPage ('reco'). Le rendu est TOUJOURS kiosque ici : $kiosk
        // est posé avant l'inclusion, $token est dans le scope.
        $kiosk = true;
        require AEIC_VIEWS . '/admin/reco/carte.php';
        ?>

        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Les produits qui font le chiffre d'affaires</h2>
                <span class="ka-sub">Top 10 · CA</span>
            </div>
            <div class="ka-chart ka-chart-top"><canvas id="ka-chart-top"></canvas></div>
        </section>

        <?php if ($insights !== []): ?>
        <div class="ka-insights">
            <?php foreach ($insights as $ins): ?>
                <div class="card surface glass ka-insight ka-insight--<?= e($ins['tone'] ?? 'teal') ?>">
                    <span class="ka-insight-icon"><?= $iconSvg($ins['icon'] ?? 'star') ?></span>
                    <div>
                        <h3 class="ka-insight-title"><?= e($ins['title'] ?? '') ?></h3>
                        <p class="ka-insight-text"><?= e($ins['text'] ?? '') ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        // Bandeau compact « Détails techniques » (replié, lot 4) :
        // métriques du modèle + anomalies du moteur + notes. Tout le
        // jargon (WAPE, dispersion, couverture) vit UNIQUEMENT ici.
        $kAnomAtyp = isset($forecast['anomalies']['jours_atypiques']) && is_array($forecast['anomalies']['jours_atypiques']) ? $forecast['anomalies']['jours_atypiques'] : [];
        $kAnomCout = isset($forecast['anomalies']['produits_cout_suspect']) && is_array($forecast['anomalies']['produits_cout_suspect']) ? $forecast['anomalies']['produits_cout_suspect'] : [];
        $kNotes    = isset($forecast['notes']) && is_array($forecast['notes']) ? $forecast['notes'] : [];
        $kFusion   = isset($forecast['anomalies']['cles_fusionnees']) && is_array($forecast['anomalies']['cles_fusionnees']) ? $forecast['anomalies']['cles_fusionnees'] : [];
        $kSansStock = isset($forecast['anomalies']['produits_sans_stock']) && is_array($forecast['anomalies']['produits_sans_stock']) ? $forecast['anomalies']['produits_sans_stock'] : [];
        $kNSansStock = (int) ($forecast['anomalies']['n_produits_sans_stock'] ?? 0);
        ?>
        <details class="card surface glass ka-card ka-dataq">
            <summary>
                Détails techniques — <?= (int) ($forecast['anomalies']['n_jours_inclus'] ?? 0) ?> j inclus
                · <?= count($kAnomAtyp) ?> atypique(s)
                · <?= (int) ($forecast['anomalies']['ventes_zero'] ?? 0) ?> vente(s) à 0 €
                · <?= (int) ($forecast['anomalies']['doublons_ignores'] ?? 0) ?> doublon(s)
                · <?= count($kAnomCout) ?> coût(s) suspect(s)
                <?= $kNSansStock > 0 ? '· ' . $kNSansStock . ' sans stock' : '' ?>
            </summary>
            <p class="ka-fc-note"><strong>Qualité du modèle de prévision :</strong> <?= e($kTooltip) ?></p>
            <?php if ($fcNoteSel !== ''): ?><p class="ka-fc-note"><?= e($fcNoteSel) ?></p><?php endif; ?>
            <?php if ($kAnomAtyp !== []): ?><p class="ka-fc-note">Jours atypiques exclus : <?= e(implode(', ', array_map('strval', $kAnomAtyp))) ?></p><?php endif; ?>
            <?php if ($kAnomCout !== []): ?><p class="ka-fc-note">Coûts suspects : <?= e(implode(', ', array_map('strval', $kAnomCout))) ?></p><?php endif; ?>
            <?php if ($kFusion !== []): ?><p class="ka-fc-note">Clés produit fusionnées (quasi-doublons) : <?= e(implode(', ', array_map('strval', $kFusion))) ?> — à consolider via les alias.</p><?php endif; ?>
            <?php if ($kNSansStock > 0): ?><p class="ka-fc-note"><?= $kNSansStock ?> produit(s) non rattaché(s) au stock<?= $kSansStock !== [] ? ' (' . e(implode(', ', array_map('strval', $kSansStock))) . ')' : '' ?> : rattache-les via les alias/product_stocks pour des suggestions.</p><?php endif; ?>
            <?php foreach ($kNotes as $kNote): ?><p class="ka-fc-note">• <?= e((string) $kNote) ?></p><?php endforeach; ?>
        </details>
    </div>

    <!-- ==================== Onglet 2 : Répartition ==================== -->
    <div class="compta-tabpane ka-pane" data-pane="repart" hidden>
        <?php
        // Titres-conclusions (lots 4) : parts calculées côté contrôleur
        // (category_share / payment_share) ; libellés factuels sans vente.
        $catSharePct = $category_share['pct'] ?? null;
        $catShareTxt = $catSharePct === null ? '' : rtrim(rtrim(number_format((float) $catSharePct, 1, ',', ' '), '0'), ',');
        $payPctCarte = $payment_share['pct_carte'] ?? null;
        ?>
        <div class="ka-duo">
            <section class="card surface glass ka-card">
                <div class="ka-chart-head">
                    <h2 class="ka-chart-title"><?= ($category_share['label'] ?? null) !== null && $catShareTxt !== ''
                        ? e((string) $category_share['label']) . ' pèse ' . e($catShareTxt) . ' % du chiffre d&#039;affaires'
                        : 'Répartition du chiffre d&#039;affaires par catégorie' ?></h2>
                    <span class="ka-sub">CA</span>
                </div>
                <div class="ka-chart ka-chart-donut"><canvas id="ka-chart-category"></canvas></div>
                <!-- Légende HTML (remplace la légende Chart.js, illisible sur téléphone). -->
                <div class="ka-leg" id="ka-leg-category"></div>
            </section>

            <section class="card surface glass ka-card">
                <div class="ka-chart-head">
                    <h2 class="ka-chart-title"><?= $payPctCarte !== null
                        ? 'La carte fait ' . e((string) $payPctCarte) . ' % des encaissements'
                        : 'Répartition des paiements' ?></h2>
                    <span class="ka-sub">CA · transactions</span>
                </div>
                <div class="ka-chart ka-chart-donut"><canvas id="ka-chart-payment"></canvas></div>
                <div class="ka-leg" id="ka-leg-payment"></div>
            </section>
        </div>
    </div>

    <!-- ==================== Onglet 3 : Heures (rythmes + heatmap inversée) ==================== -->
    <div class="compta-tabpane ka-pane" data-pane="heures" hidden>
        <?php
        // Meilleur jour : VALEUR DU PAYLOAD (weekday.best, calculé par le
        // moteur — aucun recalcul local, null si non significatif). Un seul
        // chiffre dans le sous-titre. Heure de pointe : argmax des moyennes
        // OBSERVÉES calculé côté contrôleur (hourly.peak_hour, lot 4),
        // étiquetée « observé ». Sous-titre « Par heure » identique à la
        // vue admin : « Attendu aujourd'hui » = JOURNÉE (prevision_jour +
        // intervalle_80) ; partie horaire DISTINCTE « Attendu à Nh » depuis
        // day_track (expected_so_far/intervalle_h) ou « — (trop tôt) ».
        // Jour fermé → « Fermé aujourd'hui », jamais « 0,00 € ».
        $peakHr = isset($hourly['peak_hour']) ? $hourly['peak_hour'] : null;
        $phPrev  = isset($forecast['prevision_jour']) ? $forecast['prevision_jour'] : null;
        $phIv    = isset($forecast['intervalle_80']) && is_array($forecast['intervalle_80']) ? $forecast['intervalle_80'] : null;
        $phFerme = $phPrev === null
            && in_array("Fermé aujourd'hui.", (array) ($forecast['notes'] ?? []), true);
        $phH    = (int) ($day_track['hour'] ?? 0);
        $phTrop = !empty($day_track['trop_tot']);
        $phExp  = isset($day_track['expected_so_far']) ? $day_track['expected_so_far'] : null;
        $phIvH  = isset($day_track['intervalle_h']) && is_array($day_track['intervalle_h']) ? $day_track['intervalle_h'] : null;
        $phParts = ['Heure de pointe : ' . ($peakHr === null ? '—' : $peakHr . 'h') . ' (observé)'];
        if ($phPrev !== null) {
            $phParts[] = "Attendu aujourd'hui : " . formatPrice((float) $phPrev) . $kRange($phIv);
            if ($phTrop) {
                $phParts[] = 'Attendu à ' . $phH . 'h : — (trop tôt)';
            } elseif ($phExp !== null) {
                $phParts[] = 'Attendu à ' . $phH . 'h : ' . formatPrice((float) $phExp) . $kRange($phIvH);
            }
        } elseif ($phFerme) {
            $phParts[] = "Fermé aujourd'hui";
        }
        ?>
        <!-- Graphiques initialisés EN RETARDÉ à la première activation de
             l'onglet (canvas hidden = taille nulle pour Chart.js). -->
        <?php
        // Titre-conclusion : le meilleur jour DU MOTEUR quand il est
        // significatif (insight « Meilleur jour »), sinon mention honnête.
        $wdBestCard = isset($weekday['best']) && is_array($weekday['best']) ? $weekday['best'] : null;
        $wdJoursComplets = ['Lun' => 'lundi', 'Mar' => 'mardi', 'Mer' => 'mercredi', 'Jeu' => 'jeudi', 'Ven' => 'vendredi', 'Sam' => 'samedi', 'Dim' => 'dimanche'];
        ?>
        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title"><?=
                    $wdBestCard !== null && ($wdBestCard['ca'] ?? null) !== null
                        ? 'Le ' . e($wdJoursComplets[(string) ($wdBestCard['label'] ?? '')] ?? mb_strtolower((string) ($wdBestCard['label'] ?? ''))) . ' est le meilleur jour de la semaine'
                        : 'Aucune journée ne domine nettement la semaine'
                ?></h2>
                <?php
                // Meilleur jour UNIQUEMENT si significatif (payload), sinon
                // mention honnête ; l'ajustement période creuse est cité
                // quand une plage est active.
                $wdBest = isset($weekday['best']) && is_array($weekday['best']) ? $weekday['best'] : null;
                $wdSub = ($wdBest !== null && ($wdBest['ca'] ?? null) !== null)
                    ? 'Meilleur jour : ' . e((string) ($wdBest['label'] ?? '—')) . ' — ' . e(formatPrice((float) $wdBest['ca'])) . '/jour attendu (modèle, n=' . (int) ($wdBest['n'] ?? 0) . ')'
                    : 'Pas de différence significative entre les jours (historique court)';
                if (!empty($weekday['creuse_note'])) {
                    $wdSub .= ' · ' . e((string) $weekday['creuse_note']);
                }
                ?>
                <span class="ka-sub"><?= $wdSub ?></span>
            </div>
            <div class="ka-chart ka-chart-bar"><canvas id="ka-chart-weekday"></canvas></div>
        </section>

        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Le rythme des ventes au fil de la journée</h2>
                <span class="ka-sub"><?= e(implode(' · ', $phParts)) ?></span>
            </div>
            <div class="ka-chart ka-chart-bar"><canvas id="ka-chart-hourly"></canvas></div>
        </section>

        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Les créneaux qui concentrent les ventes</h2>
                <span class="ka-sub">Jour × heure — <?= e($filters['category'] === 'all' ? 'Toutes catégories' : $filters['category']) ?> · <?= e($filters['payment'] === 'all' ? 'Tous paiements' : $filters['payment']) ?></span>
            </div>
            <!-- Grille INVERSÉE : heures en lignes (00h→23h), jours en colonnes.
                 Colonnes minmax(0,1fr) → tient à 320px sans scroll ; le détail
                 d'une case s'affiche au tap dans la ligne d'info (pas de
                 tooltip flottant, inutilisable au doigt). -->
            <div class="ka-ht" id="ka-heatmap"></div>
            <p class="ka-ht-info" id="ka-ht-info">Touche une case pour le détail</p>
            <div class="ka-ht-legend">
                <span class="ka-ht-legend-label">Faible</span>
                <div class="ka-ht-legend-bar"></div>
                <span class="ka-ht-legend-label">Fort</span>
            </div>
        </section>
    </div>

    <!-- ==================== Onglet 4 : Produits (rangées-cartes) ==================== -->
    <div class="compta-tabpane ka-pane" data-pane="produits" hidden>
        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Détail par produit</h2>
            </div>
            <!-- Barre d'outils compacte : tri + export CSV (pleine largeur,
                 plus de tableau à 7 colonnes et de scroll horizontal). -->
            <div class="ka-ptools">
                <label class="ka-psort">
                    <span>Trier par</span>
                    <select id="ka-psort">
                        <option value="category" selected>Catégorie</option>
                        <option value="ca">CA</option>
                        <option value="profit">Bénéfice</option>
                        <option value="margin">Marge %</option>
                        <option value="qty">Quantité</option>
                    </select>
                </label>
                <button type="button" class="btn btn-ghost btn-sm" id="ka-export-csv">⬇ CSV</button>
            </div>
            <p class="ka-ptotals" id="ka-ptotals"></p>
            <div id="ka-product-cards"></div>
        </section>
    </div>

    <!-- Nav de bas de page kiosque. -->
    <div class="ka-actions">
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/' . rawurlencode($token))) ?>">← Kiosque admin</a>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/comptage/jour/' . rawurlencode($token))) ?>">📊 Récap du jour</a>
    </div>

</div>

<!-- Chart.js 4.4.7, vendorisé (lot 4) -->
<script src="<?= e(rootAssetVersioned('/assets/vendor/chart.umd.min.js')) ?>" defer></script>
<script src="<?= e(rootAssetVersioned('/assets/js/analytics-kiosk.js')) ?>" defer></script>
<?php /*
Lot 4 (extraction JS) : le payload complet du contrôleur reste dans
l'îlot JSON ci-dessous ; le JS extrait (public/assets/js/analytics-kiosk.js,
defer) le lit et ne contient aucun calcul métier.
*/ ?>
<script type="application/json" id="analytics-kiosk-data"><?= $json ?></script>
