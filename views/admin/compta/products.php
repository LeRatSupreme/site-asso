<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var list<array{value:string,label:string}> $months
 * @var bool $all
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Bénéfice par produit</h1>
        <p class="muted">Recherche, filtre par catégorie et tri pour analyser chaque produit : quantité vendue, chiffre d'affaires, coût et bénéfice réel.</p>
    </div>
</div>

<div class="products-toolbar">
    <form method="get" class="compta-monthselect">
        <select name="month" onchange="this.form.submit()">
            <option value="all" <?= $all ? 'selected' : '' ?>>Toute l'année</option>
            <?php foreach ($months as $m): ?>
                <option value="<?= e($m['value']) ?>" <?= (!$all && $m['value'] === ($_GET['month'] ?? '')) ? 'selected' : '' ?>><?= e($m['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="products-tools">
        <div class="search-box">
            <input type="text" id="pk-search" placeholder="🔎 Rechercher un produit…" autocomplete="off">
        </div>
        <select id="pk-cat" aria-label="Filtrer par catégorie">
            <option value="">Toutes les catégories</option>
        </select>
        <select id="pk-sort" aria-label="Trier par">
            <option value="product">Produit (A→Z)</option>
            <option value="product-desc">Produit (Z→A)</option>
            <option value="cat">Catégorie (A→Z)</option>
            <option value="qty-desc">Quantité (décroissante)</option>
            <option value="qty-asc">Quantité (croissante)</option>
            <option value="ca-desc">Chiffre d'affaires ↓</option>
            <option value="profit-desc">Bénéfice ↓</option>
            <option value="margin-desc">Marge % ↓</option>
        </select>
    </div>
</div>

<div class="card surface glass table-wrap">
    <table class="table" id="pk-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Catégorie</th>
                <th class="th-num">Qté</th>
                <th class="th-num">Prix moy.</th>
                <th class="th-num">CA</th>
                <th class="th-num">Coût unit.</th>
                <th class="th-num">Bénéfice</th>
                <th class="th-num">Marge</th>
            </tr>
        </thead>
        <tbody id="pk-body">
            <?php foreach ($rows as $r): ?>
                <?php
                    $ca     = (float) $r['ca'];
                    $profit = (float) $r['profit'];
                    $marg   = $ca > 0 ? round($profit / $ca * 100, 1) : 0.0;
                ?>
                <tr
                    data-product="<?= e(strtolower((string) $r['product_key'])) ?>"
                    data-cat="<?= e(strtolower((string) ($r['category'] ?: 'sans-categorie'))) ?>"
                    data-qty="<?= (float) $r['qty'] ?>"
                    data-ca="<?= $ca ?>"
                    data-profit="<?= $profit ?>"
                    data-margin="<?= $marg ?>">
                    <td><strong><?= e((string) $r['product_key']) ?></strong></td>
                    <td><?= e((string) ($r['category'] ?: '—')) ?></td>
                    <td class="num"><?= e((string) $r['qty']) ?></td>
                    <td class="num"><?= e(formatPrice($r['avg_price'] ?? 0)) ?></td>
                    <td class="num"><?= e(formatPrice($ca)) ?></td>
                    <td class="num"><?= e(formatPrice($r['cost_price'] ?? 0)) ?></td>
                    <td class="num <?= $profit >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($profit)) ?></td>
                    <td class="num"><?= e(number_format($marg, 1, ',', ' ')) ?> %</td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="muted">Aucune vente sur cette période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <p class="products-count muted" id="pk-count" hidden></p>
</div>

<script>
(function () {
    var search = document.getElementById('pk-search');
    var catSel = document.getElementById('pk-cat');
    var sortSel = document.getElementById('pk-sort');
    var body = document.getElementById('pk-body');
    var countEl = document.getElementById('pk-count');
    if (!body) return;
    var rows = Array.prototype.slice.call(body.querySelectorAll('tr'));
    var total = rows.length;

    // Remplit le filtre catégorie avec les catégories présentes.
    var cats = {};
    rows.forEach(function (tr) {
        var c = tr.getAttribute('data-cat') || 'sans-categorie';
        cats[c] = true;
    });
    Object.keys(cats).sort().forEach(function (c) {
        var opt = document.createElement('option');
        opt.value = c;
        opt.textContent = c === 'sans-categorie' ? '(sans catégorie)' : c;
        catSel.appendChild(opt);
    });

    function norm(s) {
        return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    }

    function apply() {
        var q = norm(search.value);
        var cat = catSel.value;
        var sort = sortSel.value;

        var visible = rows.filter(function (tr) {
            var okSearch = q === '' || norm(tr.getAttribute('data-product')).indexOf(q) !== -1;
            var okCat = cat === '' || tr.getAttribute('data-cat') === cat;
            tr.style.display = (okSearch && okCat) ? '' : 'none';
            return okSearch && okCat;
        });

        visible.sort(function (a, b) {
            switch (sort) {
                case 'product-desc': return b.getAttribute('data-product').localeCompare(a.getAttribute('data-product'));
                case 'cat':           return (a.getAttribute('data-cat')).localeCompare(b.getAttribute('data-cat'));
                case 'qty-asc':       return parseFloat(a.getAttribute('data-qty')) - parseFloat(b.getAttribute('data-qty'));
                case 'qty-desc':      return parseFloat(b.getAttribute('data-qty')) - parseFloat(a.getAttribute('data-qty'));
                case 'ca-desc':       return parseFloat(b.getAttribute('data-ca')) - parseFloat(a.getAttribute('data-ca'));
                case 'profit-desc':   return parseFloat(b.getAttribute('data-profit')) - parseFloat(a.getAttribute('data-profit'));
                case 'margin-desc':   return parseFloat(b.getAttribute('data-margin')) - parseFloat(a.getAttribute('data-margin'));
                default:              return a.getAttribute('data-product').localeCompare(b.getAttribute('data-product'));
            }
        });

        // Réordonne le tbody selon l'ordre trié.
        var frag = document.createDocumentFragment();
        visible.forEach(function (tr) { frag.appendChild(tr); });
        // Conserve aussi les lignes masquées à la fin (pour pouvoir les remonter plus tard).
        rows.filter(function (tr) { return visible.indexOf(tr) === -1; }).forEach(function (tr) { frag.appendChild(tr); });
        body.appendChild(frag);

        countEl.hidden = visible.length === total || visible.length === 0;
        countEl.textContent = visible.length + ' produit' + (visible.length > 1 ? 's' : '') + ' affiché' + (visible.length > 1 ? 's' : '') + ' sur ' + total;
    }

    search.addEventListener('input', apply);
    catSel.addEventListener('change', apply);
    sortSel.addEventListener('change', apply);
})();
</script>
