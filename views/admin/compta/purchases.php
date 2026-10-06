<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $user
 * @var list<array<string,mixed>> $rows
 * @var list<string>              $products
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string>      $periodOptions
 * @var array{lines:int, qty:int, ht:float, ttc:float, vat:float} $stats
 * @var list<array{rate:?float, lines:int, ht:float, ttc:float, vat:float}> $vatRows
 * @var list<string>              $allKeys
 */

// Prévention de doublons : la liste des clés existantes est normalisée
// côté PHP (même règle que StockPublic::normalizeKey) et injectée en JS
// via l'attribut data-dup-keys du tableau de saisie — le JS compare la
// saisie normalisée à cette carte et avertit sans bloquer.
$normKeys = [];
foreach ($allKeys as $k) {
    $n = \App\Core\Compta\StockPublic::normalizeKey((string) $k);
    if ($n !== '' && !isset($normKeys[$n])) {
        $normKeys[$n] = (string) $k;
    }
}
?>
<?php
// En-tête et onglets de niveau 1 fournis par la page fusionnée
// « Opérations » (operations.php) qui inclut cette vue.
?>
<div class="admin-actions">
    <?php require AEIC_VIEWS . '/admin/compta/_period_bar.php'; ?>
</div>

<nav class="compta-tabs" data-compta-tabs aria-label="Sections Achats & stock">
    <button type="button" class="compta-tab is-active" data-tab="total">Total des achats</button>
    <button type="button" class="compta-tab" data-tab="saisie">Saisir des achats</button>
    <button type="button" class="compta-tab" data-tab="tva">TVA payée</button>
    <button type="button" class="compta-tab" data-tab="journal">Journal</button>
</nav>

<!-- ==================== Onglet : Total des achats ==================== -->
<div class="compta-tabpane is-active" data-pane="total">
    <div class="compta-kpis">
        <div class="card surface glass kpi">
            <p class="kpi-label">Total des achats</p>
            <p class="kpi-value"><?= e(formatPrice($stats['ttc'])) ?></p>
            <p class="kpi-sub"><?= (int) $stats['lines'] ?> ligne<?= (int) $stats['lines'] > 1 ? 's' : '' ?> · sur la période</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Quantité reçue</p>
            <p class="kpi-value"><?= (int) $stats['qty'] ?></p>
            <p class="kpi-sub">unités entrées en stock</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Total HT</p>
            <p class="kpi-value"><?= e(formatPrice($stats['ht'])) ?></p>
            <p class="kpi-sub">hors TVA</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Dont TVA</p>
            <p class="kpi-value"><?= e(formatPrice($stats['vat'])) ?></p>
            <p class="kpi-sub"><button type="button" class="linklike" data-goto-tab="tva">Voir la TVA payée →</button></p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Voir aussi</p>
            <p class="kpi-sub">
                <?php if (\App\Core\Permissions::isSystemAdmin()): ?>
                    <a href="<?= e(url('/admin/compta/inventaire')) ?>">Faire un inventaire →</a><br>
                <?php endif; ?>
                <a href="<?= e(url('/admin/compta/reappro')) ?>">Calculer le réappro →</a>
            </p>
        </div>
    </div>

    <div class="compta-grid">
        <section class="card surface glass">
            <h2 class="card-title">Comment ça marche</h2>
            <p>Le <a href="<?= e(url('/admin/compta/reappro')) ?>">réappro</a> calcule ce qu'il <strong>FAUT</strong> commander ; cette page trace ce qui a <strong>ÉTÉ</strong> commandé.</p>
            <p>Par défaut, chaque achat crée un <strong>nouveau lot de coût</strong> à ce prix<?php if (\App\Core\Permissions::isSystemAdmin()): ?> dans <a href="<?= e(url('/admin/compta/couts')) ?>">Coûts de revient</a><?php endif; ?> — décoche la case pour des prix inhabituels.</p>
            <p>Les achats alimentent le <strong>stock théorique</strong> visible<?php if (\App\Core\Permissions::isSystemAdmin()): ?> dans <a href="<?= e(url('/admin/compta/inventaire')) ?>">l'inventaire</a><?php endif; ?> : dernier comptage + achats − ventes.</p>
            <p>Une ligne peut aussi être <strong>« hors stock »</strong> (conso bureau, fournitures, essais, invités…) : coche la case — l'achat reste comptabilisé mais n'alimente ni le stock théorique ni le stock de référence.</p>
        </section>
        <section class="card surface glass">
            <h2 class="card-title">HT ou TTC ?</h2>
            <p><strong>Montants HT</strong> : les prix fournisseurs (Metro…) sont souvent HT — la TVA choisie est <em>ajoutée</em> pour obtenir le TTC.</p>
            <p><strong>Montants TTC</strong> : ticket de caisse — la TVA choisie est déjà <em>incluse</em> : le HT et la TVA sont déduits automatiquement (TTC ÷ (1 + taux)).</p>
            <p>Le taux par défaut est <strong>5,5 %</strong> (alimentation) ; choisis 20 % pour la plupart des autres achats.</p>
        </section>
    </div>
