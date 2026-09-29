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
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Achats & stock</h1>
        <p class="muted">Note ici <strong>ce que tu commandes vraiment</strong>. Ces achats alimentent le stock théorique de l'inventaire : dernier comptage + achats − ventes.</p>
    </div>
</div>

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
<div class="compta-tabpane" data-pane="saisie" hidden>
    <section class="card surface glass">
        <h2 class="card-title">Enregistrer des achats</h2>
        <p class="muted">Une ligne par produit, un seul « Enregistrer » à la fin — une course entière (Metro…) en une fois. Champs communs en tête : date, TVA, fournisseur.</p>

        <form method="post" action="<?= e(url('/admin/compta/achats/save-bulk')) ?>">
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

            <table class="table" id="purchases-grid" data-dup-keys="<?= e(json_encode($normKeys)) ?>">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th style="width:90px;">Qté</th>
                        <th style="width:140px;">Montant total (€)</th>
                        <th style="width:220px;">≈ / unité</th>
                        <th style="width:80px;" title="Cochée : l'achat est comptabilisé mais n'entre pas en stock">Stock</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody id="purchases-lines">
                    <tr class="purchase-line">
                        <td><input type="text" name="product_key[]" list="purchase-products" placeholder="ex: Coca 33cl" autocomplete="off" style="width:100%;"><div class="dup-warning" hidden style="margin-top:4px;padding:4px 8px;border-radius:6px;background:#fff3cd;border:1px solid #ffeeba;color:#7a5b00;font-size:0.78rem;"></div></td>
                        <td><input type="number" name="quantity[]" value="1" min="1" step="1" style="width:100%;"></td>
                        <td><input type="text" name="total_amount[]" placeholder="ex: 18,60" inputmode="decimal" style="width:100%;"></td>
                        <td class="muted line-unit" hidden></td>
                        <td class="nostock-cell"><label style="display:flex;align-items:center;gap:4px;font-weight:400;cursor:pointer;white-space:nowrap;" title="Cochée : n'alimente pas le stock théorique (conso bureau, essais…)"><input type="checkbox" class="line-no-stock" name="no_stock[]" value="1"> hors stock</label></td>
                        <td><button type="button" class="btn btn-ghost btn-sm line-remove" aria-label="Supprimer la ligne">Retirer</button></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" class="num">Total des montants</th>
                        <th class="num"><span id="purchases-total">0,000 €</span> <span class="muted" id="purchases-total-basis"></span></th>
                        <th class="num muted" id="purchases-total-other"></th>
                        <th colspan="2"></th>
                    </tr>
                </tfoot>
            </table>
            <datalist id="purchase-products">
                <?php foreach ($products as $p): ?>
                    <option value="<?= e($p) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <p class="field-help">Choisis des noms existants (mêmes noms que dans les ventes) pour alimenter le bon stock théorique. Les lignes vides sont ignorées. Coche « hors stock » pour une ligne qui ne doit pas alimenter le stock (conso bureau, fournitures, essais…) : l'achat reste comptabilisé.</p>

            <div class="form-actions">
                <button type="button" class="btn btn-ghost" id="purchase-line-add">+ Ajouter une ligne</button>
                <button type="submit" class="btn btn-primary">Enregistrer les achats</button>
                <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler</button>
            </div>
        </form>
        <script>
        (function () {
            var tbody = document.getElementById('purchases-lines');
            var addBtn = document.getElementById('purchase-line-add');
            var totalEl = document.getElementById('purchases-total');
            var totalBasisEl = document.getElementById('purchases-total-basis');
            var totalOtherEl = document.getElementById('purchases-total-other');
            var rateEl = document.getElementById('vat_rate');
            var basisInputs = document.querySelectorAll('input[name="amount_basis"]');
            if (!tbody || !addBtn) return;

            // Carte des clés existantes, normalisées côté PHP
            // (data-dup-keys) : clé normalisée -> clé réelle à préférer.
            var normKeys = {};
            try { normKeys = JSON.parse(document.getElementById('purchases-grid').getAttribute('data-dup-keys') || '{}'); } catch (e) { normKeys = {}; }

            // Règle de normalisation dupliquée en JS (pas de PHP côté
            // client) : identique à StockPublic::normalizeKey — minuscules,
            // sans accents, séparateurs et espaces supprimés.
            function normKey(v) {
                return String(v || '')
                    .toLowerCase()
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .replace(/[_\-\.()\[\]\{\},;:\/]+/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .replace(/ /g, '');
            }

            function checkDup(tr) {
                var input = tr.querySelector('[name="product_key[]"]');
                var warn = tr.querySelector('.dup-warning');
                if (!input || !warn) return;
                var existing = normKeys[normKey(input.value)];
                if (existing && existing !== input.value) {
                    warn.textContent = '« ' + input.value + ' » ressemble à la clé existante « ' + existing + ' » — préfère-la (autocomplétion) pour éviter un doublon.';
                    warn.hidden = false;
                } else {
                    warn.hidden = true;
                    warn.textContent = '';
                }
            }

            function parseAmount(v) {
                return parseFloat(String(v).replace(/\s/g, '').replace(',', '.'));
            }

            function fmt3(n) { return n.toFixed(3).replace('.', ',') + ' €'; }

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

            // Décomposition HT/TTC d'un montant saisi, selon la base et le
            // taux choisis. Recalculée à chaque changement (jamais figée).
            function split(amount) {
                var r = rate();
                if (basis() === 'ttc') {
                    var ht = r > 0 ? amount / (1 + r / 100) : amount;
                    return { ht: ht, ttc: amount };
                }
                var ttc = r > 0 ? amount * (1 + r / 100) : amount;
                return { ht: amount, ttc: ttc };
            }

            function updateRow(tr) {
                var a = parseAmount(tr.querySelector('[name="total_amount[]"]').value);
                var q = parseInt(tr.querySelector('[name="quantity[]"]').value, 10);
                var hint = tr.querySelector('.line-unit');
                if (!isFinite(a) || a <= 0 || !q || q < 1) {
                    hint.hidden = true;
                    hint.textContent = '';
                    return;
                }
                var s = split(a);
                hint.textContent = '≈ ' + (s.ht / q).toFixed(3).replace('.', ',') + ' € HT · ' + (s.ttc / q).toFixed(3).replace('.', ',') + ' € TTC / unité';
                hint.hidden = false;
            }

            function updateTotals() {
                var sum = 0;
                Array.prototype.forEach.call(tbody.querySelectorAll('tr.purchase-line'), function (tr) {
                    var a = parseAmount(tr.querySelector('[name="total_amount[]"]').value);
                    if (isFinite(a) && a > 0) sum += a;
                });
                var isTtc = basis() === 'ttc';
                var s = split(sum);
                totalEl.textContent = fmt3(sum);
                totalBasisEl.textContent = isTtc ? 'TTC' : 'HT';
                totalOtherEl.textContent = isTtc ? 'dont ' + fmt3(s.ht) + ' HT · ' + fmt3(s.ttc - s.ht) + ' TVA' : 'soit ' + fmt3(s.ttc) + ' TTC';
            }

            function recalcAll() {
                Array.prototype.forEach.call(tbody.querySelectorAll('tr.purchase-line'), updateRow);
                updateTotals();
            }

            function wireRow(tr) {
                var keyInput = tr.querySelector('[name="product_key[]"]');
                keyInput.addEventListener('blur', function () { checkDup(tr); });
                keyInput.addEventListener('change', function () { checkDup(tr); });
                tr.querySelector('[name="total_amount[]"]').addEventListener('input', function () {
                    updateRow(tr); updateTotals();
                });
                tr.querySelector('[name="quantity[]"]').addEventListener('input', function () {
                    updateRow(tr);
                });
                tr.querySelector('.line-remove').addEventListener('click', function () {
                    tr.remove(); updateTotals();
                });
                updateRow(tr);
            }

            function addLine(focus) {
                var tr = document.createElement('tr');
                tr.className = 'purchase-line';
                tr.innerHTML =
                    '<td><input type="text" name="product_key[]" list="purchase-products" placeholder="ex: Coca 33cl" autocomplete="off" style="width:100%;"><div class="dup-warning" hidden style="margin-top:4px;padding:4px 8px;border-radius:6px;background:#fff3cd;border:1px solid #ffeeba;color:#7a5b00;font-size:0.78rem;"></div></td>' +
                    '<td><input type="number" name="quantity[]" value="1" min="1" step="1" style="width:100%;"></td>' +
                    '<td><input type="text" name="total_amount[]" placeholder="ex: 18,60" inputmode="decimal" style="width:100%;"></td>' +
                    '<td class="muted line-unit" hidden></td>' +
                    '<td class="nostock-cell"><label style="display:flex;align-items:center;gap:4px;font-weight:400;cursor:pointer;white-space:nowrap;" title="Cochée : n\'alimente pas le stock théorique (conso bureau, essais…)"><input type="checkbox" class="line-no-stock" name="no_stock[]" value="1"> hors stock</label></td>' +
                    '<td><button type="button" class="btn btn-ghost btn-sm line-remove" aria-label="Supprimer la ligne">Retirer</button></td>';
                tbody.appendChild(tr);
                wireRow(tr);
                if (focus) tr.querySelector('[name="product_key[]"]').focus();
            }

            Array.prototype.forEach.call(tbody.querySelectorAll('tr.purchase-line'), wireRow);
            addBtn.addEventListener('click', function () { addLine(true); });
            Array.prototype.forEach.call(basisInputs, function (input) {
                input.addEventListener('change', recalcAll);
            });
            rateEl.addEventListener('change', recalcAll);
            updateTotals();

            // Cases non cochées non postées + lignes supprimées : on
            // renumérote no_stock[i] dans l'ordre des lignes juste avant
            // l'envoi, pour rester aligné avec product_key[i].
            var form = tbody.closest('form');
            form.addEventListener('submit', function () {
                var rows = tbody.querySelectorAll('tr.purchase-line');
                Array.prototype.forEach.call(rows, function (tr, i) {
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
