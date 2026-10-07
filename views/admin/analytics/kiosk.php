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
 *   3. « Heures »     : heatmap jour × heure (défilement horizontal).
 *   4. « Produits »   : tableau triable + totaux + export CSV.
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
            // Menu court sur le kiosque téléphone : on masque les périodes
            // longues (90j/6 mois/12 mois/année civile). Filtrage d'affichage
            // uniquement — le champ caché « period » et le contrôleur
            // continuent d'accepter toutes les périodes.
            $kioskPeriods = array_diff_key($periods, array_flip(['90d', '180d', '365d', 'ytd']));
            foreach ($kioskPeriods as $key => $label): ?>
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
                    <span class="ka-kpi-delta is-muted">—</span>
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

    <!-- ==================== Onglet 3 : Heures ==================== -->
    <div class="compta-tabpane ka-pane" data-pane="heures" hidden>
        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Ventes par jour × heure</h2>
                <span class="ka-sub">Intensité du CA — <?= e($filters['category'] === 'all' ? 'Toutes catégories' : $filters['category']) ?> · <?= e($filters['payment'] === 'all' ? 'Tous paiements' : $filters['payment']) ?></span>
            </div>
            <div class="ka-heat-wrap">
                <div class="ka-heat" id="ka-heatmap"></div>
            </div>
            <div class="ka-hm-legend">
                <span class="ka-hm-legend-label">Faible</span>
                <div class="ka-hm-legend-bar"></div>
                <span class="ka-hm-legend-label">Fort</span>
            </div>
        </section>
    </div>

    <!-- ==================== Onglet 4 : Produits ==================== -->
    <div class="compta-tabpane ka-pane" data-pane="produits" hidden>
        <section class="card surface glass ka-card">
            <div class="ka-chart-head">
                <h2 class="ka-chart-title">Détail par produit</h2>
                <button type="button" class="btn btn-ghost btn-sm" id="ka-export-csv">Exporter CSV</button>
            </div>
            <div class="ka-table-wrap">
                <table class="ka-table" id="ka-product-table">
                    <thead>
                        <tr>
                            <th data-key="product"  data-type="str">Produit</th>
                            <th data-key="category" data-type="str">Catégorie</th>
                            <th data-key="qty"      data-type="num" class="num">Qté</th>
                            <th data-key="ca"       data-type="num" class="num">CA</th>
                            <th data-key="cost"     data-type="num" class="num">Coût moy.</th>
                            <th data-key="profit"   data-type="num" class="num">Bénéfice</th>
                            <th data-key="margin"   data-type="num" class="num">Marge %</th>
                        </tr>
                    </thead>
                    <tbody id="ka-product-table-body"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"><strong>Total</strong></td>
                            <td class="num" id="ka-tot-qty"></td>
                            <td class="num" id="ka-tot-ca"></td>
                            <td class="num">—</td>
                            <td class="num" id="ka-tot-profit"></td>
                            <td class="num" id="ka-tot-margin"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
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
    function setText(id, v) { var el = document.getElementById(id); if (el) { el.textContent = v; } }

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
    // Heatmap jour × heure (DOM pur : construite immédiatement).
    // ============================================================
    function initHeatmap() {
        var heat = data.heatmap || [];
        var days = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        var heatEl = document.getElementById('ka-heatmap');
        if (!heatEl) return;

        var max = 0;
        for (var d = 0; d < 7; d++) {
            for (var h = 0; h < 24; h++) {
                var v = Number(((heat[d] || [])[h]) || 0);
                if (v > max) { max = v; }
            }
        }

        var html = '<div class="ka-hm-row ka-hm-head"><span class="ka-hm-label"></span>';
        for (var hh = 0; hh < 24; hh++) { html += '<span class="ka-hm-hh">' + hh + 'h</span>'; }
        html += '</div>';

        for (var dd = 0; dd < 7; dd++) {
            var dayTotal = 0;
            for (var dh = 0; dh < 24; dh++) { dayTotal += Number(((heat[dd] || [])[dh]) || 0); }

            html += '<div class="ka-hm-row">';
            html += '<span class="ka-hm-label">' + days[dd] + '</span>';
            for (var h2 = 0; h2 < 24; h2++) {
                var val = Number(((heat[dd] || [])[h2]) || 0);
                var alpha = max > 0 ? val / max : 0;
                var tip = days[dd] + ' à ' + h2 + 'h\nCA : ' + money(val) + '\nTotal ' + days[dd] + ' : ' + money(dayTotal);
                html += '<span class="ka-hm-cell" style="background:rgba(72,189,211,' + alpha.toFixed(3) + ')" data-tip="' + tip.replace(/"/g, '&quot;').replace(/\n/g, '&#10;') + '"></span>';
            }
            html += '</div>';
        }
        heatEl.innerHTML = html;

        // Tooltip flottant (identique à la vue desktop).
        var hTip = document.createElement('div');
        hTip.className = 'ka-hm-tooltip';
        hTip.style.cssText = 'position:fixed;pointer-events:none;z-index:9999;background:#0c1d36;border:1px solid rgba(72,189,211,0.4);border-radius:8px;padding:0.6rem 0.85rem;font-size:0.78rem;color:#eaf2fb;box-shadow:0 8px 24px rgba(0,0,0,0.5);display:none;white-space:pre-line;line-height:1.5;';
        document.body.appendChild(hTip);

        heatEl.querySelectorAll('.ka-hm-cell').forEach(function (cell) {
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

    // ============================================================
    // Tableau produits : tri au clic + totaux + export CSV
    // (DOM pur : construit immédiatement).
    // ============================================================
    var rows = data.table || [];
    var sortKey = 'ca', sortDir = -1, sortType = 'num';

    function renderTable() {
        var tbody = document.getElementById('ka-product-table-body');
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
        setText('ka-tot-qty', totQty);
        setText('ka-tot-ca', money(totCa));
        setText('ka-tot-profit', money(totProfit));
        setText('ka-tot-margin', totCa > 0 ? pct((totProfit / totCa) * 100) : '—');
    }

    function initTable() {
        document.querySelectorAll('#ka-product-table thead th').forEach(function (th) {
            th.style.cursor = 'pointer';
            th.addEventListener('click', function () {
                var key = th.getAttribute('data-key');
                var type = th.getAttribute('data-type') || 'str';
                if (sortKey === key) { sortDir *= -1; } else { sortKey = key; sortType = type; sortDir = type === 'num' ? -1 : 1; }
                renderTable();
            });
        });
        renderTable();

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

    // Heatmap + tableau : construits tout de suite (pas de canvas).
    initHeatmap();
    initTable();

    // Onglet initial : hash de l'URL (#repart, #heures, #produits),
    // sinon « Vue » (initialisé immédiatement — pane visible).
    var initial = (window.location.hash || '').replace('#', '');
    activate(initial || 'vue', false);
})();
</script>

<style>
.ka-page { display: flex; flex-direction: column; gap: 0.6rem; }

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
.ka-sub { font-size: 0.68rem; color: var(--muted, #9fb3c8); text-align: right; }
.ka-chart { position: relative; }
.ka-chart-trend { height: 260px; }
.ka-chart-top { height: 300px; }
.ka-chart-donut { height: 250px; max-width: 280px; margin: 0 auto; }
/* Donuts empilés sur téléphone (assez larges pour être lus en 1:1),
   côte à côte uniquement sur écran large. */
.ka-duo { display: grid; grid-template-columns: 1fr; gap: 0.6rem; }
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

/* ---- Heatmap compacte (défilement horizontal DANS la carte :
        jamais de débordement de page → pas de dézoom navigateur) ---- */
.ka-heat-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.ka-heat {
    display: inline-grid;
    grid-auto-rows: 20px;
    gap: 2px;
    min-width: 560px;
    padding: 2px;
}
.ka-hm-row {
    display: grid;
    grid-template-columns: 34px repeat(24, 1fr);
    gap: 2px;
    align-items: center;
}
.ka-hm-head .ka-hm-label, .ka-hm-head .ka-hm-hh {
    font-size: 0.55rem;
    color: var(--muted, #9fb3c8);
    text-align: center;
    font-weight: 600;
    padding-bottom: 2px;
}
.ka-hm-label {
    font-size: 0.62rem;
    font-weight: 700;
    color: var(--foreground);
    text-align: right;
    padding-right: 3px;
}
.ka-hm-hh { font-size: 0.55rem; color: var(--muted, #9fb3c8); text-align: center; }
.ka-hm-cell {
    height: 20px;
    border-radius: 3px;
    background: rgba(72, 189, 211, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.03);
    cursor: default;
}
.ka-hm-legend { display: flex; align-items: center; gap: 0.4rem; justify-content: flex-end; margin-top: 0.5rem; }
.ka-hm-legend-label { font-size: 0.65rem; color: var(--muted, #9fb3c8); }
.ka-hm-legend-bar {
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

/* ---- Tableau produits (défilement horizontal DANS la carte) ---- */
.ka-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.ka-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; min-width: 480px; }
.ka-table th, .ka-table td { padding: 0.4rem 0.45rem; border-bottom: 1px solid rgba(255, 255, 255, 0.07); text-align: left; white-space: nowrap; }
.ka-table th.num, .ka-table td.num { text-align: right; }
.ka-table thead th { color: var(--muted, #9fb3c8); font-weight: 600; }
.ka-table tfoot td { font-weight: 700; }
.ka-table tbody tr:hover { background: rgba(255, 255, 255, 0.03); }

/* ---- Divers ---- */
.ka-nosales { margin: 0; font-size: 0.78rem; color: var(--muted, #9fb3c8); }
.ka-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: center; margin: 0.6rem 0 1rem; }
</style>
