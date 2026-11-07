<?php

declare(strict_types=1);

/**
 * Page unifiée « Bénéfice » : fusion des anciennes pages « Bénéfice par
 * produit » et « Bénéfice par catégorie » (templates historiques supprimés,
 * leur contenu est repris tel quel dans chaque onglet). La bascule se fait
 * par LIEN ?vue=produits|categories qui conserve la période et les autres
 * paramètres GET de la page.
 *
 * CONTRAT DU CONTRÔLEUR (AdminComptaController::benefice, à conserver tel
 * quel) :
 *   - string                $vue            Onglet actif ('produits'|'categories')
 *   - list<array>           $rowsProduits   Lignes par produit ; clés calculées :
 *                                           « margin » (marge %, lot 4B)
 *   - list<array>           $rowsCategories Lignes par catégorie ; clés calculées :
 *                                           « margin » (marge %), « share » (part du CA)
 *   - array{qty:int,ca:float,profit:float,margin:float} $totaux
 *                                           Totaux de période (onglet catégories)
 *   - array{preset:string,from:?string,to:?string}     $period
 *   - array<string,string>  $periodOptions  Pastilles de période partagées
 */

/**
 * @var string $vue
 * @var list<array<string,mixed>> $rowsProduits
 * @var list<array<string,mixed>> $rowsCategories
 * @var array{qty:int,ca:float,profit:float,margin:float} $totaux
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 */

// Onglets : liens qui conservent la période courante ET les autres
// paramètres GET éventuels (recherche, etc.).
$keepVue = $_GET;
unset($keepVue['vue'], $keepVue['period'], $keepVue['from'], $keepVue['to']);
$tabQuery = static function (string $vueCible) use ($keepVue, $period): string {
    $params = $keepVue;
    $params['vue'] = $vueCible;
    if (($period['preset'] ?? '') !== '') {
        $params['period'] = $period['preset'];
    }
    if ($period['preset'] === 'custom') {
        $params['from'] = (string) ($period['from'] ?? '');
        $params['to'] = (string) ($period['to'] ?? '');
    }

    return '?' . http_build_query($params);
};

