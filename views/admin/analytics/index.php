<?php

declare(strict_types=1);

/**
 * Dashboard analytics — graphiques Chart.js.
 *
 * @var string $json Données sérialisées (JSON) injectées dans un <script>.
 */

use App\Models\Sale;

$hasSales = Sale::count() > 0;
?>
<div class="analytics-grid">

    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Évolution du chiffre d'affaires</h2>
            <span class="chart-sub">12 derniers mois · TTC</span>
        </div>
        <div class="chart-canvas"><canvas id="chart-revenue" height="140"></canvas></div>
    </div>

    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Top 10 produits</h2>
            <span class="chart-sub">CA sur 12 mois</span>
        </div>
        <div class="chart-canvas"><canvas id="chart-top" height="140"></canvas></div>
    </div>

    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Répartition par catégorie</h2>
            <span class="chart-sub">CA sur 12 mois</span>
        </div>
        <div class="chart-canvas chart-canvas-square"><canvas id="chart-category"></canvas></div>
    </div>

    <div class="card surface glass chart-card">
        <div class="chart-head">
            <h2 class="chart-title">Activité de l'association</h2>
            <span class="chart-sub">6 derniers mois</span>
        </div>
        <div class="chart-canvas"><canvas id="chart-activity" height="140"></canvas></div>
    </div>

</div>

<?php if (!$hasSales): ?>
    <p class="card-meta" style="margin-top:1rem">
        Aucune vente importée — les graphiques de CA restent vides jusqu'au premier import
        (<a href="<?= e(url('/admin/compta/import')) ?>">Importer un CSV SumUp</a>).
    </p>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    (function () {
        var data = <?= $json ?>;

        Chart.defaults.color = '#9fb3c8';
        Chart.defaults.borderColor = 'rgba(255,255,255,.08)';
        Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";

        var teal = '#48bdd3';
        var violet = '#9b6dff';
        var palette = ['#48bdd3', '#9b6dff', '#f0a35e', '#5ec78a', '#e36a8e',
                       '#6db4ff', '#d9c24a', '#c46ad9', '#4ad9b0', '#d96a6a'];

        function money(v) { return Number(v).toFixed(0) + ' €'; }

        // CA mensuel (line).
        var r = data.revenue || { labels: [], values: [] };
        new Chart(document.getElementById('chart-revenue'), {
            type: 'line',
            data: {
                labels: r.labels,
                datasets: [{
                    label: 'CA (€)',
                    data: r.values,
                    borderColor: teal,
                    backgroundColor: 'rgba(72,189,211,.18)',
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: teal,
                    pointRadius: 3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: money } } },
            },
        });

        // Top produits (bar horizontal).
        var t = data.topProducts || { labels: [], values: [] };
        new Chart(document.getElementById('chart-top'), {
            type: 'bar',
            data: {
                labels: t.labels,
                datasets: [{
                    label: 'CA (€)',
                    data: t.values,
                    backgroundColor: violet,
                    borderRadius: 6,
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { callback: money } } },
            },
        });

        // Répartition par catégorie (donut).
        var c = data.byCategory || { labels: [], values: [] };
        new Chart(document.getElementById('chart-category'), {
            type: 'doughnut',
            data: {
                labels: c.labels,
                datasets: [{
                    data: c.values,
                    backgroundColor: palette,
                    borderColor: 'transparent',
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
                    tooltip: { callbacks: { label: function (ctx) { return ctx.label + ' : ' + money(ctx.raw); } } },
                },
                cutout: '62%',
            },
        });

        // Inscriptions + nouveaux membres (line).
        var a = data.activity || { labels: [], registrations: [], members: [] };
        new Chart(document.getElementById('chart-activity'), {
            type: 'line',
            data: {
                labels: a.labels,
                datasets: [
                    {
                        label: 'Inscriptions',
                        data: a.registrations,
                        borderColor: teal,
                        backgroundColor: 'rgba(72,189,211,.15)',
                        tension: 0.35,
                        pointBackgroundColor: teal,
                    },
                    {
                        label: 'Nouveaux membres',
                        data: a.members,
                        borderColor: violet,
                        backgroundColor: 'rgba(155,109,255,.15)',
                        tension: 0.35,
                        pointBackgroundColor: violet,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true } },
            },
        });
    })();
</script>

<style>
.analytics-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.25rem;
}
.chart-card { padding: 1.25rem 1.4rem; }
.chart-head {
    display: flex; align-items: baseline; justify-content: space-between;
    gap: 1rem; margin-bottom: 1rem;
}
.chart-title { font-size: 1rem; font-weight: 700; margin: 0; color: var(--primary); }
.chart-sub { font-size: .8rem; color: var(--muted, #9fb3c8); }
.chart-canvas { position: relative; height: 280px; }
.chart-canvas-square { height: 260px; max-width: 360px; margin: 0 auto; }
@media (max-width: 860px) {
    .analytics-grid { grid-template-columns: 1fr; }
}
</style>
