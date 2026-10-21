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
 * @var array<string,mixed> $weekday        Par jour de semaine {labels, ca (modèle : attendu prochaine occurrence), obs (moyenne brute observée|null), se, next_date, qty, tx, days_count (occ), today_index, best?{index,label,ca,n}|null, creuse_note?string|null}
 * @var array<string,mixed> $hourly         Par heure {labels, ca_avg, today_ca, today_expected, today_projete, now_hour, trop_tot, s}
 * @var float $goal                         Objectif hebdomadaire affiché (GET ?goal= ou sauvegarde)
 * @var string $json                        Payload JSON (Chart.js) injecté dans un <script>
 * @var string $token                       Jeton kiosque ADMIN (présent dans l'URL)
 */

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
        // Note de croissance du moteur (payload $json > meta) : si la
        // période précédente est incomplète, les deltas sont null côté
        // payload — on affiche la note, SANS recomposer de delta.
        $payloadDecoded = is_string($json ?? null) ? json_decode($json, true) : null;
        $payloadMeta    = is_array($payloadDecoded) ? (array) ($payloadDecoded['meta'] ?? []) : [];
        $croissanceNote = trim((string) ($payloadMeta['croissance_note'] ?? ''));

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
                <h2 class="ka-chart-title">Évolution CA / bénéfice</h2>
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
        $kTooltip = 'Modèle actif : ' . ($fcModele !== '' ? $fcModele : '—')
            . ' · WAPE backtest : ' . ($fcWape === null ? '—' : number_format((float) $fcWape, 1, ',', ' ')) . ' %'
            . ' sur ' . $fcNJours . ' jours'
            . ' · couverture intervalle : ' . ($fcCoverage === null ? '—' : number_format((float) $fcCoverage, 0, ',', ' ')) . ' %'
            . ($kFacteur !== null ? ' · Correction conforme : ×' . number_format($kFacteur, 1, ',', ' ') : '')
            . ($kNiveau !== null ? ' · Niveau ≈ ' . number_format($kNiveau, 0, ',', ' ') . ' €' : '')
            . ($kTrendActive === false
                ? ' · Tendance désactivée (historique < 6 semaines)'
                : ($kTendance !== null ? ' · Tendance ≈ ' . number_format($kTendance, 1, ',', ' ') . ' %/jour' : ''))
            . ($fcNoteSel !== '' ? ' · ' . $fcNoteSel : '');

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
        <section class="card surface glass ka-card ka-fc" title="<?= e($kTooltip) ?>">
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
                <span class="ka-kpi-delta is-off">Baseline naïve active (le modèle avancé ne bat pas la référence)</span>
            <?php endif; ?>
            <?php if ($fcInsuf): ?>
                <span class="ka-kpi-delta is-off">Données insuffisantes (&lt; 7 jours ouverts)</span>
            <?php endif; ?>
            <span class="ka-fc-note">ⓘ <?= e($kTooltip) ?> · Fiabilité : <?= e($kConfLbl) ?></span>
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
            $semProb = $sem['prob_atteinte'] ?? null;
            // prob_atteinte est une FRACTION 0–1 : l'affichage fait le ×100.
            $semProbPct = $semProb === null ? null : max(0.0, min(100.0, (float) $semProb * 100.0));
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
            </section>
        <?php endif; ?>

        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Top 10 produits</h2>
                <span class="ka-sub">CA</span>
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
        // Bandeau compact « Qualité des données » : anomalies du moteur
        // + notes, repliable (détails accessibles au tap).
        $kAnomAtyp = isset($forecast['anomalies']['jours_atypiques']) && is_array($forecast['anomalies']['jours_atypiques']) ? $forecast['anomalies']['jours_atypiques'] : [];
        $kAnomCout = isset($forecast['anomalies']['produits_cout_suspect']) && is_array($forecast['anomalies']['produits_cout_suspect']) ? $forecast['anomalies']['produits_cout_suspect'] : [];
        $kNotes    = isset($forecast['notes']) && is_array($forecast['notes']) ? $forecast['notes'] : [];
        $kFusion   = isset($forecast['anomalies']['cles_fusionnees']) && is_array($forecast['anomalies']['cles_fusionnees']) ? $forecast['anomalies']['cles_fusionnees'] : [];
        $kSansStock = isset($forecast['anomalies']['produits_sans_stock']) && is_array($forecast['anomalies']['produits_sans_stock']) ? $forecast['anomalies']['produits_sans_stock'] : [];
        $kNSansStock = (int) ($forecast['anomalies']['n_produits_sans_stock'] ?? 0);
        ?>
        <details class="card surface glass ka-card ka-dataq">
            <summary>
                Qualité des données — <?= (int) ($forecast['anomalies']['n_jours_inclus'] ?? 0) ?> j inclus
                · <?= count($kAnomAtyp) ?> atypique(s)
                · <?= (int) ($forecast['anomalies']['ventes_zero'] ?? 0) ?> vente(s) à 0 €
                · <?= (int) ($forecast['anomalies']['doublons_ignores'] ?? 0) ?> doublon(s)
                · <?= count($kAnomCout) ?> coût(s) suspect(s)
                <?= $kNSansStock > 0 ? '· ' . $kNSansStock . ' sans stock' : '' ?>
            </summary>
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
        <div class="ka-duo">
            <section class="card surface glass ka-card">
                <div class="ka-chart-head">
                    <h2 class="ka-chart-title">Par catégorie</h2>
                    <span class="ka-sub">CA</span>
                </div>
                <div class="ka-chart ka-chart-donut"><canvas id="ka-chart-category"></canvas></div>
                <!-- Légende HTML (remplace la légende Chart.js, illisible sur téléphone). -->
                <div class="ka-leg" id="ka-leg-category"></div>
            </section>

            <section class="card surface glass ka-card">
                <div class="ka-chart-head">
                    <h2 class="ka-chart-title">Paiements</h2>
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
        // OBSERVÉES (hourly.ca_avg), étiquetée « observé ». Sous-titre
        // « Par heure » identique à la vue admin : « Attendu aujourd'hui »
        // = JOURNÉE (prevision_jour + intervalle_80) ; partie horaire
        // DISTINCTE « Attendu à Nh » depuis day_track
        // (expected_so_far/intervalle_h) ou « — (trop tôt) ». Jour fermé →
        // « Fermé aujourd'hui », jamais « 0,00 € ».
        $hrAvgList = isset($hourly['ca_avg']) && is_array($hourly['ca_avg']) ? $hourly['ca_avg'] : [];
        $peakHr    = null;
        $peakVal   = 0.0;
        foreach ($hrAvgList as $h => $v) {
            if ($v !== null && (float) $v > $peakVal) { $peakVal = (float) $v; $peakHr = (int) $h; }
        }
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
        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Par jour de semaine</h2>
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
                <h2 class="ka-chart-title">Par heure</h2>
                <span class="ka-sub"><?= e(implode(' · ', $phParts)) ?></span>
            </div>
            <div class="ka-chart ka-chart-bar"><canvas id="ka-chart-hourly"></canvas></div>
        </section>

        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Ventes par jour × heure</h2>
                <span class="ka-sub">Intensité du CA — <?= e($filters['category'] === 'all' ? 'Toutes catégories' : $filters['category']) ?> · <?= e($filters['payment'] === 'all' ? 'Tous paiements' : $filters['payment']) ?></span>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    var data = <?= $json ?>;

    // ---------- Défauts Chart.js + palette (identiques à la vue desktop) ----------
    Chart.defaults.color = '#9fb3c8';
    Chart.defaults.borderColor = 'rgba(255,255,255,.08)';
    Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";

    var teal    = '#48bdd3';
    var violet  = '#6150aa';
    var amber   = '#f59e0b';
    var green   = '#22c55e';
    var red     = '#ef4444';
    var palette = [teal, violet, amber, green, red, '#6db4ff', '#d9c24a', '#c46ad9', '#4ad9b0', '#d96a6a'];

    function money(v) { return Number(v).toFixed(2).replace('.', ',') + ' €'; }
    function pct(v)   { return Number(v).toFixed(1).replace('.', ',') + ' %'; }
    function esc(s)   { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    // ============================================================
    // Filtres compacts : pills à application immédiate, custom,
    // selects catégorie/paiement auto.
    // ============================================================
    var form = document.getElementById('ka-filters');
    var customBox = document.getElementById('ka-custom');
    var periodHidden = document.getElementById('ka-period-hidden');

    function syncCustom() { customBox.hidden = periodHidden.value !== 'custom'; }

    document.querySelectorAll('.ka-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('.ka-pill').forEach(function (p) { p.classList.remove('is-active'); });
            pill.classList.add('is-active');
            periodHidden.value = pill.getAttribute('data-period');
            syncCustom();
            // Application immédiate, SAUF « Personnalisé » (dates d'abord).
            if (periodHidden.value !== 'custom') {
                form.submit();
            }
        });
    });

    // Dates custom : bascule immédiate sur « Personnalisé », application
    // dès que les deux bornes sont remplies (sinon bouton Appliquer).
    var fromInput = document.getElementById('ka-from');
    var toInput = document.getElementById('ka-to');
    Array.prototype.forEach.call([fromInput, toInput], function (input) {
        if (!input) return;
        input.addEventListener('change', function () {
            var customPill = document.querySelector('.ka-pill[data-period="custom"]');
            document.querySelectorAll('.ka-pill').forEach(function (p) { p.classList.remove('is-active'); });
            if (customPill) customPill.classList.add('is-active');
            periodHidden.value = 'custom';
            syncCustom();
            if (fromInput.value && toInput.value) {
                form.submit();
            }
        });
    });
    syncCustom();

    // Selects catégorie / paiement : soumission auto au changement.
    Array.prototype.forEach.call(['ka-cat', 'ka-pay'], function (id) {
        var sel = document.getElementById(id);
        if (sel) {
            sel.addEventListener('change', function () { form.submit(); });
        }
    });

    // ============================================================
    // Onglets (pattern Livre comptable) + initialisation EN RETARDÉ
    // des graphiques (un canvas dans un onglet hidden est à taille
    // nulle pour Chart.js).
    // ============================================================
    var bar = document.querySelector('[data-ka-tabs]');
    var tabs = bar ? bar.querySelectorAll('.compta-tab') : [];
    var panes = document.querySelectorAll('.ka-pane');
    var inited = {};

    function initPane(name) {
        if (inited[name]) return;
        inited[name] = true;
        if (name === 'vue') { initVueCharts(); }
        if (name === 'repart') { initRepartCharts(); }
        if (name === 'heures') { initHeuresCharts(); }
    }

    function activate(name, push) {
        var found = false;
        Array.prototype.forEach.call(tabs, function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            if (on) found = true;
        });
        if (!found) name = tabs.length ? tabs[0].getAttribute('data-tab') : name;
        Array.prototype.forEach.call(panes, function (p) {
            var on = p.getAttribute('data-pane') === name;
            p.classList.toggle('is-active', on);
            p.hidden = !on;
        });
        if (push && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + name);
        }
        initPane(name);
    }

    Array.prototype.forEach.call(tabs, function (t) {
        t.addEventListener('click', function () { activate(t.getAttribute('data-tab'), true); });
    });

    // ---------- Onglet « Vue » : trend CA/bénéfice + top produits ----------
    function initVueCharts() {
        var tr = data.trend || { labels: [], ca: [], profit: [] };
        var sub = document.getElementById('ka-trend-sub');
        if (sub) {
            var g = (data.filters || {}).granularity || 'auto';
            sub.textContent = g.charAt(0).toUpperCase() + g.slice(1);
        }
        var elTrend = document.getElementById('ka-chart-trend');
        if (elTrend) {
            new Chart(elTrend, {
                type: 'bar',
                data: {
                    labels: tr.labels,
                    datasets: [
                        {
                            type: 'bar',
                            label: "Chiffre d'affaires (€)",
                            data: tr.ca,
                            backgroundColor: 'rgba(72,189,211,.55)',
                            borderColor: teal,
                            borderWidth: 1,
                            borderRadius: 4,
                            order: 2,
                        },
                        {
                            type: 'line',
                            label: 'Bénéfice (€)',
                            data: tr.profit,
                            borderColor: violet,
                            backgroundColor: 'rgba(97,80,170,.15)',
                            tension: 0.35,
                            pointBackgroundColor: violet,
                            pointRadius: 3,
                            fill: false,
                            order: 1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } },
                        tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + money(c.raw); } } },
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: money } } },
                },
            });
        }

        var tp = data.topProducts || { labels: [], ca: [] };
        var elTop = document.getElementById('ka-chart-top');
        if (elTop) {
            var grad = elTop.getContext('2d').createLinearGradient(0, 0, 600, 0);
            grad.addColorStop(0, teal);
            grad.addColorStop(1, violet);
            new Chart(elTop, {
                type: 'bar',
                data: {
                    labels: tp.labels,
                    datasets: [{
                        label: 'CA (€)',
                        data: tp.ca,
                        backgroundColor: grad,
                        borderRadius: 6,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: function (c) { return money(c.raw); } } },
                    },
                    scales: { x: { beginAtZero: true, ticks: { callback: money } } },
                },
            });
        }
    }

    // ---------- Onglet « Répartition » : donuts catégorie + paiements ----------
    // Légendes HTML sous les donuts (la légende Canvas de Chart.js se
    // superposait au graphique sur téléphone avec beaucoup d'items).
    function renderCatLegend(bc, catTotal) {
        var el = document.getElementById('ka-leg-category');
        if (!el) return;
        if (!(catTotal > 0)) {
            el.innerHTML = '<p class="ka-leg-empty">Aucune vente sur la période</p>';
            return;
        }
        var html = '';
        (bc.labels || []).forEach(function (label, i) {
            var v = Number((bc.ca || [])[i] || 0);
            html += '<div class="ka-leg-row">'
                + '<span class="ka-leg-dot" style="background:' + palette[i % palette.length] + '"></span>'
                + '<span class="ka-leg-name">' + esc(label) + '</span>'
                + '<span class="ka-leg-val">' + money(v) + '</span>'
                + '<span class="ka-leg-pct">' + pct(v / catTotal * 100) + '</span>'
                + '</div>';
        });
        el.innerHTML = html;
    }

    function renderPmLegend(pm, pmCa, pmTotal) {
        var el = document.getElementById('ka-leg-payment');
        if (!el) return;
        var pmLabels = ['Carte', 'Liquide'];
        var pmColors = [teal, amber];
        if (!(pmTotal > 0)) {
            el.innerHTML = '<p class="ka-leg-empty">Aucune vente sur la période</p>';
            return;
        }
        var html = '';
        pmLabels.forEach(function (label, i) {
            var nb = Number((pm.by_count || {})[(i === 0 ? 'CARTE' : 'LIQUIDE')] || 0);
            html += '<div class="ka-leg-row">'
                + '<span class="ka-leg-dot" style="background:' + pmColors[i] + '"></span>'
                + '<span class="ka-leg-name">' + label + '</span>'
                + '<span class="ka-leg-val">' + money(pmCa[i]) + '</span>'
                + '<span class="ka-leg-pct">' + nb + ' tr. · ' + Math.round(pmCa[i] / pmTotal * 100) + ' %</span>'
                + '</div>';
        });
        el.innerHTML = html;
    }

    function initRepartCharts() {
        var bc = data.byCategory || { labels: [], ca: [] };
        var catTotal = (bc.ca || []).reduce(function (a, b) { return a + Number(b); }, 0);
        var elCat = document.getElementById('ka-chart-category');
        if (elCat) {
            new Chart(elCat, {
                type: 'doughnut',
                data: {
                    labels: bc.labels,
                    datasets: [{ data: bc.ca, backgroundColor: palette, borderColor: 'transparent' }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '64%',
                    plugins: {
                        // Légende désactivée : remplacée par la légende HTML
                        // .ka-leg sous le donut (lisible sur téléphone).
                        legend: { display: false },
                        tooltip: { callbacks: { label: function (c) { return c.label + ' : ' + money(c.raw); } } },
                    },
                },
                plugins: [{
                    id: 'kaCenterText',
                    beforeDraw: function (chart) {
                        var w = chart.width, h = chart.height, ctx = chart.ctx;
                        ctx.save();
                        ctx.font = '600 12px system-ui';
                        ctx.fillStyle = '#9fb3c8';
                        ctx.textAlign = 'center';
                        ctx.fillText('Total', w / 2, h / 2 - 8);
                        ctx.font = '700 15px system-ui';
                        ctx.fillStyle = '#eaf2fb';
                        ctx.fillText(money(catTotal), w / 2, h / 2 + 10);
                        ctx.restore();
                    },
                }],
            });
        }

        var pm = data.payments || { by_ca: {}, by_count: {} };
        var pmLabels = ['Carte', 'Liquide'];
        var pmKeys   = ['CARTE', 'LIQUIDE'];
        var pmCa     = pmKeys.map(function (k) { return Number((pm.by_ca || {})[k] || 0); });
        var pmTotal  = pmCa.reduce(function (a, b) { return a + b; }, 0);
        var elPm = document.getElementById('ka-chart-payment');
        if (elPm) {
            new Chart(elPm, {
                type: 'doughnut',
                data: {
                    labels: pmLabels.map(function (label, i) {
                        var p = pmTotal > 0 ? Math.round(pmCa[i] / pmTotal * 100) : 0;
                        return label + ' ' + p + '%';
                    }),
                    datasets: [{
                        data: pmCa,
                        backgroundColor: [teal, amber],
                        borderColor: 'transparent',
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    plugins: {
                        // Légende désactivée : remplacée par la légende HTML
                        // .ka-leg sous le donut (lisible sur téléphone).
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (c) {
                                    var key = pmKeys[c.dataIndex];
                                    var nb = Number((pm.by_count || {})[key] || 0);
                                    var p = pmTotal > 0 ? (c.raw / pmTotal * 100).toFixed(1).replace('.', ',') : '0';
                                    return c.label + ' : ' + money(c.raw) + ' (' + p + '% · ' + nb + ' transactions)';
                                },
                            },
                        },
                    },
                },
                plugins: [{
                    id: 'kaPmCenter',
                    beforeDraw: function (chart) {
                        var w = chart.width, h = chart.height, ctx = chart.ctx;
                        var cartPct = pmTotal > 0 ? Math.round(pmCa[0] / pmTotal * 100) : 0;
                        ctx.save();
                        ctx.font = '600 11px system-ui';
                        ctx.fillStyle = '#9fb3c8';
                        ctx.textAlign = 'center';
                        ctx.fillText('Carte', w / 2, h / 2 - 9);
                        ctx.font = '800 18px system-ui';
                        ctx.fillStyle = '#48bdd3';
                        ctx.fillText(cartPct + '%', w / 2, h / 2 + 11);
                        ctx.restore();
                    },
                }],
            });
        }

        // Légendes HTML sous les donuts (les légendes Chart.js sont désactivées).
        renderCatLegend(bc, catTotal);
        renderPmLegend(pm, pmCa, pmTotal);
    }

    // ============================================================
    // Heures : graphiques CA par jour de semaine et par heure
    // (initialisés EN RETARDÉ à la première activation de l'onglet),
    // puis heatmap INVERSÉE (DOM pur, construite immédiatement).
    // ============================================================
    function initHeuresCharts() {
        // Barres PLEINES = OBSERVÉ (obs : null = pas de barre), points/
        // ligne = MODÈLE (prochaine occurrence) ; tooltip des deux séries.
        var wd = data.weekday || { labels: [], ca: [], obs: [] };
        var elWd = document.getElementById('ka-chart-weekday');
        if (elWd) {
            var kWdObs = (wd.obs || []).map(function (v) { return v == null ? null : Number(v); });
            var kWdModel = (wd.ca || []).map(function (v) { return v == null ? null : Number(v); });
            var kWdCount = (wd.days_count || []).map(function (v) { return v == null ? 0 : Number(v); });
            // Observé « dont période creuse » (moyenne + nb des jours du dow
            // en plage creuse, null si aucun) — ajouté au tooltip de la barre.
            var kWdObsCreuse = (wd.obs_creuse || []).map(function (v) { return v == null ? null : Number(v); });
            var kWdObsNCreuse = (wd.obs_n_creuse || []).map(function (v) { return v == null ? 0 : Number(v); });
            var kWdTi = Number(wd.today_index);
            new Chart(elWd, {
                type: 'bar',
                data: {
                    labels: wd.labels || [],
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Observé',
                            data: kWdObs,
                            backgroundColor: kWdObs.map(function (v, i) {
                                return i === kWdTi ? 'rgba(245,158,11,.75)' : 'rgba(97,80,170,.55)';
                            }),
                            borderColor: violet,
                            borderWidth: 1,
                            borderRadius: 4,
                            order: 2,
                        },
                        {
                            type: 'line',
                            label: 'Modèle (prochaine occurrence)',
                            data: kWdModel,
                            borderColor: violet,
                            borderDash: [4, 4],
                            backgroundColor: violet,
                            pointRadius: 3,
                            pointHitRadius: 10,
                            tension: 0,
                            fill: false,
                            order: 1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (c) {
                                    if (c.dataset.type === 'bar') {
                                        var t = 'Observé : ' + (c.raw == null ? '—' : money(c.raw))
                                            + ' (n=' + (kWdCount[c.dataIndex] || 0) + ')';
                                        if (kWdObsNCreuse[c.dataIndex] > 0) {
                                            t += ' · dont période creuse : '
                                                + (kWdObsCreuse[c.dataIndex] == null ? '—' : money(kWdObsCreuse[c.dataIndex]))
                                                + ' (n=' + kWdObsNCreuse[c.dataIndex] + ')';
                                        }
                                        return t;
                                    }
                                    return 'Modèle : ' + (c.raw == null ? '—' : money(c.raw));
                                },
                            },
                        },
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: money } } },
                },
            });
        }

        // Barres ambre = CA moyen OBSERVÉ par heure ; tirets violets =
        // attendu du jour ; pointillés verts = projeté fin de journée ;
        // ligne sarcelle = réel du jour tronqué à l'heure courante.
        var hr = data.hourly || { labels: [], ca_avg: [] };
        var elHr = document.getElementById('ka-chart-hourly');
        if (elHr) {
            var kHrHour = Number(hr.now_hour == null ? 0 : hr.now_hour);
            var kHrAvg = (hr.ca_avg || []).map(function (v) { return v == null ? null : Number(v); });
            var kHrToday = (hr.today_ca || []).map(function (v, i) {
                return i <= kHrHour && v != null ? Number(v) : null;
            });
            var kHrExpected = (hr.today_expected || []).map(function (v) { return v == null ? null : Number(v); });
            var kHrProjete = (hr.today_projete || []).map(function (v) { return v == null ? null : Number(v); });
            new Chart(elHr, {
                type: 'line',
                data: {
                    labels: hr.labels || [],
                    datasets: [
                        {
                            type: 'bar',
                            label: 'CA moyen observé (€)',
                            data: kHrAvg,
                            backgroundColor: 'rgba(245,158,11,.55)',
                            borderColor: amber,
                            borderWidth: 1,
                            borderRadius: 3,
                            order: 4,
                        },
                        {
                            type: 'line',
                            label: "Attendu aujourd'hui (€)",
                            data: kHrExpected,
                            borderColor: violet,
                            borderDash: [6, 6],
                            fill: false,
                            tension: 0.3,
                            pointRadius: 0,
                            pointHitRadius: 8,
                            order: 3,
                        },
                        {
                            type: 'line',
                            label: 'Projeté (€)',
                            data: kHrProjete,
                            borderColor: green,
                            borderDash: [2, 4],
                            fill: false,
                            tension: 0.3,
                            pointRadius: 0,
                            pointHitRadius: 8,
                            order: 2,
                        },
                        {
                            type: 'line',
                            label: "Aujourd'hui (€)",
                            data: kHrToday,
                            borderColor: teal,
                            backgroundColor: 'rgba(72,189,211,.15)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 2,
                            pointBackgroundColor: teal,
                            spanGaps: false,
                            order: 1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8, font: { size: 10 } } },
                        tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + (c.raw == null ? '—' : money(c.raw)); } } },
                    },
                    scales: {
                        x: { ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 4, font: { size: 9 } } },
                        y: { beginAtZero: true, ticks: { callback: money } },
                    },
                },
            });
        }
    }

    // ============================================================
    // Heures : heatmap INVERSÉE (DOM pur, construite immédiatement).
    // HEURES en lignes (00h→23h), 7 JOURS en colonnes. Colonnes
    // minmax(0,1fr) → la grille tient à 320px sans scroll horizontal.
    // Le détail d'une case s'affiche au TAP dans la ligne d'info
    // (les tooltips flottants sont inutilisables au doigt).
    // ============================================================
    function initHeatmap() {
        var heat = data.heatmap || [];
        var days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        var heatEl = document.getElementById('ka-heatmap');
        var infoEl = document.getElementById('ka-ht-info');
        if (!heatEl) { return; }

        // Max GLOBAL (toutes les cases partagent la même échelle de couleur).
        var max = 0;
        for (var d = 0; d < 7; d++) {
            for (var h = 0; h < 24; h++) {
                var v = Number(((heat[d] || [])[h]) || 0);
                if (v > max) { max = v; }
            }
        }

        // Cas trivial : aucune vente → message, pas de grille.
        if (!(max > 0)) {
            heatEl.innerHTML = '<p class="ka-ht-empty">Aucune vente sur la période</p>';
            if (infoEl) { infoEl.hidden = true; }
            var legendEl = document.querySelector('.ka-ht-legend');
            if (legendEl) { legendEl.hidden = true; }
            return;
        }

        // Ligne d'entête : coin vide (38px) + 7 jours abrégés.
        var html = '<div class="ka-ht-row"><span class="ka-ht-hour"></span>';
        for (var dd = 0; dd < 7; dd++) { html += '<span class="ka-ht-day">' + days[dd] + '</span>'; }
        html += '</div>';

        // 24 lignes horaires : libellé heure + 7 cellules colorées.
        // alpha = valeur / max global (0.03 plancher pour garder la nuance
        // des petites valeurs ; 0 = case vide, transparente).
        for (var hh = 0; hh < 24; hh++) {
            html += '<div class="ka-ht-row"><span class="ka-ht-hour">' + (hh < 10 ? '0' + hh : hh) + 'h</span>';
            for (var d2 = 0; d2 < 7; d2++) {
                var val = Number(((heat[d2] || [])[hh]) || 0);
                var alpha = val > 0 ? Math.max(val / max, 0.03) : 0;
                html += '<button type="button" class="ka-ht-cell" data-jour="' + d2 + '" data-heure="' + hh + '" data-val="' + val + '"'
                    + ' aria-label="' + days[d2] + ' ' + hh + 'h : ' + money(val) + '"'
                    + ' style="background:rgba(72,189,211,' + alpha.toFixed(3) + ')"></button>';
            }
            html += '</div>';
        }
        heatEl.innerHTML = html;

        // Détail au TAP : un seul listener délégué sur la grille.
        // Re-tap sur la même case → retour au texte par défaut.
        var DEFAULT_INFO = 'Touche une case pour le détail';
        var selected = null;
        heatEl.addEventListener('click', function (e) {
            var cell = e.target && e.target.closest ? e.target.closest('.ka-ht-cell') : null;
            if (!cell) { return; }
            var same = (selected === cell);
            if (selected) { selected.classList.remove('is-sel'); selected = null; }
            if (same) {
                if (infoEl) { infoEl.textContent = DEFAULT_INFO; }
                return;
            }
            cell.classList.add('is-sel');
            selected = cell;
            var jour = Number(cell.getAttribute('data-jour'));
            var heure = Number(cell.getAttribute('data-heure'));
            var montant = Number(cell.getAttribute('data-val'));
            if (infoEl) { infoEl.textContent = days[jour] + ' ' + heure + 'h — ' + money(montant); }
        });
    }

    // ============================================================
    // Produits : rangées-cartes pleine largeur (zéro scroll-x),
    // tri par select — DÉFAUT « Catégorie » (groupement : catégories
    // ordonnées par CA total décroissant, produits par CA décroissant
    // dans chaque catégorie), autres tris = liste plate avec chip.
    // + totaux + export CSV. (DOM pur : construit immédiatement.)
    // ============================================================
    var rows = data.table || [];
    var sortKey = 'category';

    // Bénéfice signé : texte « +12,30 € » / « −4,00 € » + classe couleur.
    function profitParts(v) {
        v = Number(v);
        return {
            cls: v >= 0 ? 'is-pos' : 'is-neg',
            text: (v >= 0 ? '+' : '−') + money(Math.abs(v)),
        };
    }

    // Une carte produit. withCat = chip catégorie (masquée en tri
    // « Catégorie », redondante avec l'entête de section). La marge est
    // OBSERVÉE (coût à la date de vente), distincte de la marge
    // prévisionnelle de la carte « Bénéfice prévu ».
    function cardHtml(r, withCat) {
        var pp = profitParts(r.profit);
        return '<div class="ka-pcard">'
            + '<div class="ka-pcard-top">'
            + '<span class="ka-pcard-name">' + esc(r.product) + '</span>'
            + (withCat ? '<span class="ka-pcard-cat">' + esc(r.category) + '</span>' : '')
            + '</div>'
            + '<div class="ka-pcard-meta"><span>×' + Number(r.qty) + '</span>'
            + '<span>Coût moy. ' + money(r.cost) + '</span></div>'
            + '<div class="ka-pcard-stats">'
            + '<span class="ka-pcard-ca">CA : ' + money(r.ca) + '</span>'
            + '<span class="ka-pcard-profit ' + pp.cls + '">Bénéfice : ' + pp.text + '</span>'
            + '<span class="ka-pcard-margin">Marge observée (coût à la date de vente) ' + Math.round(Number(r.margin)) + ' %</span>'
            + '</div>'
            + '</div>';
    }

    function renderCards() {
        var wrap = document.getElementById('ka-product-cards');
        var totals = document.getElementById('ka-ptotals');
        if (!wrap) { return; }

        // Liste vide → message simple.
        if (!rows.length) {
            wrap.innerHTML = '<p class="ka-pempty">Aucune vente sur la période</p>';
            if (totals) { totals.textContent = ''; }
            return;
        }

        var sorted = rows.slice().sort(function (a, b) {
            return Number(b[sortKey]) - Number(a[sortKey]);
        });

        var totQty = 0, totCa = 0, totProfit = 0;
        rows.forEach(function (r) { totQty += Number(r.qty); totCa += Number(r.ca); totProfit += Number(r.profit); });
        if (totals) {
            var tp = profitParts(totProfit);
            totals.innerHTML = '<span>Total ' + totQty + ' u.</span>'
                + '<span>CA <strong>' + money(totCa) + '</strong></span>'
                + '<span class="ka-ptot-profit ' + tp.cls + '">Bénéfice ' + tp.text + '</span>'
                + '<span>Marge ' + (totCa > 0 ? pct((totProfit / totCa) * 100) : '—') + '</span>';
        }

        // Tri « Catégorie » = GROUPAGE : sections par catégorie (CA total
        // décroissant), produits par CA décroissant dans chaque catégorie,
        // sans chip redondante. Autres tris = liste plate avec chip.
        if (sortKey === 'category') {
            var byCat = {};
            sorted.forEach(function (r) {
                var key = String(r.category == null ? '—' : r.category);
                (byCat[key] = byCat[key] || []).push(r);
            });
            var cats = Object.keys(byCat).map(function (name) {
                // Produits de la catégorie triés par CA décroissant.
                var products = byCat[name].slice().sort(function (a, b) {
                    return Number(b.ca) - Number(a.ca);
                });
                var caTotal = products.reduce(function (a, r) { return a + Number(r.ca); }, 0);
                return { name: name, caTotal: caTotal, products: products };
            }).sort(function (a, b) { return b.caTotal - a.caTotal; });

            wrap.innerHTML = cats.map(function (c) {
                return '<div class="ka-psec">'
                    + '<div class="ka-psec-head">'
                    + '<span class="ka-psec-name">' + esc(c.name) + '</span>'
                    + '<span class="ka-psec-meta">' + c.products.length + ' produits · ' + money(c.caTotal) + '</span>'
                    + '</div>'
                    + c.products.map(function (r) { return cardHtml(r, false); }).join('')
                    + '</div>';
            }).join('');
            return;
        }

        wrap.innerHTML = sorted.map(function (r) { return cardHtml(r, true); }).join('');
    }

    function initProducts() {
        // Tri : re-sert la liste au changement du select (toujours décroissant).
        var sel = document.getElementById('ka-psort');
        if (sel) {
            sel.addEventListener('change', function () {
                sortKey = sel.value;
                renderCards();
            });
        }
        renderCards();

        // Export CSV : mêmes colonnes que l'ancien tableau.
        var btnCsv = document.getElementById('ka-export-csv');
        if (btnCsv) {
            btnCsv.addEventListener('click', function () {
                var header = ['Produit', 'Categorie', 'Qte', 'CA', 'Cout moyen', 'Benefice', 'Marge %'];
                var lines = [header.join(';')];
                rows.forEach(function (r) {
                    lines.push([
                        csvEsc(r.product), csvEsc(r.category), r.qty,
                        Number(r.ca).toFixed(2).replace('.', ','), Number(r.cost).toFixed(2).replace('.', ','),
                        Number(r.profit).toFixed(2).replace('.', ','), Number(r.margin).toFixed(1).replace('.', ',')
                    ].join(';'));
                });
                var blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'analytics_produits.csv';
                a.click();
            });
        }
    }

    function csvEsc(s) {
        s = s == null ? '' : String(s);
        return /[;"\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }

    // Heatmap + cartes produits : construits tout de suite (pas de canvas).
    initHeatmap();
    initProducts();

    // Onglet initial : hash de l'URL (#repart, #heures, #produits),
    // sinon « Vue » (initialisé immédiatement — pane visible).
    var initial = (window.location.hash || '').replace('#', '');
    activate(initial || 'vue', false);
})();
</script>