</div>

<!-- ==================== Onglet : Saisir des achats ==================== -->
<?php
// Liste de picking : produits vendus + clés réelles existantes (les
// mêmes que les suggestions anti-doublons), triées naturellement.
$pickerList = array_values(array_unique(array_merge($products, array_values($normKeys))));
usort($pickerList, 'strnatcasecmp');
?>
<div class="compta-tabpane" data-pane="saisie" hidden>
    <section class="card surface glass">
        <h2 class="card-title">Enregistrer des achats</h2>
        <p class="muted">Une course entière (Metro…) en une fois, même à 20 produits. Le plus rapide : <strong>colle la commande du fournisseur</strong> ci-dessous. Sinon clique les produits connus ou tape au clavier — un seul « Enregistrer » à la fin.</p>

        <form method="post" action="<?= e(url('/admin/compta/achats/save-bulk')) ?>" data-purchase-form>
            <?= csrf_field() ?>

            <div class="field-row">
                <div class="field">
                    <label for="purchased_at">Date</label>
                    <input type="date" id="purchased_at" name="purchased_at" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="field">
                    <label for="vat_rate">Taux de TVA (toutes les lignes)</label>
                    <select id="vat_rate" name="vat_rate">
                        <option value="20">TVA 20 %</option>
                        <option value="10">TVA 10 %</option>
                        <option value="5.5" selected>TVA 5,5 %</option>
                        <option value="2.1">TVA 2,1 %</option>
                        <option value="0">Sans TVA (0 %)</option>
                    </select>
                </div>
                <div class="field">
                    <label>Les montants saisis sont…</label>
                    <div class="chip-row" role="radiogroup" aria-label="Base des montants">
                        <label class="chip"><input type="radio" name="amount_basis" value="ht" checked><span>HT (TVA à ajouter)</span></label>
                        <label class="chip"><input type="radio" name="amount_basis" value="ttc"><span>TTC (TVA incluse)</span></label>
                    </div>
                    <p class="field-help">Prix Metro/fournisseur → HT. Ticket de caisse → TTC : le HT et la TVA sont déduits automatiquement.</p>
                </div>
                <div class="field">
                    <label for="supplier">Fournisseur <span class="muted">(optionnel, appliqué à toutes les lignes)</span></label>
                    <input type="text" id="supplier" name="supplier" placeholder="ex: Metro">
                </div>
            </div>

            <div class="field">
                <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer;">
                    <input type="checkbox" name="update_cost" value="1" checked>
                    Mettre à jour le coût de revient <span class="muted">(un lot par produit, en TTC)</span>
                </label>
                <p class="field-help">Décoche si ces prix sont inhabituels (promo, erreur, test…) pour ne pas fausser le calcul du bénéfice.</p>
            </div>

            <!-- ── 1) Coller la commande : le plus rapide pour ~20 lignes ── -->
            <div class="pa-paste">
                <p class="pa-paste-title">📋 Coller la commande entière <span class="muted">— copie l'e-mail fournisseur ou Excel, une ligne par produit</span></p>
                <textarea id="purchase-paste" rows="5" placeholder="Coca 33cl ; 24 ; 18,60&#10;Fanta Orange 33cl ; 24 ; 15,20&#10;Bonbons ; 10,45" spellcheck="false"></textarea>
                <p class="field-help">Format <code>Nom ; Qté ; Montant</code> (tabulations acceptées ; Qté et Montant optionnels).</p>
                <div class="form-actions">
                    <button type="button" class="btn btn-primary btn-sm" id="purchase-paste-apply">Importer les lignes →</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="purchase-paste-clear">Vider</button>
                    <span class="muted pa-paste-status" id="purchase-paste-status"></span>
                </div>
            </div>

            <!-- ── 2) Ajouter un produit connu : recherche + clic ── -->
            <div class="combobox pa-picker">
                <input type="text" id="pa-search" class="combobox-input" placeholder="🔍 Ou ajoute un produit connu… (tape, puis Entrée ou clic)"
                       autocomplete="off" aria-label="Rechercher un produit connu à ajouter">
                <ul class="combobox-list" id="pa-results" hidden></ul>
            </div>

            <!-- ── 3) La commande : lignes compactes ── -->
            <div class="pa-grid" id="purchases-grid"
                 data-dup-keys="<?= e(json_encode($normKeys)) ?>"
                 data-picker-keys="<?= e(json_encode($pickerList, JSON_UNESCAPED_UNICODE)) ?>">
                <div class="pa-head" aria-hidden="true">
                    <span>#</span>
                    <span>Produit</span>
                    <span>Qté</span>
                    <span>Montant total (€)</span>
                    <span>≈ / unité · stock</span>
                    <span></span>
                </div>
                <div id="purchases-lines"></div>
            </div>
            <datalist id="purchase-products">
                <?php foreach ($products as $p): ?>
                    <option value="<?= e($p) ?>"></option>
                <?php endforeach; ?>
            </datalist>

            <!-- Récap collant : compte, totaux et actions toujours visibles -->
            <div class="pa-recap" id="pa-recap">
                <div class="pa-recap-info">
                    <strong id="pa-count">0 ligne</strong>
                    <span class="pa-recap-total"><span id="purchases-total">0,000 €</span> <span class="muted" id="purchases-total-basis"></span></span>
                    <span class="muted" id="purchases-total-other"></span>
                </div>
                <div class="pa-recap-actions">
                    <button type="button" class="btn btn-ghost btn-sm" id="purchase-line-add">+ Ligne</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="purchase-line-add5">+ 5</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler</button>
                    <button type="submit" class="btn btn-primary" id="pa-submit">Enregistrer les achats</button>
                </div>
            </div>

            <p class="field-help">
                Au clavier : <strong>Produit → Entrée → Qté → Entrée → Montant → Entrée</strong> enchaîne les lignes (une ligne vide s'ajoute toute seule) ; <strong>Ctrl+Entrée</strong> enregistre.
                Choisis des noms existants (mêmes noms que dans les ventes) pour alimenter le bon stock théorique ; les lignes vides sont ignorées.
                Coche « hors stock » pour une ligne qui ne doit pas alimenter le stock (conso bureau, fournitures, essais…) : l'achat reste comptabilisé.
            </p>
        </form>
        <!-- Helpers purs (normalisation, collage, HT/TTC, doublons) :
             logique partagée et testée automatiquement
             (tests/js/compta-saisie.test.js via tests/Unit/PurchaseGridJsTest.php). -->
        <script src="<?= e(rootAssetVersioned('/assets/js/compta-saisie.js')) ?>"></script>
        <script>
        (function () {
            var H = window.ComptaSaisie;
            var grid = document.getElementById('purchases-grid');
            var tbody = document.getElementById('purchases-lines');
            var addBtn = document.getElementById('purchase-line-add');
            var add5Btn = document.getElementById('purchase-line-add5');
            var countEl = document.getElementById('pa-count');
            var totalEl = document.getElementById('purchases-total');
            var totalBasisEl = document.getElementById('purchases-total-basis');
            var totalOtherEl = document.getElementById('purchases-total-other');
            var rateEl = document.getElementById('vat_rate');
            var basisInputs = document.querySelectorAll('input[name="amount_basis"]');
            var form = document.querySelector('[data-purchase-form]');
            if (!H || !tbody || !addBtn || !grid || !form) return;

            var MAX_LINES = 60;
            var START_LINES = 5; // une commande complète tient d'un coup

            // Carte des clés existantes, normalisées côté PHP
            // (data-dup-keys) : clé normalisée -> clé réelle à préférer.
            var normKeys = {};
            try { normKeys = JSON.parse(grid.getAttribute('data-dup-keys') || '{}'); } catch (e) { normKeys = {}; }
            var pickerKeys = [];
            try { pickerKeys = JSON.parse(grid.getAttribute('data-picker-keys') || '[]'); } catch (e) { pickerKeys = []; }

            // Helpers purs partagés (fichier compta-saisie.js) : le DOM
            // reste ici, la logique est testée automatiquement.
            var normKey = H.normKey;
            var parseAmount = H.parseAmount;
            var fmt3 = H.fmt3;
            var parsePasteLine = H.parsePasteLine;

            function esc(s) {
                return String(s).replace(/[&<>"]/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
                });
            }

            function rows() {
                return Array.prototype.slice.call(tbody.querySelectorAll('.pa-row'));
            }

            function basis() {
                for (var i = 0; i < basisInputs.length; i++) {
                    if (basisInputs[i].checked) return basisInputs[i].value;
                }
                return 'ht';
            }

            function rate() {
                var r = parseFloat(rateEl.value);
                return isFinite(r) ? r : 0;
            }

            function updateRow(tr) {
                var a = parseAmount(tr.querySelector('[name="total_amount[]"]').value);
                var q = parseInt(tr.querySelector('[name="quantity[]"]').value, 10);
                var hint = tr.querySelector('.line-unit');
                var text = H.unitHint(a, q, rate(), basis());
                if (text === '') {
                    hint.hidden = true;
                    hint.textContent = '';
                    return;
                }
                hint.textContent = text;
                hint.hidden = false;
            }

            function updateTotals() {
                var sum = 0, filled = 0;
                Array.prototype.forEach.call(rows(), function (tr) {
                    var a = parseAmount(tr.querySelector('[name="total_amount[]"]').value);
                    if (tr.querySelector('[name="product_key[]"]').value.trim() !== '' || (isFinite(a) && a > 0)) filled++;
                    if (isFinite(a) && a > 0) sum += a;
                });
                var isTtc = basis() === 'ttc';
                var s = H.splitVat(sum, rate(), basis());
                countEl.textContent = filled + ' ligne' + (filled > 1 ? 's' : '');
                totalEl.textContent = fmt3(sum);
                totalBasisEl.textContent = isTtc ? 'TTC' : 'HT';
                totalOtherEl.textContent = sum <= 0 ? '' : (isTtc
                    ? 'dont ' + fmt3(s.ht) + ' HT · ' + fmt3(s.ttc - s.ht) + ' TVA'
                    : 'soit ' + fmt3(s.ttc) + ' TTC');
            }

            function refreshIdx() {
                Array.prototype.forEach.call(rows(), function (tr, i) {
                    tr.querySelector('.pa-idx').textContent = i + 1;
                });
            }

            // Doublons : suggestion d'une clé existante qui ressemble, et
            // détection de deux lignes identiques dans la même commande.
            function checkDup(tr) {
                var input = tr.querySelector('[name="product_key[]"]');
                var warn = tr.querySelector('.pa-warn');
                if (!input || !warn) return;
                var value = input.value.trim();
                var msg = '';

                var existing = normKeys[normKey(value)];
                if (existing && existing !== value) {
                    msg = '« ' + value + ' » ressemble à « ' + existing + ' » — préfère ce nom existant (autocomplétion).';
                }

                if (value !== '') {
                    var all = rows();
                    var names = all.map(function (row) {
                        return row.querySelector('[name="product_key[]"]').value;
                    });
                    var firstAt = H.findDuplicateLine(names, all.indexOf(tr));
                    if (firstAt !== -1) {
                        msg = '« ' + value + ' » est déjà en ligne ' + firstAt + ' — fusionne les quantités sur une seule ligne.';
                    }
                }

                warn.textContent = msg;
                warn.hidden = msg === '';
            }

            function refreshDups() {
                Array.prototype.forEach.call(rows(), checkDup);
            }

            function recalcAll() {
                Array.prototype.forEach.call(rows(), updateRow);
                updateTotals();
            }

            // Navigation clavier : Entrée = champ suivant sur la ligne,
            // puis ligne suivante (une nouvelle ligne est créée si on est
            // sur la dernière).
            function focusField(tr, name) {
                var el = tr.querySelector('[name="' + name + '"]');
                if (el) el.focus();
            }

            function nextRow(tr) {
                var all = rows();
                var i = all.indexOf(tr);
                if (i === -1) return null;
                if (i + 1 < all.length) return all[i + 1];
                if (all.length < MAX_LINES) return addLine(false);
                return null;
            }

            function setQty(tr, q) {
                var input = tr.querySelector('[name="quantity[]"]');
                input.value = String(Math.max(1, q));
                updateRow(tr);
            }

            function wireRow(tr) {
                var keyInput = tr.querySelector('[name="product_key[]"]');
                var qtyInput = tr.querySelector('[name="quantity[]"]');
                var amountInput = tr.querySelector('[name="total_amount[]"]');

                keyInput.addEventListener('blur', function () { checkDup(tr); });
                keyInput.addEventListener('change', function () { checkDup(tr); });
                keyInput.addEventListener('input', function () { updateTotals(); });
                keyInput.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); focusField(tr, 'quantity[]'); }
                });

                // Sélection auto au focus : la valeur « 1 » s'écrase direct.
                qtyInput.addEventListener('focus', function () { qtyInput.select(); });
                qtyInput.addEventListener('input', function () { updateRow(tr); });
                qtyInput.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); focusField(tr, 'total_amount[]'); }
                });

                Array.prototype.forEach.call(tr.querySelectorAll('.pa-step'), function (btn) {
                    btn.addEventListener('click', function () {
                        var q = parseInt(qtyInput.value, 10) || 1;
                        setQty(tr, q + (btn.getAttribute('data-step') === '1' ? 1 : -1));
                    });
                });

                amountInput.addEventListener('focus', function () { amountInput.select(); });
                amountInput.addEventListener('input', function () {
                    updateRow(tr); updateTotals();
                    // Dernière ligne remplie → une nouvelle ligne apparaît :
                    // on enchaîne les produits sans jamais cliquer.
                    if (amountInput.value !== '' && rows()[rows().length - 1] === tr && rows().length < MAX_LINES) {
                        addLine(false);
                        refreshIdx();
                    }
                });
                amountInput.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        var next = nextRow(tr);
                        if (next) focusField(next, 'product_key[]');
                    }
                });

                tr.querySelector('.pa-remove').addEventListener('click', function () {
                    var all = rows();
                    if (all.length <= 1) {
                        // Dernière ligne : on la vide au lieu de la retirer.
                        tr.querySelectorAll('input[type="text"], input[type="number"]').forEach(function (el) { el.value = el.name === 'quantity[]' ? '1' : ''; });
                        tr.querySelector('.line-no-stock').checked = false;
                        updateRow(tr); updateTotals(); refreshDups();
                        return;
                    }
                    tr.remove(); updateTotals(); refreshIdx(); refreshDups();
                });
                updateRow(tr);
            }

            function addLine(focus) {
                var tr = document.createElement('div');
                tr.className = 'pa-row';
                tr.innerHTML =
                    '<span class="pa-idx">0</span>' +
                    '<div class="pa-prod"><input type="text" name="product_key[]" list="purchase-products" placeholder="ex: Coca 33cl" autocomplete="off"><div class="pa-warn" hidden></div></div>' +
                    '<div class="pa-qty">' +
                        '<button type="button" class="pa-step" data-step="-1" aria-label="Moins un">−</button>' +
                        '<input type="number" name="quantity[]" value="1" min="1" step="1" inputmode="numeric">' +
                        '<button type="button" class="pa-step" data-step="1" aria-label="Plus un">+</button>' +
                    '</div>' +
                    '<div class="pa-amount"><input type="text" name="total_amount[]" placeholder="ex: 18,60" inputmode="decimal"></div>' +
                    '<div class="pa-meta">' +
                        '<span class="muted line-unit" hidden></span>' +
                        '<label class="pa-nostock" title="Cochée : n\'alimente pas le stock théorique (conso bureau, essais…)"><input type="checkbox" class="line-no-stock" name="no_stock[]" value="1"> hors stock</label>' +
                    '</div>' +
                    '<button type="button" class="pa-remove" aria-label="Retirer la ligne" title="Retirer la ligne">×</button>';
                tbody.appendChild(tr);
                wireRow(tr);
                refreshIdx();
                if (focus) tr.querySelector('[name="product_key[]"]').focus();

                return tr;
            }

            function addLines(n) {
                for (var i = 0; i < n && rows().length < MAX_LINES; i++) addLine(false);
                updateTotals();
            }

            function firstEmptyRow() {
                var all = rows();
                for (var i = 0; i < all.length; i++) {
                    if (all[i].querySelector('[name="product_key[]"]').value.trim() === '') return all[i];
                }
                return null;
            }

            // Remplit une ligne (picker ou import) : nom, qté, montant.
            function fillRow(tr, name, qty, amount) {
                tr.querySelector('[name="product_key[]"]').value = name;
                tr.querySelector('[name="quantity[]"]').value = String(qty || 1);
                tr.querySelector('[name="total_amount[]"]').value = amount || '';
                updateRow(tr);
                checkDup(tr);
                updateTotals();
            }

            // ── Sélecteur de produits connus : tape, flèches, Entrée ──
            var searchInput = document.getElementById('pa-search');
            var resultList = document.getElementById('pa-results');
            var activeAt = -1;
            if (searchInput && resultList) {
                function inOrderKeys() {
                    var set = {};
                    Array.prototype.forEach.call(rows(), function (tr) {
                        var v = tr.querySelector('[name="product_key[]"]').value.trim();
                        if (v !== '') set[normKey(v)] = true;
                    });
                    return set;
                }

                function renderResults() {
                    var q = normKey(searchInput.value);
                    var inOrder = inOrderKeys();
                    var matches = [];
                    Array.prototype.forEach.call(pickerKeys, function (name) {
                        if (q === '' || normKey(name).indexOf(q) !== -1) matches.push(name);
                    });
                    var html = '';
                    var shown = matches.slice(0, 8);
                    Array.prototype.forEach.call(shown, function (name) {
                        html += '<li class="combobox-option" data-name="' + esc(name) + '">' +
                            (inOrder[normKey(name)] ? '✓ ' : '') + esc(name) +
                            (inOrder[normKey(name)] ? ' <small class="muted">déjà dans la commande</small>' : '') +
                            '</li>';
                    });
                    if (searchInput.value.trim() !== '' && normKey(searchInput.value) !== '' && !inOrder[normKey(searchInput.value)]) {
                        var known = matches.some(function (n) { return normKey(n) === normKey(searchInput.value); });
                        if (!known) {
                            html += '<li class="combobox-option combobox-new" data-name="' + esc(searchInput.value.trim()) + '">+ Nouveau produit : « ' + esc(searchInput.value.trim()) + ' »</li>';
                        }
                    }
                    resultList.innerHTML = html;
                    activeAt = shown.length > 0 ? 0 : (resultList.children.length > 0 ? 0 : -1);
                    highlight();
                    resultList.hidden = resultList.children.length === 0;
                }

                function highlight() {
                    Array.prototype.forEach.call(resultList.children, function (li, i) {
                        li.classList.toggle('is-active', i === activeAt);
                    });
                }

                function applyAt(i) {
                    var li = resultList.children[i];
                    if (!li) return;
                    var name = li.getAttribute('data-name');
                    var tr = firstEmptyRow() || (rows().length < MAX_LINES ? addLine(false) : null);
                    if (!tr) return;
                    fillRow(tr, name, 1, '');
                    searchInput.value = '';
                    resultList.hidden = true;
                    focusField(tr, 'quantity[]');
                }

                searchInput.addEventListener('input', renderResults);
                searchInput.addEventListener('focus', renderResults);
                searchInput.addEventListener('keydown', function (ev) {
                    if (ev.key === 'ArrowDown') { ev.preventDefault(); if (activeAt < resultList.children.length - 1) activeAt++; highlight(); }
                    else if (ev.key === 'ArrowUp') { ev.preventDefault(); if (activeAt > 0) activeAt--; highlight(); }
                    else if (ev.key === 'Enter') { ev.preventDefault(); applyAt(activeAt); }
                    else if (ev.key === 'Escape') { resultList.hidden = true; }
                });
                searchInput.addEventListener('blur', function () {
                    window.setTimeout(function () { resultList.hidden = true; }, 150);
                });
                resultList.addEventListener('mousedown', function (ev) {
                    var li = ev.target.closest('.combobox-option');
                    if (!li) return;
                    ev.preventDefault();
                    applyAt(Array.prototype.indexOf.call(resultList.children, li));
                });
            }

            // ── Coller la commande : comptage en direct, puis import ──
            // (analyse déléguée au helper partagé H.parsePasteLine)

            var pasteArea = document.getElementById('purchase-paste');
            var pasteApply = document.getElementById('purchase-paste-apply');
            var pasteStatus = document.getElementById('purchase-paste-status');
            if (pasteArea && pasteApply) {
                pasteArea.addEventListener('input', function () {
                    var n = 0;
                    Array.prototype.forEach.call(pasteArea.value.split(/\r?\n/), function (line) {
                        if (String(line).trim() !== '' && parsePasteLine(String(line).trim())) n++;
                    });
                    pasteStatus.textContent = pasteArea.value.trim() === ''
                        ? ''
                        : n + ' ligne' + (n > 1 ? 's' : '') + ' détectée' + (n > 1 ? 's' : '') + ' — clique « Importer ».';
                });

                pasteApply.addEventListener('click', function () {
                    var count = 0;
                    Array.prototype.forEach.call(pasteArea.value.split(/\r?\n/), function (line) {
                        if (String(line).trim() === '') return;
                        var entry = parsePasteLine(String(line).trim());
                        if (!entry) return;

                        // Remplit d'abord les lignes vides existantes.
                        var tr = firstEmptyRow();
                        if (!tr && rows().length < MAX_LINES) tr = addLine(false);
                        if (!tr) return;

                        fillRow(tr, entry.name, entry.qty, entry.amount);
                        count++;
                    });
                    refreshIdx(); refreshDups(); updateTotals();
                    pasteStatus.textContent = count > 0
                        ? count + ' ligne' + (count > 1 ? 's' : '') + ' importée' + (count > 1 ? 's' : '') + ' — vérifie puis enregistre.'
                        : 'Rien à importer : une ligne par produit, « Nom ; Qté ; Montant ».';
                    if (count > 0) pasteArea.value = '';
                });
                var pasteClear = document.getElementById('purchase-paste-clear');
                if (pasteClear) {
                    pasteClear.addEventListener('click', function () {
                        pasteArea.value = '';
                        pasteStatus.textContent = '';
                        pasteArea.focus();
                    });
                }
            }

            // Grille prête : quelques lignes vides pour saisir direct.
            addLines(START_LINES);
            addBtn.addEventListener('click', function () { addLine(true); });
            if (add5Btn) add5Btn.addEventListener('click', function () { addLines(5); });
            Array.prototype.forEach.call(basisInputs, function (input) {
                input.addEventListener('change', recalcAll);
            });
            rateEl.addEventListener('change', recalcAll);
            updateTotals();

            // Ctrl+Entrée : enregistrer sans aller chercher le bouton
            // tout en bas d'une longue commande.
            form.addEventListener('keydown', function (ev) {
                if ((ev.ctrlKey || ev.metaKey) && ev.key === 'Enter') {
                    ev.preventDefault();
                    var submit = document.getElementById('pa-submit');
                    if (submit) submit.click();
                }
            });

            // Cases non cochées non postées + lignes supprimées : on
            // renumérote no_stock[i] dans l'ordre des lignes juste avant
            // l'envoi, pour rester aligné avec product_key[i].
            form.addEventListener('submit', function () {
                var all = rows();
                Array.prototype.forEach.call(all, function (tr, i) {
                    var cb = tr.querySelector('.line-no-stock');
                    if (cb) cb.name = 'no_stock[' + i + ']';
                });
            });
        })();
        </script>
    </section>
