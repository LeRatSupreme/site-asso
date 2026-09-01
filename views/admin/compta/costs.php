<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>>                $items
 * @var list<string>                             $categories
 * @var list<string>                             $productKeys
 * @var array<string,mixed>                      $form
 * @var array<string,mixed>|null                 $editLot
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Coûts de revient</h1>
        <p class="muted">Dis <strong>combien chaque produit t'a coûté à l'achat</strong>. Sans ça, on a le chiffre d'affaires mais pas le vrai bénéfice.</p>
    </div>
</div>

<!-- Explication -->
<section class="card surface glass cost-explain">
    <h2 class="card-title">Comment ça marche ?</h2>
    <div class="cost-formula">
        <div class="cost-formula-item">
            <span class="cost-formula-label">Prix de vente TTC</span>
            <span class="cost-formula-value">ce que l'étudiant paie</span>
            <span class="cost-formula-eg">ex : 1,00 €</span>
        </div>
        <span class="cost-formula-op">−</span>
        <div class="cost-formula-item cost-formula-cost">
            <span class="cost-formula-label">Coût d'achat unitaire</span>
            <span class="cost-formula-value">ce que TU achètes 1 unité</span>
            <span class="cost-formula-eg">ex : 0,60 €</span>
        </div>
        <span class="cost-formula-op">=</span>
        <div class="cost-formula-item cost-formula-profit">
            <span class="cost-formula-label">Bénéfice par unité</span>
            <span class="cost-formula-value">ta marge réelle</span>
            <span class="cost-formula-eg">ex : 0,40 € (40 %)</span>
        </div>
    </div>
    <div class="cost-tips">
        <p>📌 Le « coût d'achat » = ton prix pour <strong>une unité</strong> (un Bueno, une canette…), pas le prix de vente.</p>
        <p>📌 Dès qu'au moins un lot est saisi pour un produit, le bénéfice se calcule automatiquement (le lot le plus pertinent est appliqué selon la date de vente).</p>
        <p>📌 Tu peux <strong>modifier</strong> un lot (✏️), le <strong>clôturer</strong> ou le <strong>supprimer</strong> (icône corbeille).</p>
    </div>
</section>

