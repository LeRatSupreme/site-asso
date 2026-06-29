<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var list<array{value:string,label:string}> $months
 * @var bool $all
 */
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Comptabilité</p>
            <h1 class="page-title">Bénéfice par produit</h1>
            <p class="muted">Analyse chaque produit : quantité, chiffre d'affaires, coût et bénéfice réel. Regroupé par catégorie, avec recherche et tri.</p>
        </div>
        <form method="get" class="compta-monthselect">
            <select name="month" onchange="this.form.submit()">
                <option value="all" <?= $all ? 'selected' : '' ?>>Toute l'année</option>
                <?php foreach ($months as $m): ?>
                    <option value="<?= e($m['value']) ?>" <?= (!$all && $m['value'] === ($_GET['month'] ?? '')) ? 'selected' : '' ?>><?= e($m['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="costs-toolbar">
    <div class="search-box">
        <input type="text" id="pk-search" placeholder="🔎 Rechercher un produit…" autocomplete="off">
    </div>
    <select id="pk-cat" aria-label="Filtrer par catégorie">
        <option value="">Toutes les catégories</option>
    </select>
    <select id="pk-sort" aria-label="Trier par">
        <option value="product">Produit (A→Z)</option>
        <option value="product-desc">Produit (Z→A)</option>
        <option value="qty-desc">Quantité ↓</option>
        <option value="qty-asc">Quantité ↑</option>
        <option value="ca-desc">Chiffre d'affaires ↓</option>
        <option value="profit-desc">Bénéfice ↓</option>
        <option value="margin-desc">Marge % ↓</option>
    </select>
    <span class="costs-count muted" id="pk-count"></span>
</div>

<div id="pk-list">
<?php
    // Regroupement par catégorie.
    $byCat = [];
    foreach ($rows as $r) {
        $cat = (string) ($r['category'] ?? 'Non classé');
        $byCat[$cat][] = $r;
    }
    ksort($byCat);
?>
<?php foreach ($byCat as $catName => $catRows): ?>
    <section class="cost-group" data-cat="<?= e(strtolower((string) $catName)) ?>">
        <h2 class="cost-group-title"><?= e($catName) ?> <span class="muted">(<?= count($catRows) ?>)</span></h2>
        <div class="card surface glass table-wrap">
            <table class="table prod-table">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th class="th-num">Qté</th>
                        <th class="th-num">Prix moy.</th>
                        <th class="th-num">CA</th>
                        <th class="th-num">Coût unit.</th>
                        <th class="th-num">Bénéfice</th>
                        <th class="th-num">Marge</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($catRows as $r):
                        $ca     = (float) $r['ca'];
                        $profit = (float) $r['profit'];
                        $marg   = $ca > 0 ? round($profit / $ca * 100, 1) : 0.0;
                    ?>
                        <tr
                            data-product="<?= e(strtolower((string) $r['product_key'])) ?>"
                            data-cat="<?= e(strtolower((string) $catName)) ?>"
                            data-qty="<?= (float) $r['qty'] ?>"
                            data-ca="<?= $ca ?>"
                            data-profit="<?= $profit ?>"
                            data-margin="<?= $marg ?>">
                            <td><strong><?= e((string) $r['product_key']) ?></strong></td>
                            <td class="num"><?= e((string) $r['qty']) ?></td>
                            <td class="num"><?= e(formatPrice($r['avg_price'] ?? 0)) ?></td>
                            <td class="num"><?= e(formatPrice($ca)) ?></td>
                            <td class="num"><?= e(formatPrice($r['cost_price'] ?? 0)) ?></td>
                            <td class="num <?= $profit >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($profit)) ?></td>
                            <td class="num"><?= e(number_format($marg, 1, ',', ' ')) ?> %</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<?php if ($rows === []): ?>
    <div class="empty-state card surface glass">
        <p class="muted">Aucune vente sur cette période.</p>
    </div>
<?php endif; ?>
</div>

<script>
(function () {
    var search = document.getElementById('pk-search');
    var catSel = document.getElementById('pk-cat');
    var sortSel = document.getElementById('pk-sort');
    var countEl = document.getElementById('pk-count');
    var groups = Array.prototype.slice.call(document.querySelectorAll('#pk-list .cost-group'));
    var allRows = Array.prototype.slice.call(document.querySelectorAll('#pk-list tr[data-product]'));
    var total = allRows.length;
    if (!search) return;

    // Remplit le filtre catégorie avec les catégories présentes.
    var cats = {};
    allRows.forEach(function (tr) { cats[tr.getAttribute('data-cat')] = true; });
    Object.keys(cats).sort().forEach(function (c) {
        var opt = document.createElement('option');
        opt.value = c;
        opt.textContent = c;
        catSel.appendChild(opt);
    });

    function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }

    function sortRows(rows) {
        var sort = sortSel.value;
        rows.sort(function (a, b) {
            switch (sort) {
                case 'product-desc': return b.getAttribute('data-product').localeCompare(a.getAttribute('data-product'));
                case 'qty-asc':      return parseFloat(a.getAttribute('data-qty')) - parseFloat(b.getAttribute('data-qty'));
                case 'qty-desc':     return parseFloat(b.getAttribute('data-qty')) - parseFloat(a.getAttribute('data-qty'));
                case 'ca-desc':      return parseFloat(b.getAttribute('data-ca')) - parseFloat(a.getAttribute('data-ca'));
                case 'profit-desc':  return parseFloat(b.getAttribute('data-profit')) - parseFloat(a.getAttribute('data-profit'));
                case 'margin-desc':  return parseFloat(b.getAttribute('data-margin')) - parseFloat(a.getAttribute('data-margin'));
                default:             return a.getAttribute('data-product').localeCompare(b.getAttribute('data-product'));
            }
        });
        return rows;
    }

    function apply() {
        var q = norm(search.value);
        var cat = catSel.value;
        var shown = 0;

        groups.forEach(function (group) {
            var tbody = group.querySelector('tbody');
            var groupRows = Array.prototype.slice.call(group.querySelectorAll('tr[data-product]'));
            var visibleInGroup = [];

            groupRows.forEach(function (tr) {
                var okSearch = q === '' || norm(tr.getAttribute('data-product')).indexOf(q) !== -1;
                var okCat = cat === '' || tr.getAttribute('data-cat') === cat;
                var visible = okSearch && okCat;
                tr.style.display = visible ? '' : 'none';
                if (visible) visibleInGroup.push(tr);
            });

            // Tri des lignes visibles de ce groupe, réinjectées dans le tbody.
            sortRows(visibleInGroup);
            var frag = document.createDocumentFragment();
            visibleInGroup.forEach(function (tr) { frag.appendChild(tr); });
            // Lignes masquées remises après (pour ne pas les perdre).
            groupRows.filter(function (tr) { return visibleInGroup.indexOf(tr) === -1; }).forEach(function (tr) { frag.appendChild(tr); });
            if (tbody) tbody.appendChild(frag);

            group.style.display = visibleInGroup.length > 0 ? '' : 'none';
            shown += visibleInGroup.length;
        });

        countEl.textContent = shown + ' / ' + total + ' produit' + (total > 1 ? 's' : '');
    }

    search.addEventListener('input', apply);
    catSel.addEventListener('change', apply);
    sortSel.addEventListener('change', apply);
    apply();
})();
</script>