</div>

<!-- ==================== Onglet : TVA payée ==================== -->
<div class="compta-tabpane" data-pane="tva" hidden>
    <div class="compta-kpis">
        <div class="card surface glass kpi">
            <p class="kpi-label">TVA payée (achats)</p>
            <p class="kpi-value"><?= e(formatPrice($stats['vat'])) ?></p>
            <p class="kpi-sub">sur la période sélectionnée</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Achats TTC</p>
            <p class="kpi-value"><?= e(formatPrice($stats['ttc'])) ?></p>
            <p class="kpi-sub">dont <?= e(formatPrice($stats['ht'])) ?> HT</p>
        </div>
    </div>

    <div class="card surface glass table-wrap">
        <h2 class="card-title">TVA payée par taux</h2>
        <p class="muted">Décomposition HT / TVA / TTC des achats de la période, groupés par taux de TVA. Les lignes « déjà TTC » (saisies historiques sans décomposition) sont listées à part.</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Taux</th>
                    <th class="th-num">Lignes</th>
                    <th class="th-num">Total HT</th>
                    <th class="th-num">TVA</th>
                    <th class="th-num">Total TTC</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vatRows as $v): ?>
                    <tr>
                        <td>
                            <?php if ($v['rate'] === null): ?>
                                <span class="badge badge-muted">Déjà TTC (sans décomposition)</span>
                            <?php else: ?>
                                <span class="badge badge-warning"><?= e(formatFrenchPercent((float) $v['rate'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $v['lines'] ?></td>
                        <td class="num"><?= e(formatPrice($v['ht'], 3)) ?></td>
                        <td class="num"><strong><?= e(formatPrice($v['vat'], 3)) ?></strong></td>
                        <td class="num"><strong><?= e(formatPrice($v['ttc'], 3)) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($vatRows === []): ?>
                    <tr><td colspan="5" class="muted">Aucun achat sur la période sélectionnée.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($vatRows !== []): ?>
                <tfoot>
                    <tr>
                        <th>Total période</th>
                        <th class="num"><?= (int) $stats['lines'] ?></th>
                        <th class="num"><?= e(formatPrice($stats['ht'], 3)) ?></th>
                        <th class="num"><?= e(formatPrice($stats['vat'], 3)) ?></th>
                        <th class="num"><?= e(formatPrice($stats['ttc'], 3)) ?></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ==================== Onglet : Journal ==================== -->
<div class="compta-tabpane" data-pane="journal" hidden>
    <div class="card surface glass table-wrap">
        <h2 class="card-title">Derniers achats</h2>
        <p class="muted">Achats de la période sélectionnée (200 lignes max).</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Produit</th>
                    <th class="th-num">Qté</th>
                    <th class="th-num">Coût unit. HT</th>
                    <th class="th-num">Total HT</th>
                    <th class="th-num">Total TTC</th>
                    <th>TVA</th>
                    <th>Fournisseur</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php $hasVat = $r['vat_rate'] !== null; ?>
                    <tr>
                        <td><?= e(formatDate((string) $r['purchased_at'])) ?></td>
                        <td><strong><?= e((string) $r['product_key']) ?></strong><?php if (!empty($r['no_stock'])): ?> <small class="muted">· hors stock</small><?php endif; ?></td>
                        <td class="num"><?= (int) $r['quantity'] ?></td>
                        <td class="num"><?= e(formatPrice((float) $r['unit_cost'], 3)) ?></td>
                        <td class="num">
                            <?php if (($r['total_ht'] ?? null) !== null): ?>
                                <?= e(formatPrice((float) $r['total_ht'], 3)) ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><strong><?= e(formatPrice((float) $r['total_ttc'], 3)) ?></strong></td>
                        <td>
                            <?php if ($hasVat): ?>
                                <span class="badge badge-warning"><?= e(formatFrenchPercent((float) $r['vat_rate'])) ?></span>
                            <?php else: ?>
                                <span class="badge badge-muted">Déjà TTC</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($r['supplier'] ?? '—')) ?></td>
                        <td class="row-actions">
                            <form method="post" action="<?= e(url('/admin/compta/achats/' . rawurlencode((string) $r['id']) . '/delete')) ?>"
                                  data-confirm="Supprimer cet achat ?" data-preserve-scroll>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="muted">Aucun achat sur la période sélectionnée.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($rows !== []): ?>
                <tfoot>
                    <tr>
                        <th colspan="2">Total période</th>
                        <th class="num"><?= (int) $stats['qty'] ?></th>
                        <th></th>
                        <th class="num"><?= e(formatPrice($stats['ht'], 3)) ?></th>
                        <th class="num"><?= e(formatPrice($stats['ttc'], 3)) ?></th>
                        <th class="num muted">dont TVA <?= e(formatPrice($stats['vat'], 3)) ?></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<script>
// Onglets compta : bascule côté client, mémorisée dans l'URL (?tab=…).
// Ouvert aussi aux liens internes (data-goto-tab).
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
    Array.prototype.forEach.call(document.querySelectorAll('[data-goto-tab]'), function (el) {
        el.addEventListener('click', function () { activate(el.getAttribute('data-goto-tab'), true); });
    });

    var initial = new URLSearchParams(window.location.search).get('tab');
    if (initial) activate(initial, false);
})();
</script>