<div class="costs-layout">

    <!-- Formulaire d'ajout / modification (sticky) -->
    <section class="card surface glass costs-form" id="costs-form">
        <h2 class="card-title"><?= $editLot !== null ? 'Modifier le lot' : 'Ajouter un lot' ?></h2>
        <form method="post" action="<?= e(url($editLot !== null
            ? '/admin/compta/couts/' . rawurlencode((string) $editLot['id']) . '/update'
            : '/admin/compta/couts/save')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label for="product_key">Produit</label>
                <div class="combobox" id="pk-combobox">
                    <input type="text" id="product_key" name="product_key" class="combobox-input"
                        value="<?= e((string) $form['product_key']) ?>" placeholder="Rechercher un produit…"
                        autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="pk-listbox" required
                        <?= $editLot !== null ? 'readonly' : '' ?>>
                    <span class="combobox-badge" id="pk-count" hidden></span>
                    <ul class="combobox-list" id="pk-listbox" role="listbox" hidden></ul>
                </div>
                <p class="field-help"><?= $editLot !== null ? 'Lot en modification — le produit ne change pas.' : 'Choisis dans la liste (mêmes noms que dans les ventes) pour éviter les fautes.' ?></p>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="cost_price">Coût d'achat (€)</label>
                    <input type="text" id="cost_price" name="cost_price" value="<?= e((string) $form['cost_price']) ?>" placeholder="ex: 0,60" inputmode="decimal" required>
                </div>
                <div class="field">
                    <label for="valid_from">Du</label>
                    <input type="date" id="valid_from" name="valid_from" value="<?= e((string) $form['valid_from']) ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="supplier">Fournisseur <span class="muted">(optionnel)</span></label>
                <input type="text" id="supplier" name="supplier" value="<?= e((string) $form['supplier']) ?>" placeholder="ex: Metro…">
            </div>

            <button type="submit" class="btn btn-primary btn-block"><?= $editLot !== null ? 'Mettre à jour' : 'Enregistrer' ?></button>
            <?php if ($editLot !== null): ?>
                <a class="btn btn-ghost btn-block" href="<?= e(url('/admin/compta/couts')) ?>">Annuler la modification</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- Liste des produits -->
    <section class="costs-list">
        <div class="costs-toolbar">
            <div class="search-box">
                <input type="text" id="c-search" placeholder="🔎 Rechercher un produit…" autocomplete="off">
            </div>
            <select id="c-cat" aria-label="Filtrer par catégorie">
                <option value="">Toutes les catégories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= e(strtolower($cat)) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="filter-check">
                <input type="checkbox" id="c-nocost"> Sans coût uniquement
            </label>
            <span class="costs-count muted" id="c-count"></span>
        </div>

        <div id="c-list">
            <?php
                // Regroupement par catégorie pour la lisibilité.
                $byCat = [];
                foreach ($items as $it) {
                    $byCat[(string) $it['category']][] = $it;
                }
                ksort($byCat);
            ?>
            <?php foreach ($byCat as $catName => $catItems): ?>
                <section class="cost-group" data-cat="<?= e(strtolower((string) $catName)) ?>">
                    <h2 class="cost-group-title"><?= e($catName) ?> <span class="muted">(<?= count($catItems) ?>)</span></h2>
                    <div class="cost-cards">
                        <?php foreach ($catItems as $it):
                            $name = (string) $it['name'];
                            $hasCost = $it['currentCost'] !== null;
                        ?>
                            <article
                                class="cost-card <?= $hasCost ? 'has-cost' : 'no-cost' ?>"
                                data-name="<?= e(strtolower($name)) ?>"
                                data-cat="<?= e(strtolower((string) $it['category'])) ?>"
                                data-nocost="<?= $hasCost ? '0' : '1' ?>">

                                <div class="cost-card-head">
                                    <div class="cost-card-id">
                                        <h3><?= e($name) ?></h3>
                                        <div class="cost-card-meta">
                                            <?php if ((int) $it['qty'] > 0): ?>
                                                <span class="muted"><?= (int) $it['qty'] ?> vendus</span>
                                            <?php endif; ?>
                                            <?php if ($it['lotsCount'] > 0): ?>
                                                <span class="muted"><?= (int) $it['lotsCount'] ?> lot<?= $it['lotsCount'] > 1 ? 's' : '' ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="cost-card-current">
                                        <?php if ($hasCost): ?>
                                            <span class="badge badge-success">Lot en cours</span>
                                            <strong class="cost-card-price"><?= e(formatPrice($it['currentCost'])) ?><span class="muted"> /unité</span></strong>
                                            <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/couts?edit=' . rawurlencode((string) $it['currentLotId']) . '#costs-form')) ?>">✏️ Modifier</a>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Aucun coût</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($it['lotsCount'] > 0): ?>
                                    <details class="cost-card-lots">
                                        <summary>Voir les <?= (int) $it['lotsCount'] ?> lot<?= $it['lotsCount'] > 1 ? 's' : '' ?></summary>
                                        <table class="table">
                                            <thead><tr><th>Coût unit.</th><th>Du</th><th>Au</th><th>Fournisseur</th><th>Actions</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($it['lots'] as $c): ?>
                                                    <tr class="<?= empty($c['valid_to']) ? 'row-current' : '' ?>">
                                                        <td><strong><?= e(formatPrice($c['cost_price'])) ?></strong></td>
                                                        <td><?= e(formatDate($c['valid_from'])) ?></td>
                                                        <td>
                                                            <?php if (!empty($c['valid_to'])): ?>
                                                                <?= e(formatDate($c['valid_to'])) ?>
                                                            <?php else: ?>
                                                                <span class="badge badge-success">en cours</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= e((string) ($c['supplier'] ?? '—')) ?></td>
                                                        <td class="row-actions">
                                                            <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/couts?edit=' . rawurlencode((string) $c['id']) . '#costs-form')) ?>">✏️ Modifier</a>
                                                            <?php if (empty($c['valid_to'])): ?>
                                                                <form method="post" action="<?= e(url('/admin/compta/couts/' . rawurlencode((string) $c['id']) . '/close')) ?>" data-confirm="Clôturer ce lot maintenant ?">
                                                                    <?= csrf_field() ?>
                                                                    <button type="submit" class="btn btn-outline btn-sm">Clôturer</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="post" action="<?= e(url('/admin/compta/couts/' . rawurlencode((string) $c['id']) . '/delete')) ?>" data-confirm="Supprimer ce lot ? Action irréversible.">
                                                                <?= csrf_field() ?>
                                                                <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </details>
                                <?php endif; ?>

                                <div class="cost-card-add">
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/compta/couts?product_key=' . rawurlencode($name))) ?>">＋ Ajouter un lot pour <?= e($name) ?></a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php if ($items === []): ?>
                <div class="empty-state">
                    <p class="muted">Aucun produit. Importe d'abord un rapport SumUp (<code>/admin/compta/import</code>), puis reviens ici.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script type="application/json" id="pk-data"><?= json_encode($productKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script>
