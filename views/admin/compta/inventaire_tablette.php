<?php

declare(strict_types=1);

/**
 * Inventaire tablette SumUp — section de la page fusionnée
 * « Opérations » (vague 2 : interface complète).
 *
 * Trois onglets internes commutés côté client, mémorisés dans l'URL
 * (?tab=…) : « Nouvelle saisie » (collage du contenu de la tablette
 * dans une zone dédiée, analyse des lignes + aperçu des écarts avant
 * enregistrement), « Historique » (sessions + KPIs), « Analyse »
 * (période personnalisable, KPIs avec deltas vs période précédente,
 * graphiques Chart.js et comparaison de fenêtres). L'onglet initial
 * vient du contrôleur ($tab, déjà validé côté serveur).
 *
 * En-tête et onglets de niveau 1 fournis par la page fusionnée
 * « Opérations » (operations.php) qui inclut cette vue.
 *
 * @var array<string,mixed>       $user
 * @var string                    $tab          'saisie'|'historique'|'analyse'
 * @var string                    $from         Début fenêtre d'analyse (Y-m-d)
 * @var string                    $to           Fin fenêtre d'analyse (Y-m-d)
 * @var string                    $prevFrom     Début fenêtre précédente (Y-m-d)
 * @var string                    $prevTo       Fin fenêtre précédente (Y-m-d)
 * @var list<array{key:string, theoretical:?int, unit_cost:?string}> $products
 * @var list<array<string,mixed>> $sessions     (+ lines_count, gaps_count,
 *                                              missing_cost_count,
 *                                              loss_value_sum,
 *                                              corrected_count)
 * @var array{kpis:array{sessions:int,gaps:int,loss_value:float,missing_cost_count:int,products_affected:int}, per_session:list<array{date:string,label:string,gaps:int,loss_value:float}>, top_products:list<array{key:string,gaps_units_sum:int,loss_value:float}>} $analysis
 * @var array{kpis:array{sessions:int,gaps:int,loss_value:float,missing_cost_count:int,products_affected:int}, per_session:list<array{date:string,label:string,gaps:int,loss_value:float}>, top_products:list<array{key:string,gaps_units_sum:int,loss_value:float}>} $prev
 */

// ---- Agrégats de l'onglet Historique ----
$histSessions = count($sessions);
$histGaps = 0;
$histLoss = 0.0;
foreach ($sessions as $s) {
    $histGaps += (int) $s['gaps_count'];
    $histLoss += (float) $s['loss_value_sum'];
}

// ---- Deltas « analyse vs période précédente » (null si base nulle) ----
$pctDelta = static function (float $cur, float $base): ?float {
    return $base == 0.0 ? null : round((($cur - $base) / $base) * 100, 1);
};

/**
 * Petit libellé de variation (↑/↓ %), coloré bon/mauvais selon le sens
 * souhaité (pour la perte, la hausse est mauvaise).
 */
$deltaHtml = static function (?float $d, bool $lowerIsBetter = false): string {
    if ($d === null) {
        return '<span class="inv-delta is-muted">— pas de base de comparaison</span>';
    }
    if ($d == 0.0) {
        return '<span class="inv-delta is-muted">= stable</span>';
    }
    $up = $d > 0;
    $good = $lowerIsBetter ? !$up : $up;

    return '<span class="inv-delta ' . ($good ? 'is-good' : 'is-bad') . '">'
        . ($up ? '↑' : '↓') . ' ' . number_format(abs($d), 1, ',', ' ') . ' % vs préc.</span>';
};

$ak = $analysis['kpis'];
$pk = $prev['kpis'];

// ---- Payload des graphiques de l'onglet Analyse ----
$chartData = [
    'perSession' => [
        'labels' => array_map(static fn (array $r): string => formatDate((string) $r['date']), $analysis['per_session']),
        'loss'   => array_map(static fn (array $r): float => (float) $r['loss_value'], $analysis['per_session']),
        'gaps'   => array_map(static fn (array $r): int => (int) $r['gaps'], $analysis['per_session']),
    ],
    'topProducts' => [
        'labels' => array_map(static fn (array $r): string => (string) $r['key'], $analysis['top_products']),
        'loss'   => array_map(static fn (array $r): float => (float) $r['loss_value'], $analysis['top_products']),
    ],
];
$hasPerSession  = $analysis['per_session'] !== [];
$hasTopProducts = $analysis['top_products'] !== [];
?>
<p class="muted">
    Releve les quantités affichées sur la <strong>tablette SumUp</strong> : le site calcule l'écart
    contre son stock théorique (dernier comptage + achats − ventes − pertes). Chaque écart
    négatif est une suspicion de vol/casse, valorisée en perte € estimée.
