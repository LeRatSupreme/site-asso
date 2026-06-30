<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var array<string,array{label:string,days:int}> $periods
 * @var string $currentPeriod
 * @var int $targetDays
 * @var int $alerts
 */

function reorder_qty(float $v): string {
    return number_format($v, ($v >= 10 ? 0 : 1), ',', ' ');
}
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Comptabilité</p>
            <h1 class="page-title">Réapprovisionnement</h1>
            <p class="muted">Basé sur <strong>toutes les ventes importées</strong> (SumUp). Saisis le stock de chaque produit : la quantité à commander se recalcule (besoin sur la période − stock).</p>
        </div>
        <form method="get" class="compta-monthselect">
            <label for="period" class="muted" style="display:block;font-size:0.75rem;margin-bottom:0.3rem">Couvrir pour :</label>
            <select name="period" id="period" onchange="this.form.submit()">
                <?php foreach ($periods as $k => $p): ?>
                    <option value="<?= e($k) ?>" <?= $k === $currentPeriod ? 'selected' : '' ?>><?= e($p['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="alert alert-info">
    🕒 <strong>Cafétéria ouverte du lundi au vendredi</strong> (fermée samedi/dimanche). Les consommations moyennes et les besoins sont calculés en <strong>jours d'ouverture</strong> (5 j/sem ≈ 22 j/mois).
</div>

<?php if ($alerts > 0): ?>
    <div class="alert alert-warning">
        ⚠️ <strong><?= $alerts ?></strong> produit<?= $alerts > 1 ? 's ont' : ' a' ?> un stock faible (autonomie &lt; 7 jours) — à racheter en priorité.
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/compta/reappro/stocks')) ?>" id="reorder-form">
    <?= csrf_field() ?>
    <input type="hidden" name="period" value="<?= e($currentPeriod) ?>">

    <div class="costs-toolbar">
        <div class="search-box">
            <input type="text" id="reorder-search" placeholder="🔎 Rechercher un produit…" autocomplete="off">
        </div>
        <select id="reorder-cat" aria-label="Filtrer par catégorie">
            <option value="">Toutes les catégories</option>
        </select>
        <select id="reorder-sort" aria-label="Trier par">
            <option value="to_order">À commander ↓</option>
            <option value="name">Produit (A→Z)</option>
            <option value="name-desc">Produit (Z→A)</option>
            <option value="cat">Catégorie (A→Z)</option>
            <option value="month-desc">Conso / mois ↓</option>
            <option value="need-desc">Besoin ↓</option>
            <option value="stock-asc">Stock ↑</option>
            <option value="stock-desc">Stock ↓</option>
            <option value="autonomy-asc">Autonomie ↑</option>
        </select>
        <span class="costs-count muted" id="reorder-count"></span>
        <button type="submit" class="btn btn-primary btn-sm">💾 Enregistrer les stocks</button>
    </div>

    <div class="card surface glass table-wrap">
        <table class="table reorder-table">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Catégorie</th>
                    <th class="th-num">Stock actuel</th>
                    <th class="th-num">Conso moy.<br>/ jour <small>(ouv.)</small></th>
                    <th class="th-num">Conso moy.<br>/ semaine</th>
                    <th class="th-num">Conso moy.<br>/ mois</th>
                    <th class="th-num">Besoin<br>(<?= e($periods[$currentPeriod]['label']) ?>)</th>
                    <th class="th-num">À commander</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $key = (string) $r['name'];
                    $stockVal = ($r['stock'] !== null) ? (string) (int) $r['stock'] : '';
                ?>
                    <tr class="<?= !empty($r['is_alert']) ? 'row-alert' : '' ?>"
                        data-name="<?= e(strtolower($key)) ?>"
                        data-cat="<?= e(strtolower((string) $r['category'])) ?>"
                        data-stock="<?= (int) ($r['stock'] ?? 0) ?>"
                        data-month="<?= (float) $r['avg_month'] ?>"
                        data-need="<?= (int) $r['need'] ?>"
                        data-toorder="<?= (int) $r['to_order'] ?>"
                        data-autonomy="<?= $r['autonomy'] === null ? 99999 : (int) $r['autonomy'] ?>"
                        data-need-orig="<?= (int) $r['need'] ?>">
                        <td><strong><?= e($key) ?></strong></td>
                        <td><?= e((string) $r['category']) ?></td>
                        <td class="num">
                            <input type="hidden" name="keys[]" value="<?= e($key) ?>">
                            <input type="number" class="stock-input" name="values[]"
                                   value="<?= e($stockVal) ?>" min="0" step="1"
                                   placeholder="—" style="width:5.5rem" inputmode="numeric">
                        </td>
                        <td class="num muted"><?= reorder_qty((float) $r['avg_day']) ?></td>
                        <td class="num muted"><?= reorder_qty((float) $r['avg_week']) ?></td>
                        <td class="num muted"><?= reorder_qty((float) $r['avg_month']) ?></td>
                        <td class="num"><?= e((string) $r['need']) ?></td>
                        <td class="num to-order-cell">
                            <?php if ((int) $r['to_order'] > 0): ?>
                                <strong style="color:var(--primary)"><?= e((string) $r['to_order']) ?></strong>
                            <?php else: ?>
                                <span class="muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (($r['state'] ?? '') === 'reorder'): ?>
                                <span class="badge badge-warning">À racheter</span>
                            <?php elseif (($r['state'] ?? '') === 'unknown'): ?>
                                <span class="badge badge-muted">À définir</span>
                            <?php else: ?>
                                <span class="badge badge-success">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="muted">Aucun produit à analyser. Importe d'abord un rapport SumUp.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($rows !== []): ?>
                <tfoot>
                    <tr>
                        <th colspan="7" style="text-align:right">Total à commander (<?= e($periods[$currentPeriod]['label']) ?>) :</th>
                        <th class="num"><strong id="reorder-total" style="color:var(--primary)">0</strong></th>
                        <th></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</form>

<p class="card-meta">
    Conso / jour = moyenne mensuelle ÷ 22 (jours d'ouverture) · Conso / semaine = conso / jour × 5 (lun-ven) · Moyenne mobile 3 mois (ventes SumUp).
    « À commander » = besoin sur la période − stock saisi. Modifie le stock puis <strong>Enregistre</strong>.
</p>

<script>
(function () {
    var search = document.getElementById('reorder-search');
    var catSel = document.getElementById('reorder-cat');
    var sortSel = document.getElementById('reorder-sort');
    var countEl = document.getElementById('reorder-count');
    var tbody = document.querySelector('.reorder-table tbody');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.reorder-table tbody tr[data-name]'));
    var totalEl = document.getElementById('reorder-total');
    var total = rows.length;

    function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }
    function num(tr, attr) { return parseFloat(tr.getAttribute(attr)) || 0; }

    // Remplit le filtre catégorie avec les catégories présentes.
    if (catSel) {
        var cats = {};
        rows.forEach(function (tr) { cats[tr.getAttribute('data-cat') || 'sans-categorie'] = true; });
        Object.keys(cats).sort().forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c;
            opt.textContent = c === 'sans-categorie' ? '(sans catégorie)' : c;
            catSel.appendChild(opt);
        });
    }

    function recomputeTotal() {
        var sum = 0;
        rows.forEach(function (tr) {
            if (tr.style.display === 'none') return;
            var cell = tr.querySelector('.to-order-cell strong');
            if (cell) sum += parseInt(cell.textContent.replace(/[^\d-]/g, ''), 10) || 0;
        });
        if (totalEl) totalEl.textContent = sum;
    }

    // Recalcul live : à commander = max(0, besoin − stock saisi).
    rows.forEach(function (tr) {
        var input = tr.querySelector('.stock-input');
        if (!input) return;
        var need = parseInt(tr.getAttribute('data-need'), 10) || 0;
        var cell = tr.querySelector('.to-order-cell');
        input.addEventListener('input', function () {
            var stock = parseInt(input.value, 10);
            stock = isNaN(stock) ? 0 : Math.max(0, stock);
            var toOrder = Math.max(0, need - stock);
            tr.setAttribute('data-toorder', String(toOrder));
            if (cell) {
                cell.innerHTML = toOrder > 0
                    ? '<strong style="color:var(--primary)">' + toOrder + '</strong>'
                    : '<span class="muted">0</span>';
            }
            recomputeTotal();
        });
    });

    function sortRows() {
        var sort = sortSel ? sortSel.value : 'to_order';
        rows.sort(function (a, b) {
            switch (sort) {
                case 'name':       return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                case 'name-desc':  return b.getAttribute('data-name').localeCompare(a.getAttribute('data-name'));
                case 'cat':        return (a.getAttribute('data-cat') || '').localeCompare(b.getAttribute('data-cat') || '');
                case 'month-desc': return num(b, 'data-month') - num(a, 'data-month');
                case 'need-desc':  return num(b, 'data-need') - num(a, 'data-need');
                case 'stock-asc':  return num(a, 'data-stock') - num(b, 'data-stock');
                case 'stock-desc': return num(b, 'data-stock') - num(a, 'data-stock');
                case 'autonomy-asc': return num(a, 'data-autonomy') - num(b, 'data-autonomy');
                default:           return num(b, 'data-toorder') - num(a, 'data-toorder'); // to_order
            }
        });
        if (tbody) {
            var frag = document.createDocumentFragment();
            rows.forEach(function (tr) { frag.appendChild(tr); });
            tbody.appendChild(frag);
        }
    }

    function apply() {
        var q = norm(search.value);
        var cat = catSel ? catSel.value : '';
        var shown = 0;
        rows.forEach(function (tr) {
            var okSearch = q === '' || norm(tr.getAttribute('data-name')).indexOf(q) !== -1;
            var okCat = cat === '' || tr.getAttribute('data-cat') === cat;
            var visible = okSearch && okCat;
            tr.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });
        countEl.textContent = shown + ' / ' + total + ' produit' + (total > 1 ? 's' : '');
        sortRows();
        recomputeTotal();
    }

    search.addEventListener('input', apply);
    if (catSel) catSel.addEventListener('change', apply);
    if (sortSel) sortSel.addEventListener('change', apply);
    apply();

    // Conserve la position de défilement après une sauvegarde (rechargement).
    var SS_KEY = 'reorder_scroll';
    var form = document.getElementById('reorder-form');
    if (form) {
        form.addEventListener('submit', function () {
            try { sessionStorage.setItem(SS_KEY, String(window.scrollY)); } catch (e) {}
        });
    }
    try {
        var y = parseInt(sessionStorage.getItem(SS_KEY) || '0', 10);
        if (y > 0) {
            window.scrollTo(0, y);
            sessionStorage.removeItem(SS_KEY);
        }
    } catch (e) {}
})();
</script>