/* Combobox produit (recherche du champ d'ajout) */
(function () {
    var dataEl = document.getElementById('pk-data');
    var keys = [];
    try { keys = JSON.parse(dataEl.textContent) || []; } catch (e) { keys = []; }
    keys = keys.filter(function (k) { return typeof k === 'string' && k.trim() !== ''; });
    var input = document.getElementById('product_key');
    var listbox = document.getElementById('pk-listbox');
    var countEl = document.getElementById('pk-count');
    if (!input || !listbox) return;

    function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }
    function highlight(text, q) {
        if (!q) return escapeHtml(text);
        var i = norm(text).indexOf(norm(q)); if (i === -1) return escapeHtml(text);
        return escapeHtml(text.substring(0,i)) + '<mark>' + escapeHtml(text.substring(i,i+q.length)) + '</mark>' + escapeHtml(text.substring(i+q.length));
    }
    function build(q) {
        var nq = norm(q);
        var matches = nq === '' ? keys.slice(0,100) : keys.filter(function (k){ return norm(k).indexOf(nq)!==-1; });
        matches.sort(function (a,b){ var ai=norm(a).indexOf(nq), bi=norm(b).indexOf(nq); if(nq!==''&&ai!==bi) return ai-bi; return a.localeCompare(b); });
        var html=''; matches.forEach(function(k,idx){ html += '<li class="combobox-option" role="option" data-value="'+escapeHtml(k)+'">'+highlight(k,q)+'</li>'; });
        if (html==='') html = '<li class="combobox-empty">Aucun produit. Saisis un nom pour le créer.</li>';
        listbox.innerHTML = html;
        countEl.hidden = true;
        Array.prototype.forEach.call(listbox.querySelectorAll('.combobox-option'), function (li){ li.addEventListener('mousedown', function (e){ e.preventDefault(); input.value=li.getAttribute('data-value'); listbox.hidden=true; input.focus(); }); });
    }
    input.addEventListener('focus', function(){ listbox.hidden=false; build(input.value); input.setAttribute('aria-expanded','true'); });
    input.addEventListener('input', function(){ listbox.hidden=false; build(input.value); input.setAttribute('aria-expanded','true'); });
    input.addEventListener('blur', function(){ setTimeout(function(){ listbox.hidden=true; input.setAttribute('aria-expanded','false'); }, 150); });
})();

/* Filtres de la liste des produits */
(function () {
    var search = document.getElementById('c-search');
    var catSel = document.getElementById('c-cat');
    var noCost = document.getElementById('c-nocost');
    var countEl = document.getElementById('c-count');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.cost-card'));
    var total = cards.length;
    if (!search) return;

    function norm(s){ return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim(); }
    function apply(){
        var q = norm(search.value);
        var cat = catSel.value;
        var onlyNoCost = noCost.checked;
        var shown = 0;
        cards.forEach(function (card){
            var okSearch = q==='' || norm(card.getAttribute('data-name')).indexOf(q)!==-1;
            var okCat = cat==='' || card.getAttribute('data-cat')===cat;
            var okCost = !onlyNoCost || card.getAttribute('data-nocost')==='1';
            var visible = okSearch && okCat && okCost;
            card.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });
        // Masque les groupes de catégorie devenus vides.
        var groups = document.querySelectorAll('.cost-group');
        groups.forEach(function (g){
            var anyVisible = Array.prototype.some.call(g.querySelectorAll('.cost-card'), function (c){ return c.style.display !== 'none'; });
            g.style.display = anyVisible ? '' : 'none';
        });
        countEl.textContent = shown + ' / ' + total + ' produit' + (total>1?'s':'');
    }
    search.addEventListener('input', apply);
    catSel.addEventListener('change', apply);
    noCost.addEventListener('change', apply);
    apply();
})();
</script>