</p>

<nav class="compta-tabs" data-compta-tabs aria-label="Sections Inventaire tablette">
    <button type="button" class="compta-tab<?= $tab === 'saisie' ? ' is-active' : '' ?>" data-tab="saisie">Nouvelle saisie</button>
    <button type="button" class="compta-tab<?= $tab === 'historique' ? ' is-active' : '' ?>" data-tab="historique">Historique</button>
    <button type="button" class="compta-tab<?= $tab === 'analyse' ? ' is-active' : '' ?>" data-tab="analyse">Analyse</button>
</nav>

<!-- ==================== Onglet : Nouvelle saisie ==================== -->
<div class="compta-tabpane<?= $tab === 'saisie' ? ' is-active' : '' ?>" data-pane="saisie"<?= $tab === 'saisie' ? '' : ' hidden' ?>>
    <section class="card surface glass">
        <h2 class="card-title">Nouveau relevé tablette</h2>
        <p class="muted">
            Copie le contenu affiché par la <strong>tablette SumUp</strong> et colle-le ci-dessous :
            le site repère chaque produit et sa quantité, puis calcule l'écart contre son stock
            théorique. Les lignes d'interface (« Sélectionner », « Modifier », « Options »…) sont
            ignorées ; les lignes non reconnues sont signalées « à préciser ».
        </p>

        <form method="post" action="<?= e(url('/admin/compta/inventaire-tablette/save')) ?>" id="tablet-paste-form">
            <?= csrf_field() ?>

            <div class="field-row">
                <div class="field">
                    <label for="counted_at">Date du relevé</label>
                    <input type="date" id="counted_at" name="counted_at" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="field">
                    <label for="note">Note <span class="muted">(optionnel)</span></label>
                    <input type="text" id="note" name="note" placeholder="ex : relevé du vendredi soir" maxlength="500">
                </div>
            </div>

            <div class="inv-paste-zone">
                <label for="inv-paste">Collage depuis la tablette</label>
                <textarea
                    id="inv-paste"
                    rows="10"
                    spellcheck="false"
                    autocomplete="off"
                    class="inv-paste-input"
                    placeholder="Redbull&#9;12&#10;eau - 104&#10;Monster Blanche : 2&#10;Bueno white&#10;28"
                ></textarea>
                <div class="inv-paste-actions">
                    <button type="button" class="btn btn-outline btn-sm" id="inv-paste-analyze">Analyser le collage</button>
                    <button type="button" class="btn btn-outline btn-sm" id="inv-paste-clear">Vider</button>
                </div>
                <p class="muted inv-paste-help">
                    Formats reconnus : « Nom 12 », « Nom - 12 », « Nom — 12 », « Nom : 12 », « Nom, 12 »,
                    « Nom ; 12 », « Nom | 12 », « Nom&#9;12 » (tabulation). Quantités négatives acceptées
                    (la tablette peut afficher un stock négatif) ; un nombre seul sur une ligne se rattache
                    au libellé du dessus.
                </p>
            </div>

            <datalist id="inv-keys-list">
                <?php foreach ($products as $p): ?>
                    <option value="<?= e((string) $p['key']) ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <p class="inv-paste-summary muted" id="inv-paste-summary" hidden></p>

            <div class="table-wrap" id="inv-paste-preview" hidden>
                <table class="table" id="inv-paste-table">
                    <thead>
                        <tr>
                            <th>Libellé collé</th>
                            <th>Produit reconnu</th>
                            <th class="th-num">Qté</th>
                            <th class="th-num">Stock site <span class="muted">(théorique)</span></th>
                            <th class="th-num">Écart</th>
                            <th>Avis <span class="muted">(optionnel)</span></th>
                        </tr>
                    </thead>
                    <tbody id="inv-paste-tbody"></tbody>
                </table>
            </div>

            <div id="inv-paste-payload"></div>

            <p class="muted inv-form-note">
                Le stock théorique et l'écart sont recalculés côté serveur à l'enregistrement (l'aperçu
                n'est qu'indicatif) ; seules les lignes reconnues ci-dessus sont envoyées.
            </p>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="inv-paste-submit" disabled>Enregistrer la session</button>
            </div>
        </form>
    </section>
