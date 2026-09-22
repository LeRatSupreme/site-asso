<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $user
 * @var list<array<string,mixed>> $rows
 * @var list<string>              $products
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string>      $periodOptions
 * @var float                     $total
 * @var array{ht:float,ttc:float,vat:float} $sums
 * @var int                       $count
 * @var int                       $qtyTotal
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

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Total des achats</p>
        <p class="kpi-value"><?= e(formatPrice($total)) ?></p>
        <p class="kpi-sub"><?= (int) $count ?> ligne<?= $count > 1 ? 's' : '' ?> · sur la période</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Quantité reçue</p>
        <p class="kpi-value"><?= (int) $qtyTotal ?></p>
        <p class="kpi-sub">unités entrées en stock</p>
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
                <label for="vat_rate">TVA (toutes les lignes)</label>
                <select id="vat_rate" name="vat_rate">
                    <option value="20" selected>Montants HT + TVA 20 %</option>
                    <option value="10">Montants HT + TVA 10 %</option>
                    <option value="5.5">Montants HT + TVA 5,5 %</option>
                    <option value="2.1">Montants HT + TVA 2,1 %</option>
                    <option value="0">Montants HT sans TVA</option>
                    <option value="">Montants déjà TTC</option>
                </select>
                <p class="field-help">Les prix Metro/fournisseurs sont souvent HT.</p>
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
                    <th style="width:150px;">≈ / unité</th>
                    <th style="width:50px;"></th>
                </tr>
            </thead>
            <tbody id="purchases-lines">
                <tr class="purchase-line">
                    <td><input type="text" name="product_key[]" list="purchase-products" placeholder="ex: Coca 33cl" autocomplete="off" style="width:100%;"><div class="dup-warning" hidden style="margin-top:4px;padding:4px 8px;border-radius:6px;background:#fff3cd;border:1px solid #ffeeba;color:#7a5b00;font-size:0.78rem;"></div></td>
                    <td><input type="number" name="quantity[]" value="1" min="1" step="1" style="width:100%;"></td>
                    <td><input type="text" name="total_amount[]" placeholder="ex: 18,60" inputmode="decimal" style="width:100%;"></td>
                    <td class="muted line-unit" hidden></td>
                    <td><button type="button" class="btn btn-ghost btn-sm line-remove" aria-label="Supprimer la ligne">✕</button></td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2" class="num">Total des montants</th>
                    <th class="num" id="purchases-total">0,000 €</th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        </table>
        <datalist id="purchase-products">
            <?php foreach ($products as $p): ?>
                <option value="<?= e($p) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p class="field-help">Choisis des noms existants (mêmes noms que dans les ventes) pour alimenter le bon stock théorique. Les lignes vides sont ignorées.</p>

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
                    warn.textContent = '⚠️ « ' + input.value + ' » ressemble à la clé existante « ' + existing + ' » — préfère-la (autocomplétion) pour éviter un doublon.';
                    warn.hidden = false;
                } else {
                    warn.hidden = true;
                    warn.textContent = '';
                }
            }

            function parseAmount(v) {
                return parseFloat(String(v).replace(/\s/g, '').replace(',', '.'));
            }

            function updateRow(tr) {
                var a = parseAmount(tr.querySelector('[name="total_amount[]"]').value);
                var q = parseInt(tr.querySelector('[name="quantity[]"]').value, 10);
                var hint = tr.querySelector('.line-unit');
                if (!isFinite(a) || a <= 0 || !q || q < 1) {
                    hint.hidden = true;
                    hint.textContent = '';
                } else {
                    hint.textContent = '≈ ' + (a / q).toFixed(3).replace('.', ',') + ' € / unité';
                    hint.hidden = false;
                }
            }

            function updateTotal() {
                var sum = 0;
                Array.prototype.forEach.call(tbody.querySelectorAll('tr.purchase-line'), function (tr) {
                    var a = parseAmount(tr.querySelector('[name="total_amount[]"]').value);
                    if (isFinite(a) && a > 0) sum += a;
                });
                totalEl.textContent = sum.toFixed(3).replace('.', ',') + ' €';
            }

            function wireRow(tr) {
                var keyInput = tr.querySelector('[name="product_key[]"]');
                keyInput.addEventListener('blur', function () { checkDup(tr); });
                keyInput.addEventListener('change', function () { checkDup(tr); });
                tr.querySelector('[name="total_amount[]"]').addEventListener('input', function () {
                    updateRow(tr); updateTotal();
                });
                tr.querySelector('[name="quantity[]"]').addEventListener('input', function () {
                    updateRow(tr);
                });
                tr.querySelector('.line-remove').addEventListener('click', function () {
                    tr.remove(); updateTotal();
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
                    '<td><button type="button" class="btn btn-ghost btn-sm line-remove" aria-label="Supprimer la ligne">✕</button></td>';
                tbody.appendChild(tr);
                wireRow(tr);
                if (focus) tr.querySelector('[name="product_key[]"]').focus();
            }

            Array.prototype.forEach.call(tbody.querySelectorAll('tr.purchase-line'), wireRow);
            addBtn.addEventListener('click', function () { addLine(true); });
            updateTotal();
        })();
    </script>
