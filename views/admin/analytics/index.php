<?php

declare(strict_types=1);

/**
 * Dashboard Analytics Pro — filtres, KPI, graphiques, heatmap, tableau, insights.
 *
 * @var array<string,mixed> $filters
 * @var array<string,string> $periods
 * @var list<string> $categories
 * @var bool $hasSales
 * @var array<string,mixed> $kpis
 * @var list<array<string,mixed>> $insights
 * @var string $json  Payload JSON injecté dans un <script>.
 */

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
    <form method="get" class="card surface glass analytics-filters" id="analytics-filters">
        <div class="filter-group">
            <label class="filter-label">Période</label>
            <select name="period" id="analytics-period">
                <?php foreach ($periods as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filters['period'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group filter-custom" id="filter-custom" hidden>
            <label class="filter-label">Du</label>
            <input type="date" name="from" value="<?= e($filters['fromInput']) ?>">
            <label class="filter-label">Au</label>
            <input type="date" name="to" value="<?= e($filters['toInput']) ?>">
        </div>

        <div class="filter-group">
            <label class="filter-label">Granularité</label>
            <select name="granularity">
                <option value="" <?= $filters['granularity'] === '' ? 'selected' : '' ?>>Auto</option>
                <option value="day" <?= $filters['granularity'] === 'day' ? 'selected' : '' ?>>Jour</option>
                <option value="week" <?= $filters['granularity'] === 'week' ? 'selected' : '' ?>>Semaine</option>
                <option value="month" <?= $filters['granularity'] === 'month' ? 'selected' : '' ?>>Mois</option>
            </select>
        </div>

        <div class="filter-group">
            <label class="filter-label">Catégorie</label>
            <select name="category">
                <option value="all" <?= $filters['category'] === 'all' ? 'selected' : '' ?>>Toutes</option>
                <option value="Boisson" <?= $filters['category'] === 'Boisson' ? 'selected' : '' ?>>Boisson</option>
                <option value="Nourriture" <?= $filters['category'] === 'Nourriture' ? 'selected' : '' ?>>Nourriture</option>
                <option value="Spécial" <?= $filters['category'] === 'Spécial' ? 'selected' : '' ?>>Spécial</option>
                <option value="Non classé" <?= $filters['category'] === 'Non classé' ? 'selected' : '' ?>>Non classé</option>
                <?php foreach ($categories as $c): ?>
                    <?php if (in_array($c, ['Boisson', 'Nourriture', 'Spécial', 'Non classé'], true)) { continue; } ?>
                    <option value="<?= e($c) ?>" <?= $filters['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label class="filter-label">Paiement</label>
            <select name="payment">
                <option value="all" <?= $filters['payment'] === 'all' ? 'selected' : '' ?>>Tous</option>
                <option value="CARTE" <?= $filters['payment'] === 'CARTE' ? 'selected' : '' ?>>Carte</option>
                <option value="LIQUIDE" <?= $filters['payment'] === 'LIQUIDE' ? 'selected' : '' ?>>Liquide</option>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm">Appliquer</button>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/analytics')) ?>">Réinitialiser</a>
        </div>
    </form>

    <?php if (!$hasSales): ?>
        <p class="card-meta" style="margin-top:1rem">
            Aucune vente importée — les graphiques restent vides jusqu'au premier import
            (<a href="<?= e(url('/admin/compta/import')) ?>">Importer un CSV SumUp</a>).
        </p>
    <?php endif; ?>

    <!-- ============================================================ -->
    <!--  KPI Cards                                                     -->
    <!-- ============================================================ -->
    <div class="grid grid-6 kpi-grid">
        <?php
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
                    <span class="kpi-delta is-muted">—</span>
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
                <h2 class="chart-title">Activité de l'association</h2>
                <span class="chart-sub">6 derniers mois</span>
            </div>
            <div class="chart-canvas"><canvas id="chart-activity" height="160"></canvas></div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!--  Heatmap des ventes par jour × heure                          -->
    <!-- ============================================================ -->
    <div class="card surface glass chart-card heatmap-card">
        <div class="chart-head">
            <h2 class="chart-title">Heatmap des ventes (jour × heure)</h2>
            <span class="chart-sub">Intensité du CA</span>
        </div>
        <div class="heatmap" id="heatmap"></div>
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
                        <th data-key="margin"   data-type="num" class="num">Marge %</th>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    var data = <?= $json ?>;

    // ---------- Affichage du sélecteur de dates custom ----------
    var periodSel = document.getElementById('analytics-period');
    var customBox = document.getElementById('filter-custom');
    function syncCustom() {
        customBox.hidden = periodSel.value !== 'custom';
    }
    syncCustom();
    periodSel.addEventListener('change', syncCustom);

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
                onClick: function () { window.location.href = '<?= e(url('/admin/compta/produits')) ?>'; },
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

    // ---------- 4. Répartition des paiements (donut CA) ----------
    var pm = data.payments || { by_ca: {}, by_count: {} };
    var pmLabels = ['Carte', 'Liquide'];
    var pmKeys   = ['CARTE', 'LIQUIDE'];
    var pmCa     = pmKeys.map(function (k) { return Number((pm.by_ca || {})[k] || 0); });
    var ctxPm = document.getElementById('chart-payment');
    if (ctxPm) {
        new Chart(ctxPm, {
            type: 'doughnut',
            data: {
                labels: pmLabels,
                datasets: [{
                    data: pmCa,
                    backgroundColor: [teal, amber],
                    borderColor: 'transparent',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
                    tooltip: {
                        callbacks: {
                            label: function (c) {
                                var key = pmKeys[c.dataIndex];
                                var nb = Number((pm.by_count || {})[key] || 0);
                                return c.label + ' : ' + money(c.raw) + ' (' + nb + ' transactions)';
                            },
                        },
                    },
                },
            },
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
        for (var hh = 0; hh < 24; hh++) { html += '<span class="hm-hh">' + hh + '</span>'; }
        html += '</div>';

        for (var dd = 0; dd < 7; dd++) {
            html += '<div class="hm-row"><span class="hm-label">' + days[dd] + '</span>';
            for (var hhh = 0; hhh < 24; hhh++) {
                var val = Number(((heat[dd] || [])[hhh]) || 0);
                var alpha = max > 0 ? val / max : 0;
                var title = days[dd] + ' ' + hhh + 'h : ' + money(val);
                html += '<span class="hm-cell" style="background:rgba(72,189,211,' + alpha.toFixed(3) + ')" title="' + title.replace(/"/g, '&quot;') + '"></span>';
            }
            html += '</div>';
        }
        heatEl.innerHTML = html;
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
    display: flex; flex-wrap: wrap; align-items: flex-end; gap: .9rem 1.1rem;
    padding: 1rem 1.1rem;
}
.filter-group { display: flex; align-items: center; gap: .45rem; }
.filter-group.filter-custom { flex-wrap: wrap; }
.filter-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted, #9fb3c8); }
.analytics-filters select,
.analytics-filters input[type="date"] {
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.12);
    color: inherit; border-radius: 8px; padding: .42rem .55rem; font-size: .88rem;
}
.filter-actions { margin-left: auto; display: flex; gap: .5rem; }

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

/* ---- Heatmap ---- */
.heatmap-card { overflow-x: auto; }
.heatmap { display: inline-grid; grid-auto-rows: 22px; gap: 2px; min-width: 760px; }
.hm-row { display: grid; grid-template-columns: 34px repeat(24, 1fr); gap: 2px; align-items: center; }
.hm-label, .hm-hh { font-size: .66rem; color: var(--muted, #9fb3c8); text-align: center; }
.hm-cell { height: 22px; border-radius: 3px; background: rgba(72,189,211,0); }

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