</div>

<!-- ==================== Onglet : Historique ==================== -->
<div class="compta-tabpane<?= $tab === 'historique' ? ' is-active' : '' ?>" data-pane="historique"<?= $tab === 'historique' ? '' : ' hidden' ?>>
    <div class="compta-kpis">
        <div class="card surface glass kpi">
            <p class="kpi-label">Sessions de relevé</p>
            <p class="kpi-value"><?= $histSessions ?></p>
            <p class="kpi-sub">historique conservé à vie</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Écarts détectés</p>
            <p class="kpi-value"><?= $histGaps ?></p>
            <p class="kpi-sub">toutes sessions confondues</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Perte cumulée estimée</p>
            <p class="kpi-value <?= $histLoss > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice($histLoss)) ?></p>
            <p class="kpi-sub">manquants × coût unitaire</p>
        </div>
    </div>

    <div class="card surface glass table-wrap">
        <h2 class="card-title">Sessions de relevé</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="th-num">Produits</th>
                    <th class="th-num">Écarts</th>
                    <th class="th-num">Corrigés</th>
                    <th class="th-num">Perte estimée</th>
                    <th>Note</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sessions as $s): ?>
                    <?php
                        $g = (int) $s['gaps_count'];
                        $c = (int) $s['corrected_count'];
                    ?>
                    <tr>
                        <td><?= e(formatDate((string) $s['counted_at'])) ?></td>
                        <td class="num"><?= (int) $s['lines_count'] ?></td>
                        <td class="num">
                            <?php if ($g > 0): ?>
                                <span class="badge badge-warning"><?= $g ?></span>
                            <?php else: ?>
                                <span class="badge badge-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <?php if ($g > 0 && $c >= $g): ?>
                                <span class="badge badge-success"><?= $c ?> / <?= $g ?></span>
                            <?php elseif ($g > 0): ?>
                                <span class="badge badge-muted"><?= $c ?> / <?= $g ?></span>
                            <?php else: ?>
                                <span class="badge badge-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="num <?= (float) $s['loss_value_sum'] > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice((float) $s['loss_value_sum'])) ?></td>
                        <td><?= (string) ($s['note'] ?? '') !== '' ? e((string) $s['note']) : '<span class="muted">—</span>' ?></td>
                        <td class="row-actions">
                            <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/inventaire-tablette/' . rawurlencode((string) $s['id']))) ?>">Détail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($sessions === []): ?>
                    <tr><td colspan="7" class="muted">Aucune session enregistrée pour l'instant : commence par une « Nouvelle saisie ».</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== Onglet : Analyse ==================== -->