</section>

<div class="compta-grid" style="margin-top:24px;">
    <section class="card surface glass">
        <h2 class="card-title">Comment ça marche</h2>
        <p>📌 Le <a href="<?= e(url('/admin/compta/reappro')) ?>">réappro</a> calcule ce qu'il <strong>FAUT</strong> commander ; cette page trace ce qui a <strong>ÉTÉ</strong> commandé.</p>
        <p>📌 Par défaut, chaque achat crée un <strong>nouveau lot de coût</strong> à ce prix<?php if (\App\Core\Permissions::isSystemAdmin()): ?> dans <a href="<?= e(url('/admin/compta/couts')) ?>">Coûts de revient</a><?php endif; ?> — décoche la case pour des prix inhabituels.</p>
        <p>📌 Les achats alimentent le <strong>stock théorique</strong> visible<?php if (\App\Core\Permissions::isSystemAdmin()): ?> dans <a href="<?= e(url('/admin/compta/inventaire')) ?>">l'inventaire</a><?php endif; ?> : dernier comptage + achats − ventes.</p>
    </section>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Derniers achats</h2>
    <p class="muted">Achats de la période sélectionnée (200 lignes max).</p>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Produit</th>
                <th class="th-num">Qté</th>
                <th class="th-num">Coût unit.</th>
                <th class="th-num">Total HT</th>
                <th class="th-num">Total TTC</th>
                <th>Fournisseur</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $hasVat = $r['vat_rate'] !== null; ?>
                <tr>
                    <td><?= e(formatDate((string) $r['purchased_at'])) ?></td>
                    <td><strong><?= e((string) $r['product_key']) ?></strong></td>
                    <td class="num"><?= (int) $r['quantity'] ?></td>
                    <td class="num">
                        <?= e(formatPrice((float) $r['unit_cost'], 3)) ?>
                        <?php if ($hasVat): ?><small class="muted">HT</small><?php endif; ?>
                    </td>
                    <td class="num">
                        <?php if ($hasVat && ($r['total_ht'] ?? null) !== null): ?>
                            <?= e(formatPrice((float) $r['total_ht'], 3)) ?>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><strong><?= e(formatPrice((float) $r['total_ttc'], 3)) ?></strong></td>
                    <td><?= e((string) ($r['supplier'] ?? '—')) ?></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/compta/achats/' . rawurlencode((string) $r['id']) . '/delete')) ?>"
                              data-confirm="Supprimer cet achat ?" data-preserve-scroll>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="muted">Aucun achat sur la période sélectionnée.</td></tr>
            <?php endif; ?>
        </tbody>
        <?php if ($rows !== []): ?>
            <tfoot>
                <tr>
                    <th colspan="2">Total période</th>
                    <th class="num"><?= (int) $qtyTotal ?></th>
                    <th></th>
                    <th class="num"><?= e(formatPrice($sums['ht'], 3)) ?></th>
                    <th class="num"><?= e(formatPrice($sums['ttc'], 3)) ?></th>
                    <th class="num muted">dont TVA <?= e(formatPrice($sums['vat'], 3)) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>
</div>