<style>
/* Racine de la vue : verrou final anti-débordement — même si un élément
   échappait aux règles ci-dessus, AUCUN scroll horizontal n'est possible
   (le navigateur mobile ne dézoome donc jamais la page). */
.ka-page { display: flex; flex-direction: column; gap: 0.6rem; max-width: 100%; overflow-x: clip; }
.ka-page canvas, .ka-page img { max-width: 100%; }

/* ---- En-tête minimal ---- */
.ka-title { margin: 0; font-size: 1.2rem; font-weight: 900; color: var(--foreground); }
.ka-period { margin: 0.1rem 0 0; font-size: 0.78rem; color: var(--muted, #9fb3c8); }

/* ---- Filtres compacts ---- */
.ka-filters {
    display: flex; flex-direction: column; gap: 0.45rem;
    padding: 0.6rem 0.7rem;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border, rgba(255, 255, 255, 0.08));
    border-radius: 12px;
}
.ka-pills { display: flex; flex-wrap: wrap; gap: 0.25rem; }
.ka-pill {
    appearance: none; cursor: pointer; white-space: nowrap;
    font-family: inherit;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: var(--muted, #9fb3c8);
    padding: 0.3rem 0.6rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    transition: all 0.15s;
}
.ka-pill:hover { border-color: rgba(72, 189, 211, 0.4); color: var(--foreground); }
.ka-pill.is-active {
    background: var(--primary, #48bdd3);
    border-color: var(--primary, #48bdd3);
    color: #08172d;
}
/* Pastilles de fenêtre des prévisions : mêmes styles que .ka-pill mais
   classe distincte (liens <a>) pour ne pas capter le handler JS du
   sélecteur de période. */
.ka-fwpill {
    appearance: none; cursor: pointer; white-space: nowrap;
    font-family: inherit; text-decoration: none;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: var(--muted, #9fb3c8);
    padding: 0.3rem 0.6rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    transition: all 0.15s;
}
.ka-fwpill:hover { border-color: rgba(72, 189, 211, 0.4); color: var(--foreground); }
.ka-fwpill.is-active {
    background: var(--primary, #48bdd3);
    border-color: var(--primary, #48bdd3);
    color: #08172d;
}
.ka-custom { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem; }
/* Le display:flex ci-dessus écraserait l'attribut hidden sinon. */
.ka-custom[hidden] { display: none; }
.ka-custom input[type="date"] {
    flex: 1 1 7.5rem; min-width: 0;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: var(--foreground);
    border-radius: 8px;
    padding: 0.32rem 0.45rem;
    font-size: 0.8rem;
}
.ka-custom-sep { color: var(--muted, #9fb3c8); font-size: 0.75rem; }
.ka-selects { display: flex; gap: 0.4rem; }
.ka-field { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.1rem; }
.ka-field label {
    font-size: 0.6rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--muted, #9fb3c8);
}
.ka-field select {
    width: 100%;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: var(--foreground);
    border-radius: 8px;
    padding: 0.35rem 0.5rem;
    font-size: 0.82rem;
}

/* ---- Bandeau KPI 3×2 (2 colonnes sous 360px) ---- */
.ka-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.35rem; }
@media (max-width: 360px) { .ka-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.ka-kpi { display: flex; flex-direction: column; gap: 0.08rem; padding: 0.5rem 0.6rem; }
.ka-kpi-label { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted, #9fb3c8); }
.ka-kpi-value { font-size: 1.2rem; font-weight: 800; color: var(--primary, #48bdd3); line-height: 1.15; }
.ka-kpi-sub { font-size: 0.62rem; color: var(--muted, #9fb3c8); }
.ka-kpi-delta { font-size: 0.66rem; font-weight: 700; }
.ka-kpi-delta.is-up { color: #22c55e; }
.ka-kpi-delta.is-down { color: #ef4444; }
.ka-kpi-delta.is-muted { color: var(--muted, #9fb3c8); font-weight: 400; }

/* ---- Onglets : classes .compta-tabs de compta.css, juste resserrées ---- */
.ka-tabs { margin-bottom: 0.2rem; }

/* ---- Cartes + graphiques (tailles 1:1 lisibles sans zoomer) ---- */
.ka-card { padding: 0.7rem 0.8rem; }
.ka-chart-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.5rem; }
.ka-chart-title { margin: 0; font-size: 1rem; font-weight: 700; color: var(--primary, #48bdd3); }
.ka-sub { font-size: 0.68rem; color: var(--muted, #9fb3c8); text-align: right; min-width: 0; }
.ka-chart { position: relative; }
.ka-chart-trend { height: 260px; }
.ka-chart-top { height: 300px; }
.ka-chart-donut { height: 250px; max-width: 280px; margin: 0 auto; }
.ka-chart-bar { height: 210px; }

/* ---- Prévisions : fenêtre d'apprentissage + chips moteur (jour/hier) ---- */
.ka-fc { display: flex; flex-direction: column; gap: 0.2rem; padding: 0.6rem 0.7rem; }
.ka-fc .ka-kpi-delta { font-size: 0.78rem; line-height: 1.4; }
.ka-fc-note { font-size: 0.66rem; color: var(--muted, #9fb3c8); }
.ka-fc-win { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; margin-bottom: 0.2rem; }
.ka-fc-win-label { font-size: 0.68rem; font-weight: 700; color: var(--muted, #9fb3c8); }
/* Donuts empilés sur téléphone (assez larges pour être lus en 1:1),
   côte à côte uniquement sur écran large. */
.ka-duo { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0.6rem; }
@media (min-width: 640px) { .ka-duo { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); } }

/* Garde locale : un pane masqué ne doit JAMAIS prendre de largeur
   (compta.css a déjà .compta-tabpane[hidden], on redouble ici). */
.ka-pane[hidden] { display: none; }

/* ---- Légendes HTML des donuts (remplacent la légende Chart.js),
        tailles 1:1 lisibles sans zoomer ---- */
.ka-leg { margin-top: 0.55rem; }
.ka-leg-row {
    display: flex; align-items: center; gap: 0.5rem;
    min-width: 0;
    padding: 0.55rem 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    font-size: 1rem;
    line-height: 1.35;
}
.ka-leg-row:last-child { border-bottom: none; }
.ka-leg-dot { flex: 0 0 auto; width: 12px; height: 12px; border-radius: 50%; }
.ka-leg-name { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ka-leg-val { flex: 0 0 auto; font-size: 1rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.ka-leg-pct { flex: 0 0 auto; font-size: 0.85rem; color: var(--muted, #9fb3c8); white-space: nowrap; }
.ka-leg-empty { margin: 0; padding: 0.55rem 0; font-size: 1rem; color: var(--muted, #9fb3c8); text-align: center; }

/* ---- Heures : heatmap INVERSÉE (heures en lignes, jours en colonnes).
        Colonnes minmax(0,1fr) : zéro débordement, tient à 320px comme au
        verrou overflow-x:clip de .ka-page ; lecture verticale naturelle. ---- */
.ka-ht { display: grid; grid-template-columns: 38px repeat(7, minmax(0, 1fr)); gap: 2px; }
.ka-ht-row { display: contents; }
.ka-ht-day {
    font-size: 0.6rem; font-weight: 700;
    color: var(--muted, #9fb3c8);
    text-align: center; padding-bottom: 2px;
}
.ka-ht-hour {
    font-size: 0.68rem; font-weight: 600;
    color: var(--muted, #9fb3c8);
    text-align: left;
}
.ka-ht-cell {
    height: 21px; padding: 0; border: none; border-radius: 4px;
    background: rgba(72, 189, 211, 0.03);
    cursor: pointer;
}
/* Retour visuel de la case touchée (info affichée sous la grille). */
.ka-ht-cell.is-sel { outline: 2px solid #48bdd3; outline-offset: -2px; }
.ka-ht-info {
    min-height: 1.4em; /* réserve la place : pas de saut de layout au tap */
    margin: 0.5rem 0 0;
    font-size: 0.85rem;
    color: var(--foreground);
}
.ka-ht-info[hidden] { display: none; }
.ka-ht-legend[hidden] { display: none; }
.ka-ht-empty { margin: 0; font-size: 0.9rem; color: var(--muted, #9fb3c8); }
.ka-ht-legend { display: flex; align-items: center; gap: 0.4rem; justify-content: flex-end; margin-top: 0.5rem; }
.ka-ht-legend-label { font-size: 0.65rem; color: var(--muted, #9fb3c8); }
.ka-ht-legend-bar {
    width: 90px; height: 10px; border-radius: 5px;
    background: linear-gradient(90deg, rgba(72, 189, 211, 0.05), rgba(72, 189, 211, 1));
}

/* ---- Insights compacts (empilés) ---- */
.ka-insights { display: flex; flex-direction: column; gap: 0.4rem; }
.ka-insight { display: flex; gap: 0.55rem; align-items: flex-start; padding: 0.6rem 0.7rem; border-left: 3px solid #48bdd3; }
.ka-insight--green { border-left-color: #22c55e; }
.ka-insight--red   { border-left-color: #ef4444; }
.ka-insight--amber { border-left-color: #f59e0b; }
.ka-insight-icon { color: #48bdd3; }
.ka-insight--green .ka-insight-icon { color: #22c55e; }
.ka-insight--red .ka-insight-icon   { color: #ef4444; }
.ka-insight--amber .ka-insight-icon { color: #f59e0b; }
.ka-insight-icon svg { width: 18px; height: 18px; display: block; }
.ka-insight-title { margin: 0 0 0.1rem; font-size: 0.78rem; font-weight: 700; }
.ka-insight-text { margin: 0; font-size: 0.72rem; color: var(--muted, #9fb3c8); }

/* ---- Produits : rangées-cartes pleine largeur (fin du tableau
        scrollable — tout est visible sans défilement horizontal) ---- */
.ka-ptools { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.5rem; }
.ka-psort { display: flex; align-items: center; gap: 0.4rem; min-width: 0; }
.ka-psort span {
    font-size: 0.62rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--muted, #9fb3c8);
}
.ka-psort select {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: var(--foreground);
    border-radius: 8px;
    padding: 0.35rem 0.5rem;
    font-size: 0.85rem;
}
.ka-ptotals {
    display: flex; flex-wrap: wrap; gap: 0.3rem 0.9rem;
    margin: 0 0 0.6rem; padding: 0.5rem 0.6rem;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border, rgba(255, 255, 255, 0.08));
    border-radius: 10px;
    font-size: 0.78rem; color: var(--muted, #9fb3c8);
}
.ka-ptot-profit.is-pos { color: #22c55e; }
.ka-ptot-profit.is-neg { color: #ef4444; }
/* Sections du tri « Catégorie » : entête lisible + aération généreuse
   entre sections (1rem), entête → première carte (0.5rem). */
.ka-psec { margin-bottom: 1rem; }
.ka-psec:last-child { margin-bottom: 0; }
.ka-psec-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; min-width: 0; margin-bottom: 0.5rem; }
.ka-psec-name { flex: 1 1 auto; min-width: 0; font-weight: 700; font-size: 0.95rem; line-height: 1.3; }
.ka-psec-meta { flex: 0 0 auto; font-size: 0.72rem; color: var(--muted, #9fb3c8); white-space: nowrap; }
.ka-pcard {
    padding: 0.7rem 0.8rem;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.035);
    border: 1px solid rgba(255, 255, 255, 0.07);
    margin-bottom: 0.7rem;
    /* Aération interne : les 3 lignes de la carte respirent. */
    display: flex; flex-direction: column; gap: 0.3rem;
    line-height: 1.45;
}
.ka-pcard:last-child { margin-bottom: 0; }
.ka-pcard-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; }
.ka-pcard-name {
    flex: 1 1 auto; min-width: 0;
    font-weight: 700; font-size: 0.92rem; line-height: 1.25;
    /* Wrap sur 2 lignes max, ellipsis au-delà ; overflow-wrap pour les
       mots très longs (URL, références) qui ne doivent jamais pousser. */
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    overflow-wrap: anywhere;
}
.ka-pcard-cat {
    flex: 0 0 auto;
    font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;
    color: #48bdd3;
    background: rgba(72, 189, 211, 0.12);
    border-radius: 999px;
    padding: 0.18rem 0.55rem;
}
.ka-pcard-meta { display: flex; align-items: baseline; font-size: 0.78rem; color: var(--muted, #9fb3c8); }
/* Séparateur « · » entre les méta-informations. */
.ka-pcard-meta > * + *::before { content: '·'; margin-right: 0.45rem; color: rgba(159, 179, 200, 0.6); }
/* Les enfants flex portant du texte ne peuvent jamais forcer la largeur. */
.ka-pcard-stats > span, .ka-ptotals > span, .ka-pcard-meta > span { min-width: 0; }
.ka-pcard-stats { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.6rem; }
.ka-pcard-ca { font-size: 1rem; font-weight: 800; color: #48bdd3; font-variant-numeric: tabular-nums; }
.ka-pcard-profit { font-size: 0.85rem; font-weight: 700; font-variant-numeric: tabular-nums; }
.ka-pcard-profit.is-pos { color: #22c55e; }
.ka-pcard-profit.is-neg { color: #ef4444; }
.ka-pcard-margin { font-size: 0.78rem; color: var(--muted, #9fb3c8); }
.ka-pempty { margin: 0; font-size: 0.9rem; color: var(--muted, #9fb3c8); }

/* ---- Divers ---- */
.ka-nosales { margin: 0; font-size: 0.78rem; color: var(--muted, #9fb3c8); }
.ka-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: center; margin: 0.6rem 0 1rem; }

/* ---- Statut « Hors fourchette » (anomalie, ambre) + bandeau
        « Qualité des données » repliable ---- */
.ka-kpi-delta.is-off { color: #f59e0b; }
.ka-dataq { padding: 0.55rem 0.7rem; }
.ka-dataq summary {
    cursor: pointer;
    font-size: 0.7rem; font-weight: 700;
    color: var(--muted, #9fb3c8);
    line-height: 1.4;
}
.ka-dataq .ka-fc-note { margin-top: 0.25rem; }
</style>