<div class="compta-tabpane<?= $tab === 'analyse' ? ' is-active' : '' ?>" data-pane="analyse"<?= $tab === 'analyse' ? '' : ' hidden' ?>>
    <form method="get" action="<?= e(url('/admin/compta/inventaire-tablette')) ?>" class="inv-period card surface glass" id="inv-period-form">
        <input type="hidden" name="tab" value="analyse">
        <div class="inv-period-row">
            <div class="af-group">
                <span class="af-label">Période</span>
                <div class="af-pills">
                    <button type="button" class="af-pill" data-days="30">30 jours</button>
                    <button type="button" class="af-pill" data-days="90">90 jours</button>
                    <button type="button" class="af-pill" data-days="365">12 mois</button>
                    <button type="button" class="af-pill" data-days="0">Tout</button>
                </div>
            </div>
            <div class="af-group">
                <label class="af-label" for="inv-from">Du</label>
                <input type="date" id="inv-from" name="from" value="<?= e($from) ?>" class="af-input">
                <label class="af-label" for="inv-to">Au</label>
                <input type="date" id="inv-to" name="to" value="<?= e($to) ?>" class="af-input">
            </div>
            <div class="af-group">
                <button type="submit" class="btn btn-primary">Appliquer</button>
            </div>
        </div>
    </form>

    <div class="compta-kpis">
        <div class="card surface glass kpi">
            <p class="kpi-label">Sessions</p>
            <p class="kpi-value"><?= (int) $ak['sessions'] ?></p>
            <?= $deltaHtml($pctDelta((float) $ak['sessions'], (float) $pk['sessions'])) ?>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Écarts détectés</p>
            <p class="kpi-value"><?= (int) $ak['gaps'] ?></p>
            <?= $deltaHtml($pctDelta((float) $ak['gaps'], (float) $pk['gaps']), true) ?>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Perte estimée</p>
            <p class="kpi-value <?= (float) $ak['loss_value'] > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice((float) $ak['loss_value'])) ?></p>
            <?= $deltaHtml($pctDelta((float) $ak['loss_value'], (float) $pk['loss_value']), true) ?>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Produits touchés</p>
            <p class="kpi-value"><?= (int) $ak['products_affected'] ?></p>
            <?= $deltaHtml($pctDelta((float) $ak['products_affected'], (float) $pk['products_affected']), true) ?>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Coûts manquants</p>
            <p class="kpi-value"><?= (int) $ak['missing_cost_count'] ?></p>
            <?= $deltaHtml($pctDelta((float) $ak['missing_cost_count'], (float) $pk['missing_cost_count']), true) ?>
        </div>
    </div>

    <div class="compta-grid">
        <div class="card surface glass">
            <h2 class="card-title">Par changement</h2>
            <p class="muted">Perte estimée (€) et nombre d'écarts, session par session, du <?= e(formatDate($from)) ?> au <?= e(formatDate($to)) ?>.</p>
            <?php if ($hasPerSession): ?>
                <div class="chart-canvas"><canvas id="chart-sessions"></canvas></div>
            <?php else: ?>
                <p class="muted inv-empty">Aucune session de relevé sur cette période.</p>
            <?php endif; ?>
        </div>

        <div class="card surface glass">
            <h2 class="card-title">Top produits (perte €)</h2>
            <p class="muted">Produits les plus touchés par les manquants sur la période.</p>
            <?php if ($hasTopProducts): ?>
                <div class="chart-canvas"><canvas id="chart-top"></canvas></div>
            <?php else: ?>
                <p class="muted inv-empty">Aucun écart valorisé sur cette période.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card surface glass inv-compare">
        <h2 class="card-title">vs période précédente</h2>
        <div class="inv-compare-grid">
            <div class="inv-compare-col">
                <p class="kpi-label">Période actuelle</p>
                <p class="muted"><?= e(formatDate($from)) ?> → <?= e(formatDate($to)) ?></p>
                <p class="inv-compare-line">
                    <strong><?= (int) $ak['gaps'] ?></strong> écart<?= (int) $ak['gaps'] > 1 ? 's' : '' ?> détecté<?= (int) $ak['gaps'] > 1 ? 's' : '' ?>
                    · <strong class="<?= (float) $ak['loss_value'] > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice((float) $ak['loss_value'])) ?></strong> de perte estimée
                </p>
            </div>
            <div class="inv-compare-col">
                <p class="kpi-label">Période précédente</p>
                <p class="muted"><?= e(formatDate($prevFrom)) ?> → <?= e(formatDate($prevTo)) ?></p>
                <p class="inv-compare-line">
                    <strong><?= (int) $pk['gaps'] ?></strong> écart<?= (int) $pk['gaps'] > 1 ? 's' : '' ?> détecté<?= (int) $pk['gaps'] > 1 ? 's' : '' ?>
                    · <strong class="<?= (float) $pk['loss_value'] > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice((float) $pk['loss_value'])) ?></strong> de perte estimée
                </p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?= e(rootAssetVersioned('/assets/js/inventaire-tablette.js')) ?>"></script>
<script>
// ---------- Produits connus (index JS pour l'appariement du collage) ----------
var invProducts = <?= json_encode(
    array_map(
        static fn (array $p): array => [
            'key'         => (string) $p['key'],
            'theoretical' => $p['theoretical'] === null ? null : (int) $p['theoretical'],
        ],
        $products
    ),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
) ?>;