// Lignes de l'onglet actif (les contenus repris ci-dessous attendent
// $rows en scope).
$rows = $vue === 'categories' ? $rowsCategories : $rowsProduits;
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Comptabilité</p>
            <h1 class="page-title">Bénéfice</h1>
            <p class="muted">Analyse la rentabilité des ventes : par produit (quantité, chiffre d'affaires, coût et bénéfice réel) ou par grand regroupement (Boisson, Nourriture, Spécial…).</p>
        </div>
        <?php require AEIC_VIEWS . '/admin/compta/_period_bar.php'; ?>
    </div>
</div>

<!-- Bascule d'onglet : LIEN (conserve period/q), style pills charte
     (état actif teal plein). text-decoration inline car la classe est
     stylée pour des <button>. -->
<nav class="compta-tabs" aria-label="Vue bénéfice">
    <a class="compta-tab<?= $vue === 'produits' ? ' is-active' : '' ?>" style="text-decoration:none"
       href="<?= e($tabQuery('produits')) ?>">Produits</a>
    <a class="compta-tab<?= $vue === 'categories' ? ' is-active' : '' ?>" style="text-decoration:none"
       href="<?= e($tabQuery('categories')) ?>">Catégories</a>
</nav>

<?php if ($vue === 'categories'): ?>
    <?php // ===== Onglet « Catégories » (contenu repris de l'ancienne page) ===== ?>

    <?php // Totaux calculés par le contrôleur (lot 4B). ?>

    <!-- Total global : 4 cartes séparées -->
    <div class="grid grid-4 stat-cards">
        <div class="stat-card surface glass">
            <span class="stat-value"><?= (int) $totaux['qty'] ?></span>
            <span class="stat-label">Quantité vendue</span>
        </div>
        <div class="stat-card surface glass">
            <span class="stat-value"><?= e(formatPrice($totaux['ca'])) ?></span>
            <span class="stat-label">Chiffre d'affaires</span>
        </div>
        <div class="stat-card surface glass">
            <span class="stat-value is-positive"><?= e(formatPrice($totaux['profit'])) ?></span>
            <span class="stat-label">Bénéfice</span>
        </div>
        <div class="stat-card surface glass">
            <span class="stat-value"><?= e(number_format($totaux['margin'], 1, ',', ' ')) ?> %</span>
            <span class="stat-label">Marge globale</span>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <div class="empty-state card surface glass">
            <p class="muted">Aucune vente sur cette période.</p>
        </div>
    <?php else: ?>
        <!-- Filtre rapide des cartes (lot 4B, boîte à outils tableaux :
             cibles non tableau, affichage/masquage simple). -->
        <div class="table-tools">
            <input type="search" class="tt-filter" data-table-filter=".cat-card"
                   aria-label="Filtrer les catégories" placeholder="Filtrer les catégories…">
            <span class="tt-count muted" data-table-count=".cat-card" data-count-label="items" aria-live="polite"></span>
        </div>
        <div class="cat-grid">
            <?php foreach ($rows as $r): ?>
                <article class="cat-card card surface glass <?= (float) $r['profit'] >= 0 ? 'is-positive' : 'is-negative' ?>">
                    <header class="cat-card-head">
                        <h2 class="cat-card-title"><?= e((string) $r['category']) ?></h2>
                        <span class="cat-card-qty muted"><?= (int) $r['qty'] ?> vendus</span>
                    </header>

                    <div class="cat-card-metrics">
                        <div class="cat-metric">
                            <span class="cat-metric-label">Chiffre d'affaires</span>
                            <strong class="cat-metric-value"><?= e(formatPrice((float) $r['ca'])) ?></strong>
                        </div>
                        <div class="cat-metric">
                            <span class="cat-metric-label">Bénéfice</span>
                            <strong class="cat-metric-value <?= (float) $r['profit'] >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice((float) $r['profit'])) ?></strong>
                        </div>
                        <div class="cat-metric">
                            <span class="cat-metric-label">Marge</span>
                            <span class="cat-card-badge <?= (float) $r['margin'] >= 50 ? 'badge-success' : ((float) $r['margin'] >= 20 ? 'badge-info' : 'badge-warning') ?>"><?= e(number_format((float) $r['margin'], 1, ',', ' ')) ?> %</span>
                        </div>
                    </div>

                    <div class="cat-card-bar" title="Part du chiffre d'affaires">
                        <div class="cat-card-bar-fill" style="width: <?= max(2, (int) round((float) $r['share'])) ?>%"></div>
                    </div>
                    <p class="cat-card-share muted"><?= e(number_format((float) $r['share'], 1, ',', ' ')) ?> % du CA total</p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Boîte à outils tableaux (lot 4B) : filtre des cartes. -->
    <script src="<?= e(rootAssetVersioned('/assets/js/table-tools.js')) ?>" defer></script>
<?php else: ?>
    <?php // ===== Onglet « Produits » (contenu repris de l'ancienne page) ===== ?>

    <div class="costs-toolbar">
        <div class="search-box">
            <input type="text" id="pk-search" placeholder="Rechercher un produit…" autocomplete="off">
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
        <!-- Choix des colonnes (lot 4B) : partagé par toutes les tables du
             regroupement (même schéma), mémorisé d'une visite à l'autre. -->
        <details class="tt-cols" data-table-cols=".prod-table">
            <summary>Colonnes</summary>
        </details>
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
                <table class="table prod-table" data-table
                       data-cols-visible="produit,qte,prix_moyen,ca,benefice,marge">
                    <thead>
                        <tr>
                            <th data-col="produit" data-col-fixed>Produit</th>
                            <th class="th-num" data-col="qte">Qté</th>
                            <th class="th-num" data-col="prix_moyen">Prix moy.</th>
                            <th class="th-num" data-col="ca">CA</th>
                            <th class="th-num" data-col="cout_unit">Coût unit.</th>
                            <th class="th-num" data-col="benefice">Bénéfice</th>
                            <th class="th-num" data-col="marge">Marge</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catRows as $r): ?>
                            <tr
                                data-product="<?= e(strtolower((string) $r['product_key'])) ?>"
                                data-cat="<?= e(strtolower((string) $catName)) ?>"
                                data-qty="<?= (float) $r['qty'] ?>"
                                data-ca="<?= (float) $r['ca'] ?>"
                                data-profit="<?= (float) $r['profit'] ?>"
                                data-margin="<?= (float) $r['margin'] ?>">
                                <td><strong><?= e((string) $r['product_key']) ?></strong></td>
                                <td class="num"><?= e((string) $r['qty']) ?></td>
                                <td class="num"><?= e(formatPrice($r['avg_price'] ?? 0)) ?></td>
                                <td class="num"><?= e(formatPrice((float) $r['ca'])) ?></td>
                                <td class="num"><?= e(formatPrice($r['cost_price'] ?? 0, 3)) ?></td>
                                <td class="num <?= (float) $r['profit'] >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice((float) $r['profit'])) ?></td>
                                <td class="num"><?= e(number_format((float) $r['margin'], 1, ',', ' ')) ?> %</td>
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

    <!-- Boîte à outils tableaux (lot 4B) : tri par en-têtes + choix des
         colonnes (le filtre et le compteur de la barre ci-dessus restent
         les outils de référence de la page). -->
    <script src="<?= e(rootAssetVersioned('/assets/js/table-tools.js')) ?>" defer></script>
<?php endif; ?>
