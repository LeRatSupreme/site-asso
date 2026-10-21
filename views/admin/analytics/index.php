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
 * @var array<string,mixed> $weekday       Par jour de semaine {labels, ca (modèle : attendu prochaine occurrence), obs (moyenne brute observée|null), se, next_date, qty, tx, days_count (occ), today_index, best?{index,label,ca,n}|null, creuse_note?string|null}
 * @var array<string,mixed> $hourly        Par heure {labels, ca_avg, today_ca, today_expected, today_projete, now_hour, trop_tot, s}
 * @var float $goal                        Objectif hebdomadaire affiché (GET ?goal= ou sauvegarde POST /admin/analytics/objectif)
 * @var list<string> $products             Produits distincts (sélecteur tendance)
 * @var string $productSelected            Produit suivi par la tendance hebdo
 * @var list<array{week:string,ca:float,qty:int}> $productWeekly Tendance hebdo du produit
 * @var string $json  Payload JSON injecté dans un <script>.
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
?>

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
        // Note de croissance du moteur (payload $json > meta) : si la
        // période précédente est incomplète, les deltas sont null côté
        // payload — on affiche la note, SANS recomposer de delta.
        $payloadDecoded = is_string($json ?? null) ? json_decode($json, true) : null;
        $payloadMeta    = is_array($payloadDecoded) ? (array) ($payloadDecoded['meta'] ?? []) : [];
        $croissanceNote = trim((string) ($payloadMeta['croissance_note'] ?? ''));

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
                <h2 class="chart-title">Évolution du CA et du bénéfice</h2>
                <span class="chart-sub" id="trend-sub"></span>
            </div>
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
        $fcTooltip = 'Modèle actif : ' . ($fcModele !== '' ? $fcModele : '—')
            . ' · WAPE backtest : ' . ($fcWape === null ? '—' : number_format((float) $fcWape, 1, ',', ' ')) . ' %'
            . ' sur ' . $fcNJours . ' jours'
            . ' · couverture intervalle : ' . ($fcCoverage === null ? '—' : number_format((float) $fcCoverage, 0, ',', ' ')) . ' %'
            . ($fcFacteur !== null ? ' · Correction conforme : ×' . number_format($fcFacteur, 1, ',', ' ') : '')
            . ($fcNiveau !== null ? ' · Niveau ≈ ' . number_format($fcNiveau, 0, ',', ' ') . ' €' : '')
            . ($fcTrendActive === false
                ? ' · Tendance désactivée (historique < 6 semaines)'
                : ($fcTendance !== null ? ' · Tendance ≈ ' . number_format($fcTendance, 1, ',', ' ') . ' %/jour' : ''))
            . ($fcNoteSel !== '' ? ' · ' . $fcNoteSel : '');

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
            if (isset($fcHier['reel']) && $fcHier['reel'] !== null) {
                $txt .= ' (' . $fcSigned((float) $fcHier['reel'] - (float) $fcHier['prevu']) . ')';
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
                    <h2 class="chart-title">Prévisions<span class="fc-info" title="<?= e($fcTooltip) ?>" aria-label="<?= e($fcTooltip) ?>">ⓘ</span></h2>
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
                    <span class="kpi-delta is-off">Baseline naïve active (le modèle avancé ne bat pas la référence)</span>
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
                    <h2 class="chart-title">Estimation du jour<span class="fc-info" title="<?= e($fcTooltip) ?>" aria-label="<?= e($fcTooltip) ?>">ⓘ</span></h2>
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
                <h2 class="chart-title">Top 10 produits</h2>
                <span class="chart-sub">CA · cliquer pour le détail</span>
            </div>
            <div class="chart-canvas"><canvas id="chart-top" height="160"></canvas></div>
        </div>

        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title">Répartition par catégorie</h2>
                <span class="chart-sub">CA</span>
            </div>
            <div class="chart-canvas chart-canvas-square"><canvas id="chart-category"></canvas></div>
        </div>

        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title">Répartition des paiements</h2>
                <span class="chart-sub">CA · transactions</span>
            </div>
            <div class="chart-canvas chart-canvas-square"><canvas id="chart-payment"></canvas></div>
        </div>

        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title">Par heure</h2>
                <span class="chart-sub" id="hourly-sub"><?php
                // Sous-titre 100 % serveur (une seule source : le payload du
                // moteur). « Attendu aujourd'hui » = JOURNÉE (prevision_jour +
                // intervalle_80) ; la partie horaire reste DISTINCTE :
                // « Attendu à Nh » depuis day_track.expected_so_far /
                // intervalle_h, ou « — (trop tôt) » quand le profil horaire
                // couvre < 10 % de la journée (attendu horaire masqué).
                // Jour fermé → « Fermé aujourd'hui », jamais « 0,00 € ».
                $phPrev  = isset($forecast['prevision_jour']) ? $forecast['prevision_jour'] : null;
                $phIv    = isset($forecast['intervalle_80']) && is_array($forecast['intervalle_80']) ? $forecast['intervalle_80'] : null;
                $phFerme = $phPrev === null
                    && in_array("Fermé aujourd'hui.", (array) ($forecast['notes'] ?? []), true);
                $phH    = (int) ($day_track['hour'] ?? 0);
                $phTrop = !empty($day_track['trop_tot']);
                $phExp  = isset($day_track['expected_so_far']) ? $day_track['expected_so_far'] : null;
                $phIvH  = isset($day_track['intervalle_h']) && is_array($day_track['intervalle_h']) ? $day_track['intervalle_h'] : null;
                $phPeak  = null;
                $phPeakV = 0.0;
                foreach ((isset($hourly['ca_avg']) && is_array($hourly['ca_avg']) ? $hourly['ca_avg'] : []) as $phHr => $phVal) {
                    if ($phVal !== null && (float) $phVal > $phPeakV) {
                        $phPeakV = (float) $phVal;
                        $phPeak  = (int) $phHr;
                    }
                }
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
        $semProb = $sem['prob_atteinte'] ?? null;
        $semManq = $sem['manquant_par_jour'] ?? null;
        $semRest = (int) ($sem['jours_restants'] ?? 0);
        $m30     = isset($forecast['mois30']) && is_array($forecast['mois30']) ? $forecast['mois30'] : [];
        $m30Att  = $m30['attendu'] ?? null;
        $m30Iv   = (($m30['lo'] ?? null) !== null && ($m30['hi'] ?? null) !== null)
            ? ['lo' => $m30['lo'], 'hi' => $m30['hi']] : null;
        // prob_atteinte est une FRACTION 0–1 (P(total ≥ objectif)) : le
        // moteur ne renvoie jamais 0–100 — l'affichage fait le ×100.
        $probPct = $semProb === null ? null : max(0.0, min(100.0, (float) $semProb * 100.0));
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
                    <li><span>Horizon 30 jours</span><strong><?= $m30Att === null ? '—' : e(formatPrice((float) $m30Att) . $fcRange($m30Iv)) ?></strong></li>
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
        $reappro = isset($forecast['produits']) && is_array($forecast['produits']) ? $forecast['produits'] : [];
        ?>
        <div class="card surface glass chart-card chart-span-2">
            <div class="chart-head">
                <h2 class="chart-title">Réapprovisionnement</h2>
                <span class="chart-sub">besoins estimés — horizon 7 jours ouverts</span>
            </div>
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
                                <?php $sugg = array_key_exists('suggestion', $p) && $p['suggestion'] !== null ? (float) $p['suggestion'] : null; ?>
                                <tr>
                                    <td><?= e((string) ($p['label'] ?? '')) ?></td>
                                    <td><?= e((string) ($p['category'] ?? '—')) ?></td>
                                    <td class="num"><?= e(number_format((float) ($p['unites'] ?? 0), 0, ',', ' '))
                                        ?> (<?= e(number_format((float) ($p['lo'] ?? 0), 0, ',', ' ')) ?>–<?= e(number_format((float) ($p['hi'] ?? 0), 0, ',', ' ')) ?>)</td>
                                    <td class="num"><?= e(number_format((float) ($p['safety_demand'] ?? 0), 1, ',', ' ')) ?></td>
                                    <td class="num"><?= !array_key_exists('stock', $p) || $p['stock'] === null ? '—' : e(number_format((float) $p['stock'], 0, ',', ' ')) ?></td>
                                    <td class="num<?= $sugg !== null && $sugg > 0 ? ' reappro-sugg' : '' ?>"><?= $sugg === null ? '—' : e(number_format($sugg, 0, ',', ' ')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php
        // Totaux de la tendance produit.
        $pwCa  = array_sum(array_column($productWeekly, 'ca'));
        $pwQty = array_sum(array_column($productWeekly, 'qty'));
        ?>
        <div class="card surface glass chart-card">
            <div class="chart-head">
                <h2 class="chart-title">Par jour de semaine</h2>
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
                    <h2 class="chart-title">Tendance produit (semaines)</h2>
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
    <!--  Qualité des données : anomalies du moteur + notes (repliable) -->
    <!-- ============================================================ -->
    <?php
    $anomAtyp  = isset($forecast['anomalies']['jours_atypiques']) && is_array($forecast['anomalies']['jours_atypiques']) ? $forecast['anomalies']['jours_atypiques'] : [];
    $anomCout  = isset($forecast['anomalies']['produits_cout_suspect']) && is_array($forecast['anomalies']['produits_cout_suspect']) ? $forecast['anomalies']['produits_cout_suspect'] : [];
    $anomNotes = isset($forecast['notes']) && is_array($forecast['notes']) ? $forecast['notes'] : [];
    $anomFusion = isset($forecast['anomalies']['cles_fusionnees']) && is_array($forecast['anomalies']['cles_fusionnees']) ? $forecast['anomalies']['cles_fusionnees'] : [];
    $anomSansStock = isset($forecast['anomalies']['produits_sans_stock']) && is_array($forecast['anomalies']['produits_sans_stock']) ? $forecast['anomalies']['produits_sans_stock'] : [];
    $anomNSansStock = (int) ($forecast['anomalies']['n_produits_sans_stock'] ?? 0);
    ?>
    <details class="card surface glass dataq">
        <summary>
            Qualité des données — <?= (int) ($forecast['anomalies']['n_jours_inclus'] ?? 0) ?> jours inclus
            · <?= count($anomAtyp) ?> jour<?= count($anomAtyp) > 1 ? 's' : '' ?> atypique<?= count($anomAtyp) > 1 ? 's' : '' ?> exclu(s)
            · <?= (int) ($forecast['anomalies']['ventes_zero'] ?? 0) ?> vente(s) à 0 €
            · <?= (int) ($forecast['anomalies']['doublons_ignores'] ?? 0) ?> doublon(s) ignoré(s)
            · <?= count($anomCout) ?> produit(s) à coût suspect
            <?= $anomNSansStock > 0 ? '· ' . $anomNSansStock . ' produit(s) sans stock' : '' ?>
        </summary>
        <div class="dataq-body">
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
                <p><strong><?= $anomNSansStock ?> produit<?= $anomNSansStock > 1 ? 's' : '' ?> non rattaché<?= $anomNSansStock > 1 ? 's' : '' ?> au stock<?= $anomSansStock !== [] ? ' (' . e(implode(', ', array_map('strval', $anomSansStock))) . ')' : '' ?> :</strong> rattache-les via les alias/product_stocks pour des suggestions.</p>
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
                <h2 class="chart-title">Heatmap des ventes (jour × heure)</h2>
                <span class="chart-sub" id="heatmap-sub">Intensité du CA — <?= e($filters['category'] === 'all' ? 'Toutes catégories' : $filters['category']) ?> · <?= e($filters['payment'] === 'all' ? 'Tous paiements' : $filters['payment']) ?></span>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    var data = <?= $json ?>;

    // Mode kiosque : les liens vers l'espace admin sont désactivés.
    var kiosk = <?= $kiosk ? 'true' : 'false' ?>;

    // ---------- Heatmap filter sync ----------
    var hmCat = document.getElementById('hm-cat');
    if (hmCat) {
        // Sync valeur actuelle.
        var curCat = '<?= e($filters['category']) ?>';
        if (curCat !== 'all') { hmCat.value = curCat; }
        hmCat.addEventListener('change', function () {
            // Met à jour le select catégorie global et soumet le form.
            var globalCat = document.querySelector('select[name="category"]');
            if (globalCat) {
                globalCat.value = hmCat.value || 'all';
            }
            document.getElementById('analytics-filters').submit();
        });
    }

    // ---------- Affichage du sélecteur de dates custom + pills ----------
    var customBox = document.getElementById('filter-custom');
    var periodHidden = document.getElementById('period-hidden');
    function syncCustom() {
        var val = periodHidden.value;
        customBox.hidden = val !== 'custom';
    }
    document.querySelectorAll('.af-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('.af-pill').forEach(function (p) { p.classList.remove('is-active'); });
            pill.classList.add('is-active');
            periodHidden.value = pill.getAttribute('data-period');
            syncCustom();
            // Application immédiate : la pastille soumet le formulaire,
            // sauf « Personnalisé » qui affiche d'abord les champs de dates.
            if (periodHidden.value !== 'custom') {
                document.getElementById('analytics-filters').submit();
            }
        });
    });

    // Saisie manuelle d'une date → bascule immédiate sur « Personnalisé »,
    // avec application automatique dès que les deux bornes sont remplies.
    var customInputs = [
        customBox.querySelector('input[name="from"]'),
        customBox.querySelector('input[name="to"]')
    ];
    Array.prototype.forEach.call(customInputs, function (input) {
        if (!input) return;
        input.addEventListener('change', function () {
            var customPill = document.querySelector('.af-pill[data-period="custom"]');
            document.querySelectorAll('.af-pill').forEach(function (p) { p.classList.remove('is-active'); });
            if (customPill) customPill.classList.add('is-active');
            periodHidden.value = 'custom';
            syncCustom();
            var from = customInputs[0], to = customInputs[1];
            if (from && to && from.value && to.value) {
                document.getElementById('analytics-filters').submit();
            }
        });
    });
    syncCustom();

    // ---------- Chart.js defaults ----------
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

    // ---------- 1. Évolution CA + bénéfice (bar CA + line profit, double axe) ----------
    var tr = data.trend || { labels: [], ca: [], profit: [] };
    var sub = document.getElementById('trend-sub');
    if (sub) {
        var g = (data.filters || {}).granularity || 'auto';
        sub.textContent = g.charAt(0).toUpperCase() + g.slice(1);
    }
    var ctxTrend = document.getElementById('chart-trend');
    if (ctxTrend) {
        new Chart(ctxTrend, {
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
                        yAxisID: 'y',
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
                        yAxisID: 'y',
                        order: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + money(c.raw); } } },
                },
                scales: { y: { beginAtZero: true, ticks: { callback: money } } },
            },
        });
    }

    // ---------- 1bis. Prévisions : réalisé observé + courbe du modèle + bande 80 % ----------
    // Barres = CA passé OBSERVÉ (payload historique ; barre du jour en
    // ambre via today_index), ligne violette en tirets = prévision (point
    // du jour + 7 jours J+1..J+7), bande violette = fourchette 80 %
    // (lo–hi). Toutes les valeurs viennent du payload — aucun recalcul.
    var fc = data.forecast || null;
    var hist = data.historique || { labels: [], values: [], today_index: -1 };
    var ctxFc = document.getElementById('chart-forecast');
    if (ctxFc && fc) {
        var fLabels = (hist.labels || []).map(String);
        var fBars   = (hist.values || []).map(function (v) { return v == null ? null : Number(v); });
        var fTi     = Number(hist.today_index);
        var fPrev   = fc.prevision_jour;
        var fIv     = fc.intervalle_80 || null;
        var fLine = fBars.map(function () { return null; });
        var fLo   = fBars.map(function () { return null; });
        var fHi   = fBars.map(function () { return null; });
        if (fPrev != null) {
            fLabels.push('Auj.');
            fBars.push(null);
            fLine.push(Number(fPrev));
            // Bande plancher 0 : un CA négatif n'existe pas (le lo PHP est
            // déjà clampé ; ce clamp JS garantit l'axe côté client).
            fLo.push(fIv && fIv.lo != null ? Math.max(0, Number(fIv.lo)) : null);
            fHi.push(fIv && fIv.hi != null ? Number(fIv.hi) : null);
        }
        (fc.jours || []).forEach(function (d) {
            fLabels.push(String(d.label));
            fBars.push(null);
            fLine.push(d.is_open && d.ca != null ? Number(d.ca) : null);
            fLo.push(d.is_open && d.lo != null ? Math.max(0, Number(d.lo)) : null);
            fHi.push(d.is_open && d.hi != null ? Number(d.hi) : null);
        });
        var fBg = fBars.map(function (v, i) {
            return i === fTi ? 'rgba(245,158,11,.75)' : 'rgba(72,189,211,.55)';
        });
        new Chart(ctxFc, {
            type: 'line',
            data: {
                labels: fLabels,
                datasets: [
                    {
                        // Bande 80 % : remplissage entre hi (ce dataset,
                        // fill vers le suivant) et lo. Masquée légende/tooltips.
                        label: 'Fourchette 80 %',
                        data: fHi,
                        borderColor: 'transparent',
                        backgroundColor: 'rgba(97,80,170,.14)',
                        fill: '+1',
                        pointRadius: 0,
                        pointHitRadius: 0,
                        order: 0,
                    },
                    {
                        label: 'Borne basse',
                        data: fLo,
                        borderColor: 'transparent',
                        pointRadius: 0,
                        pointHitRadius: 0,
                        fill: false,
                        order: 0,
                    },
                    {
                        type: 'bar',
                        label: 'CA réalisé (€)',
                        data: fBars,
                        backgroundColor: fBg,
                        borderColor: teal,
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2,
                    },
                    {
                        label: 'Prévision (€)',
                        data: fLine,
                        borderColor: violet,
                        borderDash: [6, 6],
                        pointRadius: 3,
                        pointBackgroundColor: violet,
                        fill: false,
                        spanGaps: false,
                        tension: 0.25,
                        order: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { filter: function (item) { return item.text !== 'Fourchette 80 %' && item.text !== 'Borne basse'; } },
                    },
                    tooltip: {
                        filter: function (t) { return t.dataset.label !== 'Fourchette 80 %' && t.dataset.label !== 'Borne basse'; },
                        callbacks: {
                            label: function (c) {
                                if (c.dataset.label === 'Prévision (€)' && c.raw != null
                                    && fLo[c.dataIndex] != null && fHi[c.dataIndex] != null) {
                                    return 'Prévision : ' + money(c.raw)
                                        + ' (fourchette ' + money(fLo[c.dataIndex]) + ' – ' + money(fHi[c.dataIndex]) + ')';
                                }
                                return c.dataset.label + ' : ' + (c.raw == null ? '—' : money(c.raw));
                            },
                        },
                    },
                },
                scales: { y: { beginAtZero: true, ticks: { callback: money } } },
            },
        });
    }

    // ---------- 1ter. Estimation du jour : cumul réel vs attendu ----------
    // Le cumul réel s'arrête à l'heure courante (null au-delà), la
    // courbe attendue court sur 0h→23h (tirets violets). Valeurs du
    // payload day_track (format plat : cum_actual / cum_expected).
    var dt = data.day_track || null;
    var ctxDt = document.getElementById('chart-daytrack');
    if (ctxDt && dt && dt.estimate != null && Number(dt.estimate) > 0) {
        var dtHour = Number(dt.hour == null ? 0 : dt.hour);
        var dtLabels = [];
        for (var di = 0; di < 24; di++) { dtLabels.push(di + 'h'); }
        var dtToday = (dt.cum_actual || []).map(function (v, i) {
            return i <= dtHour && v != null ? Number(v) : null;
        });
        var dtExpected = (dt.cum_expected || []).map(function (v) {
            return v == null ? null : Number(v);
        });
        new Chart(ctxDt, {
            type: 'line',
            data: {
                labels: dtLabels,
                datasets: [
                    {
                        label: "Aujourd'hui (cumulé €)",
                        data: dtToday,
                        borderColor: teal,
                        backgroundColor: 'rgba(72,189,211,.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: teal,
                        spanGaps: false,
                    },
                    {
                        label: 'Attendu (cumulé €)',
                        data: dtExpected,
                        borderColor: violet,
                        borderDash: [6, 6],
                        fill: false,
                        tension: 0.3,
                        pointRadius: 0,
                        pointHitRadius: 8,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + (c.raw == null ? '—' : money(c.raw)); } } },
                },
                scales: {
                    x: { ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 4 } },
                    y: { beginAtZero: true, ticks: { callback: money } },
                },
            },
        });
    }

    // ---------- 2. Top 10 produits (bar horizontal, cliquable) ----------
    var tp = data.topProducts || { labels: [], ca: [] };
    var ctxTop = document.getElementById('chart-top');
    if (ctxTop) {
        var grad = ctxTop.getContext('2d').createLinearGradient(0, 0, 600, 0);
        grad.addColorStop(0, teal);
        grad.addColorStop(1, violet);
        new Chart(ctxTop, {
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
                onClick: function () { if (!kiosk) window.location.href = '<?= e(url('/admin/compta/produits')) ?>'; },
            },
        });
    }

    // ---------- 3. Répartition par catégorie (donut, centre = total) ----------
    var bc = data.byCategory || { labels: [], ca: [] };
    var catTotal = (bc.ca || []).reduce(function (a, b) { return a + Number(b); }, 0);
    var ctxCat = document.getElementById('chart-category');
    if (ctxCat) {
        new Chart(ctxCat, {
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
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
                    tooltip: { callbacks: { label: function (c) { return c.label + ' : ' + money(c.raw); } } },
                },
            },
            plugins: [{
                id: 'centerText',
                beforeDraw: function (chart) {
                    var w = chart.width, h = chart.height, ctx = chart.ctx;
                    ctx.save();
                    ctx.font = '600 14px system-ui';
                    ctx.fillStyle = '#9fb3c8';
                    ctx.textAlign = 'center';
                    ctx.fillText('Total', w / 2, h / 2 - 10);
                    ctx.font = '700 18px system-ui';
                    ctx.fillStyle = '#eaf2fb';
                    ctx.fillText(money(catTotal), w / 2, h / 2 + 14);
                    ctx.restore();
                },
            }],
        });
    }

    // ---------- 4. Répartition des paiements (donut CA + % au centre) ----------
    var pm = data.payments || { by_ca: {}, by_count: {} };
    var pmLabels = ['Carte', 'Liquide'];
    var pmKeys   = ['CARTE', 'LIQUIDE'];
    var pmCa     = pmKeys.map(function (k) { return Number((pm.by_ca || {})[k] || 0); });
    var pmTotal  = pmCa.reduce(function (a, b) { return a + b; }, 0);
    var ctxPm = document.getElementById('chart-payment');
    if (ctxPm) {
        new Chart(ctxPm, {
            type: 'doughnut',
            data: {
                labels: pmLabels.map(function (label, i) {
                    var pct = pmTotal > 0 ? Math.round(pmCa[i] / pmTotal * 100) : 0;
                    return label + ' ' + pct + '%';
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
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (c) {
                                var key = pmKeys[c.dataIndex];
                                var nb = Number((pm.by_count || {})[key] || 0);
                                var pct = pmTotal > 0 ? (c.raw / pmTotal * 100).toFixed(1).replace('.', ',') : '0';
                                return c.label + ' : ' + money(c.raw) + ' (' + pct + '% · ' + nb + ' transactions)';
                            },
                        },
                    },
                },
            },
            plugins: [{
                id: 'pmCenter',
                beforeDraw: function (chart) {
                    var w = chart.width, h = chart.height, ctx = chart.ctx;
                    var cartPct = pmTotal > 0 ? Math.round(pmCa[0] / pmTotal * 100) : 0;
                    ctx.save();
                    ctx.font = '600 12px system-ui';
                    ctx.fillStyle = '#9fb3c8';
                    ctx.textAlign = 'center';
                    ctx.fillText('Carte', w / 2, h / 2 - 12);
                    ctx.font = '800 22px system-ui';
                    ctx.fillStyle = '#48bdd3';
                    ctx.fillText(cartPct + '%', w / 2, h / 2 + 14);
                    ctx.restore();
                },
            }],
        });
    }

    // ---------- 6. Activité de l'association (multi-line) ----------
    var ac = data.activity || { labels: [], registrations: [], members: [], votes: [] };
    var ctxAc = document.getElementById('chart-activity');
    if (ctxAc) {
        new Chart(ctxAc, {
            type: 'line',
            data: {
                labels: ac.labels,
                datasets: [
                    { label: 'Inscriptions événements', data: ac.registrations, borderColor: violet, backgroundColor: 'rgba(97,80,170,.12)', tension: 0.35, pointBackgroundColor: violet },
                    { label: 'Nouveaux membres',        data: ac.members,        borderColor: teal,    backgroundColor: 'rgba(72,189,211,.12)', tension: 0.35, pointBackgroundColor: teal },
                    { label: 'Votes sondages',          data: ac.votes,          borderColor: amber,   backgroundColor: 'rgba(245,158,11,.12)', tension: 0.35, pointBackgroundColor: amber },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }

    // ---------- 5bis. CA par jour de semaine : OBSERVÉ (barres) + MODÈLE (points/ligne) ----------
    // Barres PLEINES = moyenne OBSERVÉE par dow (obs : null = pas de
    // barre), points/ligne tiretée = MODÈLE (attendu de la prochaine
    // occurrence). « Meilleur jour » (seulement s'il est significatif) et
    // la mention période creuse viennent du payload — AUCUN calcul local.
    var wd = data.weekday || { labels: [], ca: [], obs: [] };
    var ctxWd = document.getElementById('chart-weekday');
    if (ctxWd) {
        var wdObs = (wd.obs || []).map(function (v) { return v == null ? null : Number(v); });
        var wdModel = (wd.ca || []).map(function (v) { return v == null ? null : Number(v); });
        var wdDaysCount = (wd.days_count || []).map(function (v) { return v == null ? 0 : Number(v); });
        // Observé « dont période creuse » (moyenne + nb des jours du dow en
        // plage creuse, null si aucun) — ajouté au tooltip de la barre.
        var wdObsCreuse = (wd.obs_creuse || []).map(function (v) { return v == null ? null : Number(v); });
        var wdObsNCreuse = (wd.obs_n_creuse || []).map(function (v) { return v == null ? 0 : Number(v); });
        var wdTi  = Number(wd.today_index);
        var wdBest = wd.best || null;
        var wdCreuse = wd.creuse_note || null;

        var wdSub = document.getElementById('weekday-sub');
        if (wdSub) {
            // F4 : un SEUL chiffre dans le sous-titre (déjà pré-rendu côté
            // serveur) — reconstruit depuis le payload, jamais un meilleur
            // jour non significatif.
            if (wdBest && wdBest.label && wdBest.ca != null) {
                wdSub.textContent = 'Meilleur jour : ' + wdBest.label + ' — ' + money(wdBest.ca)
                    + '/jour attendu (modèle, n=' + Number(wdBest.n || 0) + ')';
            } else {
                wdSub.textContent = 'Pas de différence significative entre les jours (historique court)';
            }
            if (wdCreuse) {
                wdSub.textContent += ' · ' + wdCreuse;
            }
        }

        var wdBg = wdObs.map(function (v, i) {
            return i === wdTi ? 'rgba(245,158,11,.75)' : 'rgba(97,80,170,.55)';
        });
        new Chart(ctxWd, {
            type: 'bar',
            data: {
                labels: wd.labels || [],
                datasets: [
                    {
                        type: 'bar',
                        label: 'Observé',
                        data: wdObs,
                        backgroundColor: wdBg,
                        borderColor: violet,
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2,
                    },
                    {
                        type: 'line',
                        label: 'Modèle (prochaine occurrence)',
                        data: wdModel,
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
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (c) {
                                if (c.dataset.type === 'bar') {
                                    var t = 'Observé : ' + (c.raw == null ? '—' : money(c.raw))
                                        + ' (n=' + (wdDaysCount[c.dataIndex] || 0) + ')';
                                    if (wdObsNCreuse[c.dataIndex] > 0) {
                                        t += ' · dont période creuse : '
                                            + (wdObsCreuse[c.dataIndex] == null ? '—' : money(wdObsCreuse[c.dataIndex]))
                                            + ' (n=' + wdObsNCreuse[c.dataIndex] + ')';
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

    // ---------- 5ter. CA par heure (observé + attendu/projeté du jour) ----------
    // Barres ambre = CA moyen OBSERVÉ par heure (payload hourly.ca_avg).
    // Tirets violets = attendu du jour ; pointillés verts = projeté fin
    // de journée ; ligne sarcelle = réel du jour tronqué à l'heure
    // courante. Le SOUS-TITRE (heure de pointe, attendu JOURNÉE, attendu
    // à Nh / trop tôt) est rendu côté serveur (#hourly-sub) — aucune
    // reconstruction JS, une seule source de vérité.
    var hr = data.hourly || { labels: [], ca_avg: [] };
    var ctxHr = document.getElementById('chart-hourly');
    if (ctxHr) {
        var hrAvg  = (hr.ca_avg || []).map(function (v) { return v == null ? null : Number(v); });
        var hrHour = Number(hr.now_hour == null ? 0 : hr.now_hour);
        var hrToday = (hr.today_ca || []).map(function (v, i) {
            return i <= hrHour && v != null ? Number(v) : null;
        });
        var hrExpected = (hr.today_expected || []).map(function (v) { return v == null ? null : Number(v); });
        var hrProjete  = (hr.today_projete || []).map(function (v) { return v == null ? null : Number(v); });

        new Chart(ctxHr, {
            type: 'bar',
            data: {
                labels: hr.labels || [],
                datasets: [
                    {
                        type: 'bar',
                        label: 'CA moyen observé (€)',
                        data: hrAvg,
                        backgroundColor: 'rgba(245,158,11,.55)',
                        borderColor: amber,
                        borderWidth: 1,
                        borderRadius: 3,
                        order: 4,
                    },
                    {
                        type: 'line',
                        label: "Attendu aujourd'hui (€)",
                        data: hrExpected,
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
                        label: 'Projeté fin de journée (€)',
                        data: hrProjete,
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
                        data: hrToday,
                        borderColor: teal,
                        backgroundColor: 'rgba(72,189,211,.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
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
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' : ' + (c.raw == null ? '—' : money(c.raw)); } } },
                },
                scales: {
                    x: { ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 4 } },
                    y: { beginAtZero: true, ticks: { callback: money } },
                },
            },
        });
    }

    // ---------- 5quater. Tendance produit (semaines) + sélecteur auto ----------
    var pw = data.product_weekly || { labels: [], ca: [] };
    var pwSelect = document.getElementById('product-select');
    if (pwSelect) {
        pwSelect.addEventListener('change', function () { pwSelect.form.submit(); });
    }
    var ctxPw = document.getElementById('chart-product-weekly');
    if (ctxPw) {
        new Chart(ctxPw, {
            type: 'line',
            data: {
                labels: pw.labels,
                datasets: [{
                    label: 'CA (€)',
                    data: pw.ca,
                    borderColor: teal,
                    backgroundColor: 'rgba(72,189,211,.12)',
                    tension: 0.3,
                    pointBackgroundColor: teal,
                    pointRadius: 3,
                    fill: true,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return money(c.raw); } } },
                },
                scales: { y: { beginAtZero: true, ticks: { callback: money } } },
            },
        });
    }

    // ---------- 5. Heatmap jour × heure ----------
    var heat = data.heatmap || [];
    var days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
    var heatEl = document.getElementById('heatmap');
    if (heatEl) {
        var max = 0;
        for (var d = 0; d < 7; d++) {
            for (var h = 0; h < 24; h++) {
                var v = Number(((heat[d] || [])[h]) || 0);
                if (v > max) { max = v; }
            }
        }

        var html = '<div class="hm-row hm-head"><span class="hm-label"></span>';
        for (var hh = 0; hh < 24; hh++) { html += '<span class="hm-hh">' + hh + 'h</span>'; }
        html += '</div>';

        for (var dd = 0; dd < 7; dd++) {
            var dayTotal = 0;
            for (var dh2 = 0; dh2 < 24; dh2++) { dayTotal += Number(((heat[dd] || [])[dh2]) || 0); }

            html += '<div class="hm-row">';
            html += '<span class="hm-label">' + days[dd] + '</span>';
            for (var hhh = 0; hhh < 24; hhh++) {
                var val = Number(((heat[dd] || [])[hhh]) || 0);
                var alpha = max > 0 ? val / max : 0;
                var tip = days[dd] + ' à ' + hhh + 'h\nCA : ' + money(val) + '\nTotal ' + days[dd] + ' : ' + money(dayTotal);
                html += '<span class="hm-cell" style="background:rgba(72,189,211,' + alpha.toFixed(3) + ')" data-tip="' + tip.replace(/"/g, '&quot;').replace(/\n/g, '&#10;') + '"></span>';
            }
            html += '</div>';
        }
        heatEl.innerHTML = html;

        // Tooltip flottant.
        var hTip = document.createElement('div');
        hTip.className = 'hm-tooltip';
        hTip.style.cssText = 'position:fixed;pointer-events:none;z-index:9999;background:#0c1d36;border:1px solid rgba(72,189,211,0.4);border-radius:8px;padding:0.6rem 0.85rem;font-size:0.78rem;color:#eaf2fb;box-shadow:0 8px 24px rgba(0,0,0,0.5);display:none;white-space:pre-line;line-height:1.5;';
        document.body.appendChild(hTip);

        heatEl.querySelectorAll('.hm-cell').forEach(function (cell) {
            cell.addEventListener('mouseenter', function () {
                hTip.innerHTML = cell.getAttribute('data-tip').replace(/&#10;/g, '<br>');
                hTip.style.display = 'block';
            });
            cell.addEventListener('mousemove', function (e) {
                hTip.style.left = (e.clientX + 14) + 'px';
                hTip.style.top = (e.clientY - 10) + 'px';
            });
            cell.addEventListener('mouseleave', function () {
                hTip.style.display = 'none';
            });
        });
    }

    // ---------- Tableau récapitulatif (tri + totaux + CSV) ----------
    var rows = data.table || [];
    var tbody = document.getElementById('product-table-body');
    var sortKey = 'ca', sortDir = -1, sortType = 'num';

    function renderTable() {
        if (!tbody) { return; }
        var sorted = rows.slice().sort(function (a, b) {
            var va = a[sortKey], vb = b[sortKey];
            if (sortType === 'num') { return (Number(va) - Number(vb)) * sortDir; }
            return String(va).localeCompare(String(vb)) * sortDir;
        });

        tbody.innerHTML = sorted.map(function (r) {
            return '<tr>'
                + '<td>' + esc(r.product) + '</td>'
                + '<td>' + esc(r.category) + '</td>'
                + '<td class="num">' + Number(r.qty) + '</td>'
                + '<td class="num">' + money(r.ca) + '</td>'
                + '<td class="num">' + money(r.cost) + '</td>'
                + '<td class="num">' + money(r.profit) + '</td>'
                + '<td class="num">' + pct(r.margin) + '</td>'
                + '</tr>';
        }).join('');

        var totQty = 0, totCa = 0, totProfit = 0;
        rows.forEach(function (r) { totQty += Number(r.qty); totCa += Number(r.ca); totProfit += Number(r.profit); });
        setText('tot-qty', totQty);
        setText('tot-ca', money(totCa));
        setText('tot-profit', money(totProfit));
        setText('tot-margin', totCa > 0 ? pct((totProfit / totCa) * 100) : '—');
    }
    function setText(id, v) { var el = document.getElementById(id); if (el) { el.textContent = v; } }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    document.querySelectorAll('#product-table thead th').forEach(function (th) {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function () {
            var key = th.getAttribute('data-key');
            var type = th.getAttribute('data-type') || 'str';
            if (sortKey === key) { sortDir *= -1; } else { sortKey = key; sortType = type; sortDir = type === 'num' ? -1 : 1; }
            renderTable();
        });
    });
    renderTable();

    // ---------- Export CSV ----------
    var btnCsv = document.getElementById('export-csv');
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
    function csvEsc(s) {
        s = s == null ? '' : String(s);
        return /[;"\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
    }
})();
</script>

<style>
.analytics-page { display: flex; flex-direction: column; gap: 1.25rem; }

.analytics-head { margin-bottom: .25rem; }
.analytics-head .eyebrow { color: var(--primary, #48bdd3); font-size: .8rem; letter-spacing: .04em; text-transform: uppercase; margin: 0 0 .15rem; }
.analytics-head .page-title { margin: 0 0 .25rem; }
.analytics-head .muted { margin: 0; color: var(--muted, #9fb3c8); font-size: .9rem; }

/* ---- Filtres ---- */
.analytics-filters {
    background: rgba(255,255,255,0.03);
    border: 1px solid var(--border, rgba(255,255,255,.08));
    border-radius: 14px;
    padding: 1.25rem 1.4rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.af-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.75rem 1.25rem; }
.af-row-filters { align-items: flex-end; }
.af-group { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.af-label {
    font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.04em; color: var(--muted, #9fb3c8); white-space: nowrap;
}
.af-pills { display: flex; flex-wrap: wrap; gap: 0.35rem; }
.af-pill {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    color: var(--muted, #9fb3c8);
    padding: 0.42rem 0.85rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.af-pill:hover {
    border-color: rgba(72,189,211,0.4);
    color: var(--foreground);
    background: rgba(72,189,211,0.06);
}
.af-pill.is-active {
    background: var(--primary, #48bdd3);
    border-color: var(--primary, #48bdd3);
    color: #08172d;
    font-weight: 700;
}
.af-select, .af-input {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.12);
    color: var(--foreground);
    border-radius: 8px;
    padding: 0.5rem 0.7rem;
    font-size: 0.88rem;
    transition: border-color 0.15s;
}
.af-select:focus, .af-input:focus {
    outline: none;
    border-color: var(--primary, #48bdd3);
    background: rgba(72,189,211,0.05);
}
.af-actions { margin-left: auto; display: flex; gap: 0.5rem; }

/* ---- KPI ---- */
.kpi-grid { gap: 1rem; }
.kpi-card { display: flex; flex-direction: column; gap: .25rem; padding: 1rem 1.1rem; min-height: 110px; }
.kpi-label { font-size: .76rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted, #9fb3c8); }
.kpi-value { font-size: 1.5rem; font-weight: 800; color: var(--primary, #48bdd3); line-height: 1.1; }
.kpi-sub { font-size: .78rem; color: var(--muted, #9fb3c8); }
.kpi-delta { font-size: .8rem; font-weight: 700; }
.kpi-delta.is-up { color: #22c55e; }
.kpi-delta.is-down { color: #ef4444; }
.kpi-delta.is-muted { color: var(--muted, #9fb3c8); font-weight: 400; }

.grid-6 { display: grid; grid-template-columns: repeat(6, minmax(0,1fr)); }
@media (max-width: 1100px) { .grid-6 { grid-template-columns: repeat(3, minmax(0,1fr)); } }
@media (max-width: 640px)  { .grid-6 { grid-template-columns: repeat(2, minmax(0,1fr)); } }

/* ---- Charts ---- */
.analytics-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 1.25rem; }
.chart-span-2 { grid-column: 1 / -1; }
.chart-card { padding: 1.25rem 1.4rem; }
.chart-head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.chart-title { font-size: 1rem; font-weight: 700; margin: 0; color: var(--primary, #48bdd3); }
.chart-sub { font-size: .8rem; color: var(--muted, #9fb3c8); }
.chart-canvas { position: relative; height: 300px; }
.chart-canvas-square { height: 280px; max-width: 360px; margin: 0 auto; }
@media (max-width: 860px) { .analytics-grid { grid-template-columns: 1fr; } .chart-span-2 { grid-column: auto; } }

/* ---- Prévisions ---- */
.fc-chips { display: flex; flex-wrap: wrap; gap: 0.35rem 0.75rem; justify-content: flex-end; }
.fc-note { display: block; margin: -0.6rem 0 0.75rem; }
.fc-empty { margin: 0; padding: 0.5rem 0 1rem; }
.pt-form { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end; }
/* Info-bulle métriques du modèle (règle interface 8) : native « title ». */
.fc-info {
    display: inline-block; margin-left: .35rem; cursor: help;
    font-size: .68rem; font-weight: 700; color: var(--muted, #9fb3c8);
    border: 1px solid rgba(255,255,255,.2); border-radius: 50%;
    width: 15px; height: 15px; line-height: 13px; text-align: center;
    vertical-align: 2px;
}
/* Statut « Hors fourchette » (anomalie, ambre) — le statut « Dans la
   fourchette ✓ » reste neutre (classe is-muted existante). */
.kpi-delta.is-off { color: #f59e0b; }
/* Bandeau d'avertissement (données insuffisantes / coût non fiable). */
.fc-banner {
    margin: 0 0 .75rem; padding: .5rem .75rem; border-radius: 10px;
    background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.35);
    color: #fbbf24; font-size: .84rem;
}

/* ---- Objectif ---- */
.goal-body { display: flex; flex-direction: column; gap: .9rem; }
.goal-prob { display: flex; flex-direction: column; gap: .25rem; }
.goal-prob-value { font-size: 2rem; font-weight: 800; color: var(--primary, #48bdd3); line-height: 1; }
.goal-prob-label { font-size: .78rem; color: var(--muted, #9fb3c8); }
.goal-prob-bar { height: 10px; border-radius: 5px; background: rgba(255,255,255,.07); overflow: hidden; }
.goal-prob-fill { height: 100%; border-radius: 5px; background: linear-gradient(90deg, #48bdd3, #22c55e); }
.goal-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; font-size: .88rem; }
.goal-list li { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; border-bottom: 1px solid rgba(255,255,255,.06); padding: .3rem 0; }
.goal-list li:last-child { border-bottom: none; }
.goal-list li span { color: var(--muted, #9fb3c8); }
.goal-form { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }

/* ---- Bénéfice prévu / Réapprovisionnement ---- */
.benef-total { display: flex; align-items: baseline; gap: .5rem; margin: 0 0 .75rem; }
.benef-suspects { margin: .75rem 0 0; padding-left: 1.1rem; font-size: .82rem; color: var(--muted, #9fb3c8); }
.reappro-sugg { color: #22c55e; font-weight: 800; }

/* ---- Qualité des données (bandeau discret, repliable) ---- */
.dataq { padding: .8rem 1.1rem; }
.dataq summary { cursor: pointer; font-size: .82rem; color: var(--muted, #9fb3c8); }
.dataq-body { margin-top: .6rem; font-size: .84rem; display: flex; flex-direction: column; gap: .35rem; }
.dataq-body p { margin: 0; }
.dataq-notes { margin: 0; padding-left: 1.1rem; color: var(--muted, #9fb3c8); }

/* ---- Estimation du jour ---- */
.dt-pills { gap: 0.3rem; }
.dt-pills .af-pill { padding: 0.32rem 0.75rem; font-size: 0.78rem; text-decoration: none; }
.dt-chips { justify-content: flex-start; margin-bottom: 0.75rem; }

/* ---- Heatmap ---- */
.heatmap-card { overflow-x: auto; }
.hm-filters { display: flex; gap: 0.5rem; align-items: center; }
.hm-filters .af-select { font-size: 0.82rem; padding: 0.35rem 0.6rem; }
.heatmap-wrap { display: flex; flex-direction: column; gap: 0.8rem; }
.heatmap {
    display: inline-grid;
    grid-auto-rows: 28px;
    gap: 3px;
    min-width: 820px;
    padding: 4px;
}
.hm-row {
    display: grid;
    grid-template-columns: 40px repeat(24, 1fr);
    gap: 3px;
    align-items: center;
}
.hm-head .hm-label, .hm-head .hm-hh {
    font-size: 0.62rem;
    color: var(--muted, #9fb3c8);
    text-align: center;
    font-weight: 600;
    padding-bottom: 2px;
}
.hm-label {
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--foreground);
    text-align: right;
    padding-right: 4px;
}
.hm-hh {
    font-size: 0.6rem;
    color: var(--muted, #9fb3c8);
    text-align: center;
}
.hm-cell {
    height: 28px;
    border-radius: 5px;
    background: rgba(72,189,211,0.03);
    border: 1px solid rgba(255,255,255,0.03);
    transition: transform 0.1s, box-shadow 0.1s;
    cursor: default;
}
.hm-cell:hover {
    transform: scale(1.25);
    box-shadow: 0 0 8px rgba(72,189,211,0.4);
    z-index: 5;
    position: relative;
}
.hm-legend {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: flex-end;
}
.hm-legend-label { font-size: 0.72rem; color: var(--muted, #9fb3c8); }
.hm-legend-bar {
    width: 120px;
    height: 12px;
    border-radius: 6px;
    background: linear-gradient(90deg, rgba(72,189,211,0.05), rgba(72,189,211,1));
}

/* ---- Insights ---- */
.insights-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 1rem; }
@media (max-width: 1100px) { .insights-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
@media (max-width: 640px)  { .insights-grid { grid-template-columns: 1fr; } }
.insight-card { display: flex; gap: .8rem; align-items: flex-start; padding: 1rem 1.1rem; border-left: 4px solid var(--primary, #48bdd3); }
.insight-teal   { border-left-color: #48bdd3; }
.insight-green  { border-left-color: #22c55e; }
.insight-red    { border-left-color: #ef4444; }
.insight-amber  { border-left-color: #f59e0b; }
.insight-icon svg { width: 24px; height: 24px; display: block; }
.insight-title { font-size: .85rem; font-weight: 700; margin: 0 0 .25rem; color: var(--primary, #48bdd3); }
.insight-text { font-size: .85rem; margin: 0; color: var(--muted, #9fb3c8); }

/* ---- Nav bas de page (kiosque) ---- */
.kday-actions {
    max-width: 40rem; margin: 0 auto 1rem;
    display: flex; gap: 0.5rem; flex-wrap: wrap; justify-content: center;
}

/* ---- Tableau ---- */
.table-card { padding: 1.25rem 1.4rem; }
.table-wrap { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.data-table th, .data-table td { padding: .5rem .6rem; border-bottom: 1px solid rgba(255,255,255,.07); text-align: left; white-space: nowrap; }
.data-table th.num, .data-table td.num { text-align: right; }
.data-table thead th { color: var(--muted, #9fb3c8); font-weight: 600; }
.data-table tfoot td { font-weight: 700; }
.data-table tbody tr:hover { background: rgba(255,255,255,.03); }
</style>