// ---------- Graphiques de l'onglet Analyse (créés au 1er affichage :
// un canvas dans un onglet masqué a une taille nulle) ----------
var analyseData = <?= json_encode(
    $chartData,
    JSON_NUMERIC_CHECK
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    | JSON_UNESCAPED_UNICODE
) ?>;
var analyseChartsDone = false;
function initAnalyseCharts() {
    if (analyseChartsDone || typeof Chart === 'undefined') return;
    analyseChartsDone = true;

    // Défauts repris de la page Analytics.
    Chart.defaults.color = '#9fb3c8';
    Chart.defaults.borderColor = 'rgba(255,255,255,.08)';
    Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";

    var teal = '#48bdd3', amber = '#f59e0b', red = '#ef4444';
    function money(v) { return Number(v).toFixed(2).replace('.', ',') + ' €'; }

    // « Par changement » : barres (perte €, teal) + courbe (nb écarts, amber, 2e axe).
    var ps = analyseData.perSession || { labels: [], loss: [], gaps: [] };
    var elPs = document.getElementById('chart-sessions');
    if (elPs) {
        new Chart(elPs, {
            type: 'bar',
            data: {
                labels: ps.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Perte estimée (€)',
                        data: ps.loss,
                        backgroundColor: 'rgba(72,189,211,.55)',
                        borderColor: teal,
                        borderWidth: 1,
                        borderRadius: 4,
                        yAxisID: 'y',
                        order: 2,
                    },
                    {
                        type: 'line',
                        label: 'Écarts détectés',
                        data: ps.gaps,
                        borderColor: amber,
                        backgroundColor: 'rgba(245,158,11,.12)',
                        tension: 0.35,
                        pointBackgroundColor: amber,
                        pointRadius: 3,
                        fill: false,
                        yAxisID: 'y1',
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
                                return c.dataset.label + ' : '
                                    + (c.dataset.yAxisID === 'y1' ? c.raw : money(c.raw));
                            },
                        },
                    },
                },
                scales: {
                    y:  { beginAtZero: true, ticks: { callback: money } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } },
                },
            },
        });
    }

    // « Top produits (perte €) » : barres horizontales rouges.
    var tp = analyseData.topProducts || { labels: [], loss: [] };
    var elTp = document.getElementById('chart-top');
    if (elTp) {
        new Chart(elTp, {
            type: 'bar',
            data: {
                labels: tp.labels,
                datasets: [{
                    label: 'Perte (€)',
                    data: tp.loss,
                    backgroundColor: red,
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

// ---------- Saisie par collage (onglet Nouvelle saisie) ----------
// Analyse la zone de collage (auto sur paste/input, debouncée), apparie
// chaque libellé aux produits connus (InventaireTabletMatch) et reconstruit
// l'aperçu + les champs cachés keys[] / qtys[] / avis[] (alignés, lignes
// valides uniquement — même contrat POST qu'avant).
(function () {
    var form = document.getElementById('tablet-paste-form');
    if (!form || typeof window.InventaireTabletParseFull !== 'function') return;

    var products = invProducts || [];
    var index = window.InventaireTabletBuildIndex(products);
    var theoMap = new Map();
    products.forEach(function (p) {
        theoMap.set(p.key, p.theoretical === null ? null : Number(p.theoretical));
    });

    var area = document.getElementById('inv-paste');
    var tbody = document.getElementById('inv-paste-tbody');
    var preview = document.getElementById('inv-paste-preview');
    var summary = document.getElementById('inv-paste-summary');
    var payload = document.getElementById('inv-paste-payload');
    var submitBtn = document.getElementById('inv-paste-submit');
    if (!area || !tbody || !preview || !summary || !payload || !submitBtn) return;

    // rows/resolved : sorties du parseur/apparieur ; chosen/avis : choix
    // manuels de l'utilisateur par ligne (clé valide + avis optionnel).
    var state = { rows: [], ignored: 0, resolved: [], chosen: [], avis: [] };

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }

    /** Clé produit retenue pour la ligne i (auto-reconnue ou choisie). */
    function effKey(i) {
        var m = state.resolved[i];
        if (!m) return null;
        if (m.status === 'exact' || m.status === 'check') return m.key;
        var c = state.chosen[i];
        return (c && index.keys.indexOf(c) !== -1) ? c : null;
    }

    /** Dernière ligne portant chaque clé (doublon : la dernière gagne). */
    function lastIdxByKey() {
        var last = {};
        for (var i = 0; i < state.rows.length; i++) {
            var k = effKey(i);
            if (k) last[k] = i;
        }
        return last;
    }

    function addHidden(name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        payload.appendChild(input);
    }

    /** Reconstruit keys[]/qtys[]/avis[] alignés sur les lignes valides. */
    function buildPayload() {
        payload.innerHTML = '';
        var last = lastIdxByKey();
        var n = 0;
        for (var i = 0; i < state.rows.length; i++) {
            var key = effKey(i);
            var row = state.rows[i];
            if (!key || row.qty === null || (last[key] !== i)) continue;
            addHidden('keys[]', key);
            addHidden('qtys[]', String(row.qty));
            addHidden('avis[]', state.avis[i] || '');
            n++;
        }
        submitBtn.disabled = n === 0;
        return n;
    }

    function render() {
        tbody.innerHTML = '';
        var last = lastIdxByKey();

        for (var i = 0; i < state.rows.length; i++) {
            var row = state.rows[i];
            var m = state.resolved[i];
            var key = effKey(i);
            var dup = key !== null && last[key] !== i;
            var qtyNull = row.qty === null;

            var tr = document.createElement('tr');
            if (!key || qtyNull) tr.className = 'inv-paste-tr-bad';
            else if (dup || m.status === 'check') tr.className = 'inv-paste-tr-check';

            // Libellé collé
            tr.appendChild(el('td', null, row.label));

            // Produit reconnu
            var tdP = el('td');
            if (key !== null && (m.status === 'exact' || m.status === 'check')) {
                tdP.appendChild(el('strong', null, key));
                if (m.status === 'check') tdP.appendChild(el('span', 'badge badge-warning inv-badge', 'à vérifier'));
            } else if (m.status === 'ambiguous' && m.candidates.length > 0) {
                tdP.appendChild(el('span', 'muted', 'Plusieurs produits possibles : '));
                var sel = document.createElement('select');
                sel.className = 'inv-paste-key';
                sel.dataset.row = i;
                var opt0 = document.createElement('option');
                opt0.value = '';
                opt0.textContent = '— choisir —';
                sel.appendChild(opt0);
                m.candidates.forEach(function (c) {
                    var o = document.createElement('option');
                    o.value = c;
                    o.textContent = c;
                    if (state.chosen[i] === c) o.selected = true;
                    sel.appendChild(o);
                });
                tdP.appendChild(sel);
            } else {
                var inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'inv-paste-key';
                inp.setAttribute('list', 'inv-keys-list');
                inp.dataset.row = i;
                inp.placeholder = 'Produit introuvable — choisir…';
                inp.value = state.chosen[i] || '';
                tdP.appendChild(inp);
            }
            if (dup) tdP.appendChild(el('span', 'badge badge-warning inv-badge', 'doublon, la dernière valeur gagne'));
            if (qtyNull) tdP.appendChild(el('span', 'badge badge-danger inv-badge', 'quantité manquante'));
            tr.appendChild(tdP);

            // Qté (rouge si négative : la tablette affiche un stock négatif)
            var tdQ = el('td', 'num');
            if (qtyNull) tdQ.appendChild(el('span', 'muted', '—'));
            else if (row.qty < 0) tdQ.appendChild(el('span', 'is-negative', String(row.qty)));
            else tdQ.textContent = String(row.qty);
            tr.appendChild(tdQ);

            // Stock site + écart (aperçu indicatif : recalculés côté serveur)
            var theo = key !== null && theoMap.has(key) ? theoMap.get(key) : null;
            tr.appendChild(el('td', 'num', theo === null ? '—' : String(theo)));
            var tdG = el('td', 'num');
            if (theo === null || qtyNull) tdG.appendChild(el('span', 'muted', '—'));
            else {
                var gap = row.qty - theo;
                tdG.appendChild(el('span',
                    'inv-paste-gap ' + (gap < 0 ? 'is-missing' : (gap > 0 ? 'is-surplus' : 'is-even')),
                    gap < 0 ? gap + ' · manquant' : (gap > 0 ? '+' + gap + ' · surplus' : '0'))
                );
            }
            tr.appendChild(tdG);

            // Avis (optionnel, aucune valeur par défaut)
            var tdA = el('td');
            var avis = document.createElement('input');
            avis.type = 'text';
            avis.maxLength = 500;
            avis.className = 'inv-paste-avis';
            avis.dataset.row = i;
            avis.value = state.avis[i] || '';
            tdA.appendChild(avis);
            tr.appendChild(tdA);

            tbody.appendChild(tr);
        }

        var n = buildPayload();
        preview.hidden = state.rows.length === 0;
        summary.hidden = state.rows.length === 0 && state.ignored === 0;
        summary.textContent = n + ' ligne(s) reconnue(s) · '
            + (state.rows.length - n) + ' à préciser · '
            + state.ignored + ' ignorée(s)';
    }

    function analyze() {
        if (timer) { clearTimeout(timer); timer = null; }
        var parsed = window.InventaireTabletParseFull(area.value);
        state = {
            rows: parsed.rows,
            ignored: parsed.ignored,
            resolved: parsed.rows.map(function (r) {
                return window.InventaireTabletMatch(r.label, index);
            }),
            chosen: [],
            avis: [],
        };
        render();
    }

    // Analyse automatique sur collage/frappe (debounce ~300 ms).
    var timer = null;
    function schedule() {
        if (timer) clearTimeout(timer);
        timer = setTimeout(analyze, 300);
    }
    area.addEventListener('input', schedule);
    area.addEventListener('paste', schedule);
    document.getElementById('inv-paste-analyze').addEventListener('click', analyze);
    document.getElementById('inv-paste-clear').addEventListener('click', function () {
        area.value = '';
        analyze();
    });

    // Saisies de l'aperçu : l'avis se mémorise sans re-rendre (saisie
    // fluide) ; un changement de clé revalide la ligne.
    tbody.addEventListener('input', function (ev) {
        var t = ev.target;
        if (t.classList && t.classList.contains('inv-paste-avis')) {
            state.avis[Number(t.dataset.row)] = t.value;
        }
    });
    tbody.addEventListener('change', function (ev) {
        var t = ev.target;
        if (t.classList && t.classList.contains('inv-paste-key')) {
            state.chosen[Number(t.dataset.row)] = String(t.value || '').trim();
            render();
        }
    });

    // Juste avant l'envoi : resynchronise les saisies non « blurées » et
    // reconstruit le POST aligné ; rien d'envoyé si aucune ligne valide.
    form.addEventListener('submit', function (ev) {
        Array.prototype.forEach.call(tbody.querySelectorAll('.inv-paste-key'), function (elm) {
            state.chosen[Number(elm.dataset.row)] = String(elm.value || '').trim();
        });
        Array.prototype.forEach.call(tbody.querySelectorAll('.inv-paste-avis'), function (elm) {
            state.avis[Number(elm.dataset.row)] = elm.value;
        });
        if (buildPayload() === 0) ev.preventDefault();
    });
})();

// ---------- Onglets compta : bascule côté client, mémorisée dans
// l'URL (?tab=…) — même mécanique que la vue Achats. ----------
(function () {
    var bar = document.querySelector('[data-compta-tabs]');
    if (!bar) return;
    var tabs = bar.querySelectorAll('.compta-tab');
    var panes = document.querySelectorAll('.compta-tabpane[data-pane]');

    function activate(name, push) {
        var found = false;
        Array.prototype.forEach.call(tabs, function (t) {
            var on = t.getAttribute('data-tab') === name;
            t.classList.toggle('is-active', on);
            if (on) found = true;
        });
        if (!found) name = tabs[0].getAttribute('data-tab');
        Array.prototype.forEach.call(panes, function (p) {
            var on = p.getAttribute('data-pane') === name;
            p.classList.toggle('is-active', on);
            p.hidden = !on;
        });
        if (name === 'analyse' && typeof initAnalyseCharts === 'function') {
            initAnalyseCharts();
        }
        if (push && window.history && window.history.replaceState) {
            var u = new URL(window.location.href);
            if (name === tabs[0].getAttribute('data-tab')) u.searchParams.delete('tab');
            else u.searchParams.set('tab', name);
            window.history.replaceState(null, '', u.toString());
        }
    }

    Array.prototype.forEach.call(tabs, function (t) {
        t.addEventListener('click', function () { activate(t.getAttribute('data-tab'), true); });
    });

    // Onglet initial : valeur du contrôleur (elle-même issue de ?tab=).
    var initial = <?= json_encode($tab) ?>;
    if (!initial) {
        initial = new URLSearchParams(window.location.search).get('tab');
    }
    if (initial) {
        activate(initial, false);
    }
})();

// ---------- Pastilles de période (onglet Analyse) ----------
(function () {
    var form = document.getElementById('inv-period-form');
    if (!form) return;
    function ymd(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
            + '-' + String(d.getDate()).padStart(2, '0');
    }
    form.querySelectorAll('.af-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            var days = parseInt(pill.getAttribute('data-days'), 10);
            var to = new Date();
            var from = new Date(to);
            if (days > 0) {
                from.setDate(from.getDate() - (days - 1));
            } else {
                from = new Date(2000, 0, 1);
            }
            document.getElementById('inv-from').value = ymd(from);
            document.getElementById('inv-to').value = ymd(to);
            form.submit();
        });
    });
})();
</script>

<style>
/* Teintes spécifiques à la vue Inventaire tablette (le reste vient
   d'admin.css : .compta-tabs, .compta-kpis, .compta-grid…). */
.inv-paste-zone { margin: .25rem 0 .75rem; }
.inv-paste-input {
    width: 100%;
    min-height: 12rem;
    font-family: ui-monospace, SFMono-Regular, Consolas, 'Courier New', monospace;
    font-size: .9rem;
    line-height: 1.45;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 8px;
    color: var(--foreground);
    padding: .6rem .75rem;
    resize: vertical;
}
.inv-paste-input:focus { outline: none; border-color: var(--primary, #48bdd3); }
.inv-paste-actions { display: flex; gap: .5rem; margin-top: .5rem; }
.inv-paste-help { margin-top: .5rem; font-size: .82rem; }
.inv-paste-summary { margin: .5rem 0; font-weight: 600; }
.inv-paste-tr-check { background: rgba(245,158,11,.08); }
.inv-paste-tr-bad { background: rgba(239,68,68,.10); }
.inv-badge { margin-left: .4rem; }
.inv-paste-key { max-width: 16rem; width: auto; }
#inv-paste-table td.num { white-space: nowrap; }
#inv-paste-table .is-negative { color: var(--accent-danger, #ef4444); font-weight: 700; }
.inv-paste-gap.is-missing { color: var(--accent-danger, #ef4444); font-weight: 700; }
.inv-paste-gap.is-surplus { color: #22c55e; font-weight: 700; }
.inv-paste-gap.is-even { color: var(--muted, #9fb3c8); }
.inv-form-note { margin-top: .75rem; }

.inv-delta { font-size: .78rem; font-weight: 700; display: block; margin-top: .15rem; }
.inv-delta.is-good { color: #22c55e; }
.inv-delta.is-bad { color: #ef4444; }
.inv-delta.is-muted { color: var(--muted, #9fb3c8); font-weight: 400; }

.inv-period { padding: 1rem 1.25rem; margin-bottom: 1rem; }
.inv-period-row { display: flex; flex-wrap: wrap; gap: .75rem 1.5rem; align-items: center; }
.af-group { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.af-label {
    font-size: .72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .04em; color: var(--muted, #9fb3c8); white-space: nowrap;
}
.af-pills { display: flex; flex-wrap: wrap; gap: .35rem; }
.af-pill {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.1);
    color: var(--muted, #9fb3c8);
    padding: .42rem .85rem;
    border-radius: 999px;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}
.af-pill:hover {
    border-color: rgba(72,189,211,0.4);
    color: var(--foreground);
    background: rgba(72,189,211,0.06);
}
.af-input {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.12);
    color: var(--foreground);
    border-radius: 8px;
    padding: .5rem .7rem;
    font-size: .88rem;
}
.af-input:focus { outline: none; border-color: var(--primary, #48bdd3); }

.chart-canvas { position: relative; height: 280px; margin-top: .75rem; }
.inv-empty { padding: 1.5rem 0; text-align: center; }

.inv-compare { margin-top: 1.25rem; }
.inv-compare-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; margin-top: .5rem; }
@media (max-width: 720px) { .inv-compare-grid { grid-template-columns: 1fr; } }
.inv-compare-col { border: 1px solid rgba(255,255,255,.08); border-radius: 10px; padding: .75rem .9rem; }
.inv-compare-col p { margin: .15rem 0; }
.inv-compare-line strong { font-size: 1.05rem; }
</style>
