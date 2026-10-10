<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 * @var list<array<string,mixed>> $expenses
 * @var array{ttc:float,ht:float,count:int} $agg
 * @var list<array{category:string,ttc:float,ht:float,vat:float,count:int}> $byCategory
 * @var array{ttc:float,ht:float,vat:float,with_vat:int,without_vat:int} $vatStats
 */

// Libellés français des catégories de dépenses.
$categoryLabels = [
    'MATIERE'   => 'Matière (cafétéria)',
    'MATERIEL'  => 'Matériel',
    'EVENEMENT' => 'Événements',
    'FRAIS'     => 'Frais (bancaires, abonnements)',
    'DIVERS'    => 'Divers',
];

// Part matière (catégorie MATIERE) sur la période.
$matiereTtc = 0.0;
foreach ($byCategory as $c) {
    if ($c['category'] === 'MATIERE') {
        $matiereTtc = (float) $c['ttc'];
        break;
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

<nav class="compta-tabs" data-compta-tabs aria-label="Sections Dépenses">
    <button type="button" class="compta-tab is-active" data-tab="total">Total des dépenses</button>
    <button type="button" class="compta-tab" data-tab="saisie">Saisir une dépense</button>
    <button type="button" class="compta-tab" data-tab="tva">TVA payée</button>
    <button type="button" class="compta-tab" data-tab="journal">Journal</button>
</nav>

<!-- ==================== Onglet : Total des dépenses ==================== -->
<div class="compta-tabpane is-active" data-pane="total">
    <div class="compta-kpis">
        <div class="card surface glass kpi">
            <p class="kpi-label">Dépenses TTC</p>
            <p class="kpi-value"><?= e(formatPrice($agg['ttc'])) ?></p>
            <p class="kpi-sub"><?= (int) $agg['count'] ?> écriture<?= (int) $agg['count'] > 1 ? 's' : '' ?> sur la période sélectionnée</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Dépenses HT</p>
            <p class="kpi-value"><?= e(formatPrice($agg['ht'])) ?></p>
            <p class="kpi-sub">hors TVA</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Dont TVA</p>
            <p class="kpi-value"><?= e(formatPrice($vatStats['vat'])) ?></p>
            <p class="kpi-sub"><button type="button" class="linklike" data-goto-tab="tva">Voir la TVA payée →</button></p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Part matière</p>
            <p class="kpi-value"><?= e(formatPrice($matiereTtc)) ?></p>
            <p class="kpi-sub">achats cafétéria</p>
        </div>
    </div>

    <div class="card surface glass">
        <h2 class="card-title">Par catégorie</h2>
        <table class="table">
            <thead><tr><th>Catégorie</th><th class="th-num">TTC</th><th class="th-num">Écritures</th></tr></thead>
            <tbody>
                <?php foreach ($byCategory as $c): ?>
                    <tr>
                        <td><?= e($categoryLabels[$c['category']] ?? $c['category']) ?></td>
                        <td class="num">
                            <?php if ($c['ttc'] > 0): ?>
                                <span class="badge badge-warning"><?= e(formatPrice($c['ttc'])) ?></span>
                            <?php else: ?>
                                <span class="muted"><?= e(formatPrice($c['ttc'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= (int) $c['count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byCategory === []): ?>
                    <tr><td colspan="3" class="muted">Aucune dépense sur cette période.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ==================== Onglet : Saisir une dépense ==================== -->
<style>
    /* Scan automatique du ticket : pavés « Informations extraites du
       ticket » et « Détail des produits » — style sobre, réutilise
       .muted, .btn, .form-actions, .field-help. */
    .exp-scan-info, .exp-scan-lines {
        margin: 0.9rem 0 0.2rem; padding: 0.8rem 0.9rem;
        border: 1px dashed rgba(255, 255, 255, 0.2); border-radius: 10px;
        background: rgba(255, 255, 255, 0.03);
    }
    .exp-scan-info[hidden], .exp-scan-lines[hidden] { display: none; }
    .exp-scan-title { font-weight: 800; font-size: 0.9rem; margin: 0 0 0.45rem; }
    .exp-scan-info p { margin: 0.18rem 0; font-size: 0.86rem; }
    .exp-scan-skip { color: var(--muted, #8892a6); font-size: 0.78rem; }
    .exp-scan-warnings { margin: 0.45rem 0 0; padding-left: 1.1rem; }
    .exp-scan-row { display: flex; gap: 0.4rem; align-items: center; margin: 0.3rem 0; }
    .exp-scan-row input {
        padding: 0.4rem 0.55rem; border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 8px; background: rgba(255, 255, 255, 0.05); color: var(--foreground, inherit);
        font-size: 0.88rem; min-width: 0;
    }
    .exp-scan-row .exp-scan-key { flex: 3 1 150px; }
    .exp-scan-row .exp-scan-qty { flex: 0 0 74px; }
    .exp-scan-row .exp-scan-total { flex: 1 1 90px; }
</style>
<div class="compta-tabpane" data-pane="saisie" hidden>
    <section class="card surface glass">
        <h2 class="card-title">Ajouter une dépense</h2>
        <form method="post" action="<?= e(url('/admin/compta/depenses/save')) ?>" enctype="multipart/form-data" data-scan-url="<?= e(url('/admin/compta/depenses/scan')) ?>">
            <?= csrf_field() ?>

            <div class="field-row">
                <div class="field">
                    <label for="spent_at">Date</label>
                    <input type="date" id="spent_at" name="spent_at" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="field">
                    <label for="category">Catégorie</label>
                    <select id="category" name="category">
                        <?php foreach (\App\Models\Expense::CATEGORIES as $cat): ?>
                            <option value="<?= e($cat) ?>"><?= e($categoryLabels[$cat] ?? $cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="label">Libellé</label>
                <input type="text" id="label" name="label" placeholder="ex: Achat frigo portable" required>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="amount">Montant (€)</label>
                    <input type="text" id="amount" name="amount" inputmode="decimal" placeholder="ex: 25,90" required>
                </div>
                <div class="field">
                    <label>Le montant saisi est…</label>
                    <div class="chip-row" role="radiogroup" aria-label="Base du montant">
                        <label class="chip"><input type="radio" name="amount_basis" value="ttc" checked><span>TTC</span></label>
                        <label class="chip"><input type="radio" name="amount_basis" value="ht"><span>HT</span></label>
                    </div>
                </div>
                <div class="field">
                    <label for="vat_rate">Taux de TVA</label>
                    <select id="vat_rate" name="vat_rate">
                        <option value="">Aucune (0 %)</option>
                        <option value="20">20 %</option>
                        <option value="10">10 %</option>
                        <option value="5.5">5,5 %</option>
                        <option value="2.1">2,1 %</option>
                    </select>
                </div>
            </div>

            <p class="field-meta">
                Saisis le montant de ton ticket et dis s'il est <strong>TTC</strong> (TVA incluse) ou <strong>HT</strong> :
                l'app recalcule tout à chaque frappe. Détail en direct :
                <strong id="vat-preview">TVA 0,00 €</strong> ·
                <strong id="ht-preview">HT 0,00 €</strong> ·
                <strong id="ttc-preview">TTC 0,00 €</strong>
            </p>

            <details style="margin:12px 0;">
                <summary>Détails (facture)</summary>
                <div class="field-row">
                    <div class="field">
                        <label for="invoice_number">Numéro de facture <span class="muted">(optionnel)</span></label>
                        <input type="text" id="invoice_number" name="invoice_number" placeholder="ex: FAC-2026-001">
                    </div>
                    <div class="field">
                        <label for="vat_amount">TVA en € <span class="muted">(optionnel — prime sur le taux si le ticket l'indique)</span></label>
                        <input type="text" id="vat_amount" name="vat_amount" inputmode="decimal" placeholder="ex: 4,32">
                    </div>
                </div>
            </details>

            <div class="field">
                <label for="receipt">Justificatif (ticket de caisse) <span class="muted">(optionnel — PDF, JPG, PNG ou WEBP · 5 Mo max)</span></label>
                <input type="file" id="receipt" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp">
                <!-- Scan automatique : occupation (texte simple, pas de
                     spinner) puis message discret en cas d'échec réseau —
                     le justificatif reste utilisable dans tous les cas. -->
                <p class="muted" id="receipt-scan-status" hidden style="margin:0.45rem 0 0; font-size:0.82rem;"></p>
            </div>

            <!-- ── Scan automatique du ticket : pavés remplis par le JS
                 ci-dessous. « Informations extraites du ticket » récapitule
                 l'analyse (préremplissage non destructif déjà fait à
                 réception) ; « Détail des produits » est éditable et part
                 dans le champ `notes` à l'enregistrement. -->
            <div id="receipt-extracted" class="exp-scan-info" hidden>
                <p class="exp-scan-title">Informations extraites du ticket</p>
                <div id="receipt-extracted-list"></div>
                <ul id="receipt-extracted-warnings" class="field-help exp-scan-warnings" hidden></ul>
                <p class="muted" id="receipt-extracted-vatmix" hidden style="margin:0.45rem 0 0; font-size:0.8rem;"></p>
                <div class="form-actions">
                    <button type="button" id="receipt-extracted-apply" class="btn btn-primary btn-sm">Utiliser ces infos</button>
                    <button type="button" id="receipt-extracted-ignore" class="btn btn-ghost btn-sm">Ignorer</button>
                </div>
            </div>
            <div id="receipt-extracted-lines" class="exp-scan-lines" hidden>
                <p class="exp-scan-title">Détail des produits détectés</p>
                <div id="receipt-extracted-rows"></div>
                <div class="form-actions">
                    <button type="button" id="receipt-extracted-row-add" class="btn btn-ghost btn-sm">+ Ligne</button>
                </div>
                <p class="muted" id="receipt-extracted-total" style="margin:0.3rem 0 0; font-size:0.8rem;"></p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer</button>
                <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler</button>
            </div>
        </form>

        <script>
        (function () {
            var amount = document.getElementById('amount');
            var rateEl = document.getElementById('vat_rate');
            var vatOverride = document.getElementById('vat_amount');
            var htPrev = document.getElementById('ht-preview');
            var ttcPrev = document.getElementById('ttc-preview');
            var vatPrev = document.getElementById('vat-preview');
            if (!amount || !rateEl || !vatOverride || !htPrev || !ttcPrev || !vatPrev) return;

            function num(el) {
                var v = parseFloat(String(el.value).replace(/\s/g, '').replace(',', '.'));
                return isFinite(v) && v > 0 ? v : null;
            }
            function fr(n) { return n.toFixed(2).replace('.', ',') + ' €'; }
            function basis() {
                var checked = document.querySelector('input[name="amount_basis"]:checked');
                return checked ? checked.value : 'ttc';
            }
            // Recalcul COMPLET à chaque changement : le montant saisi est
            // la seule source de vérité, les autres valeurs sont toujours
            // dérivées (jamais réutilisées d'un calcul précédent).
            function recalc() {
                var a = num(amount);
                var v = num(vatOverride);
                var r = parseFloat(rateEl.value);
                var hasRate = isFinite(r) && r > 0;
                var ht, ttc, vat;
                if (a === null) {
                    ht = ttc = vat = 0;
                } else if (basis() === 'ttc') {
                    ttc = a;
                    if (v !== null) { vat = v; ht = Math.max(0, ttc - v); }
                    else if (hasRate) { ht = ttc / (1 + r / 100); vat = ttc - ht; }
                    else { ht = ttc; vat = 0; }
                } else {
                    ht = a;
                    if (v !== null) { vat = v; }
                    else if (hasRate) { vat = ht * r / 100; }
                    else { vat = 0; }
                    ttc = ht + vat;
                }
                vatPrev.textContent = 'TVA ' + fr(vat);
                htPrev.textContent = 'HT ' + fr(ht);
                ttcPrev.textContent = 'TTC ' + fr(ttc);
            }
            amount.addEventListener('input', recalc);
            rateEl.addEventListener('change', recalc);
            vatOverride.addEventListener('input', recalc);
            Array.prototype.forEach.call(document.querySelectorAll('input[name="amount_basis"]'), function (input) {
                input.addEventListener('change', recalc);
            });
            recalc();
        })();
        </script>

        <!-- Helpers purs du scan de ticket (scanUpload, invoiceToRows,
             applyInvoiceToExpense, serializeExpenseNotes) : partagés avec
             la saisie d'achats et le livre comptable, testés
             automatiquement. Chargé AVANT le script inline du scan. -->
        <script src="<?= e(rootAssetVersioned('/assets/js/compta-saisie.js')) ?>"></script>
        <script>
        // ── Scan automatique du ticket ──
        // À l'image choisie dans #receipt (change) : analyse serveur
        // immédiate sans bouton, préremplissage NON destructif des
        // champs vides (applyInvoiceToExpense, pur et testé — les
        // recalculs TVA live sont déclenchés par les events input/change
        // dispatchés après remplissage), puis pavé « Informations
        // extraites du ticket » (tout le reste, dont ce qui n'a pas pu
        // être appliqué) et pavé « Détail des produits » éditable ->
        // notes. PDF ou fichier non image : justificatif seulement.
        (function () {
            var form = document.querySelector('form[data-scan-url]');
            var input = document.getElementById('receipt');
            if (!form || !input || !window.ComptaSaisie) return;
            var H = window.ComptaSaisie;

            // Requêtes scopées au formulaire (la vue fusionnée «
            // Opérations » réutilise certains ids dans d'autres onglets).
            var spentAtEl = form.querySelector('#spent_at');
            var labelEl = form.querySelector('#label');
            var amountEl = form.querySelector('#amount');
            var invoiceEl = form.querySelector('#invoice_number');
            var vatEl = form.querySelector('#vat_rate');
            var vatOverride = form.querySelector('#vat_amount');
            var statusEl = document.getElementById('receipt-scan-status');
            var infoBox = document.getElementById('receipt-extracted');
            var infoList = document.getElementById('receipt-extracted-list');
            var infoWarn = document.getElementById('receipt-extracted-warnings');
            var vatMixEl = document.getElementById('receipt-extracted-vatmix');
            var applyBtn = document.getElementById('receipt-extracted-apply');
            var ignoreBtn = document.getElementById('receipt-extracted-ignore');
            var linesBox = document.getElementById('receipt-extracted-lines');
            var rowsBox = document.getElementById('receipt-extracted-rows');
            var rowAddBtn = document.getElementById('receipt-extracted-row-add');
            var totalEl = document.getElementById('receipt-extracted-total');
            if (!spentAtEl || !labelEl || !amountEl || !vatEl || !infoBox || !infoList) return;

            var basisInputs = form.querySelectorAll('input[name="amount_basis"]');
            var csrfInput = form.querySelector('input[name="_csrf"]');
            var scanUrl = form.getAttribute('data-scan-url');
            var lastInvoice = null;

            // Date par défaut du serveur (aujourd'hui) : ce n'est pas
            // une saisie de l'utilisateur. Tant qu'elle n'a pas été
            // modifiée, elle compte comme VIDE pour le préremplissage
            // — la date lue sur le ticket la remplace au lieu d'être
            // écartée comme « déjà renseignée ».
            var defaultSpentAt = spentAtEl.value;

            function basis() {
                for (var i = 0; i < basisInputs.length; i++) {
                    if (basisInputs[i].checked) return basisInputs[i].value;
                }
                return 'ttc';
            }

            function setBasis(v) {
                Array.prototype.forEach.call(basisInputs, function (r) { r.checked = r.value === v; });
            }

            function fr2(n) { return n.toFixed(2).replace('.', ','); }

            function status(msg) {
                if (!statusEl) return;
                statusEl.textContent = msg || '';
                statusEl.hidden = !msg;
            }

            function fire(el, type) {
                el.dispatchEvent(new Event(type, { bubbles: true }));
            }

            function setField(el, value) {
                if (!el || el.value === value) return;
                el.value = value;
                fire(el, 'input');
                fire(el, 'change');
            }

            // État courant du formulaire, format attendu par
            // H.applyInvoiceToExpense (chaînes, '' = vide). La date
            // encore égale au défaut du serveur est vue comme vide.
            function currentState() {
                return {
                    spent_at: spentAtEl.value !== defaultSpentAt ? spentAtEl.value : '',
                    label: labelEl.value,
                    amount: amountEl.value,
                    basis: basis(),
                    vat_rate: vatEl.value,
                    vat_amount: vatOverride ? vatOverride.value : '',
                    invoice_number: invoiceEl ? invoiceEl.value : ''
                };
            }

            // Applique l'état fusionné au DOM : les champs « skipped »
            // ont déjà leur valeur courante (rien à faire) ; les events
            // input/change déclenchent le recalcul TVA live du script
            // existant (#vat-preview, #ht-preview, #ttc-preview).
            function applyResult(res) {
                setField(spentAtEl, res.spent_at);
                setField(labelEl, res.label);
                setField(amountEl, res.amount);
                if (res.basis !== basis()) {
                    setBasis(res.basis);
                    Array.prototype.forEach.call(basisInputs, function (r) { fire(r, 'change'); });
                }
                if (res.vat_rate !== null && res.vat_rate !== '' && parseFloat(vatEl.value) !== parseFloat(res.vat_rate)) {
                    Array.prototype.forEach.call(vatEl.options, function (opt) {
                        if (parseFloat(opt.value) === parseFloat(res.vat_rate)) vatEl.value = opt.value;
                    });
                    fire(vatEl, 'change');
                }
                // TVA multi-taux : le champ #vat_amount existe ici (dans
                // <details>) — on le remplit, le recalcul live le prend.
                if (vatOverride && res.vat_amount !== '') setField(vatOverride, res.vat_amount);
            }

            function addInfoLine(label, value) {
                var p = document.createElement('p');
                var b = document.createElement('strong');
                b.textContent = label + ' : ';
                p.appendChild(b);
                p.appendChild(document.createTextNode(value));
                infoList.appendChild(p);
            }

            function fmtDate(d) {
                if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) return d;
                return d.slice(8, 10) + '/' + d.slice(5, 7) + '/' + d.slice(0, 4);
            }

            function fmtMoney(v) {
                var n = typeof v === 'number' ? v : parseFloat(String(v == null ? '' : v).replace(',', '.'));
                return isFinite(n) ? fr2(n) + ' €' : null;
            }

            // Pavé « Informations extraites du ticket » : TOUT ce que le
            // ticket contient, y compris ce qui n'a pas pu être appliqué.
            function renderInfo(invoice, res) {
                infoList.textContent = '';
                var inv = invoice || {};

                var supplier = String(inv.supplier == null ? '' : inv.supplier).trim();
                addInfoLine('Fournisseur', supplier !== '' ? supplier : 'non détecté');

                var date = String(inv.purchased_at == null ? '' : inv.purchased_at).trim();
                addInfoLine('Date', date !== '' ? fmtDate(date) : 'non détectée');

                var ttc = fmtMoney(inv.total_ttc);
                var ht = fmtMoney(inv.total_ht);
                addInfoLine('Montant TTC', ttc !== null ? ttc + (ht !== null ? ' (HT ' + ht + ')' : '') : 'non détecté');

                // Montant estimé par Σ des lignes (totaux non lus) :
                // signalé clairement — à vérifier avant enregistrement.
                if (res.amount_source === 'lines') {
                    var p = document.createElement('p');
                    p.className = 'exp-scan-skip';
                    p.textContent = 'Montant = somme des lignes détectées (' + res.amount + ' € HT, totaux non lus sur la photo) — vérifie avant d\u2019enregistrer.';
                    infoList.appendChild(p);
                }

                var rates = Array.isArray(inv.vat_rates) ? inv.vat_rates : [];
                var vatTxt;
                if (inv.vat_rate !== null && inv.vat_rate !== undefined && inv.vat_rate !== '') {
                    vatTxt = String(inv.vat_rate).replace('.', ',') + ' %';
                    var vatSum = 0;
                    var hasVat = false;
                    for (var i = 0; i < rates.length; i++) {
                        var v = rates[i] && typeof rates[i].vat === 'number' ? rates[i].vat : parseFloat(String(rates[i] && rates[i].vat).replace(',', '.'));
                        if (isFinite(v)) { vatSum += v; hasVat = true; }
                    }
                    if (hasVat) vatTxt += ' — TVA ' + fr2(vatSum) + ' €';
                } else if (rates.length >= 2) {
                    vatTxt = 'multi-taux (détail ci-dessous)';
                } else {
                    vatTxt = 'non détectée';
                }
                addInfoLine('TVA', vatTxt);

                var num = String(inv.invoice_number == null ? '' : inv.invoice_number).trim();
                addInfoLine('Référence ticket', num !== '' ? num : 'non détectée');

                // Champs laissés tels quel (déjà remplis par
                // l'utilisateur) : la valeur extraite reste affichée ici.
                Array.prototype.forEach.call(res.skipped, function (s) {
                    var p = document.createElement('p');
                    p.className = 'exp-scan-skip';
                    p.textContent = s.field + ' : ' + s.reason;
                    infoList.appendChild(p);
                });

                infoWarn.textContent = '';
                var warnings = Array.isArray(inv.warnings) ? inv.warnings : [];
                if (warnings.length > 0) {
                    Array.prototype.forEach.call(warnings, function (w) {
                        var li = document.createElement('li');
                        li.textContent = String(w == null ? '' : w);
                        infoWarn.appendChild(li);
                    });
                    infoWarn.hidden = false;
                } else {
                    infoWarn.hidden = true;
                }

                // TVA mixte : note explicative (le montant total est déjà
                // dans #vat_amount, le recalcul live l'affiche).
                if (rates.length >= 2 && res.vat_amount !== '') {
                    var labels = [];
                    Array.prototype.forEach.call(rates, function (r) {
                        if (r && r.rate !== null && r.rate !== undefined) labels.push(String(r.rate).replace('.', ',') + ' %');
                    });
                    vatMixEl.textContent = 'TVA mixte (' + labels.join(' + ') + ') : montant TVA total enregistré (' + res.vat_amount + ' €).';
                    vatMixEl.hidden = false;
                } else {
                    vatMixEl.textContent = '';
                    vatMixEl.hidden = true;
                }

                infoBox.hidden = false;
            }

            /* ── Pavé « Détail des produits » : liste éditable -> notes ── */

            function addLineRow(r) {
                var div = document.createElement('div');
                div.className = 'exp-scan-row';

                var key = document.createElement('input');
                key.type = 'text';
                key.className = 'exp-scan-key';
                key.placeholder = 'Produit';
                key.setAttribute('aria-label', 'Produit');
                key.value = (r && r.key) || '';
                // Produit reconnu dans la base : le libellé OCR brut de
                // la facture reste consultable en info-bulle.
                if (r && r.raw_label) {
                    key.title = 'Libellé facture : ' + r.raw_label;
                }

                var qty = document.createElement('input');
                qty.type = 'number';
                qty.className = 'exp-scan-qty';
                qty.min = '1';
                qty.step = '1';
                qty.value = r && r.qty ? String(r.qty) : '1';
                qty.setAttribute('aria-label', 'Quantité');

                var total = document.createElement('input');
                total.type = 'text';
                total.className = 'exp-scan-total';
                total.inputMode = 'decimal';
                total.placeholder = 'Montant (€)';
                total.setAttribute('aria-label', 'Montant');
                total.value = (r && r.total) || '';
                total.addEventListener('input', updateLinesTotal);

                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'btn btn-ghost btn-sm';
                del.textContent = '×';
                del.setAttribute('aria-label', 'Retirer la ligne');
                del.addEventListener('click', function () {
                    div.remove();
                    updateLinesTotal();
                });

                div.appendChild(key);
                div.appendChild(qty);
                div.appendChild(total);
                div.appendChild(del);
                rowsBox.appendChild(div);
            }

            // Lignes du pavé -> [{key, qty, total}] pour
            // serializeExpenseNotes (lignes sans nom ignorées).
            function readLineRows() {
                var out = [];
                Array.prototype.forEach.call(rowsBox.querySelectorAll('.exp-scan-row'), function (div) {
                    var key = div.querySelector('.exp-scan-key').value.trim();
                    if (key === '') return;
                    out.push({
                        key: key,
                        qty: parseInt(div.querySelector('.exp-scan-qty').value, 10) || 1,
                        total: div.querySelector('.exp-scan-total').value
                    });
                });
                return out;
            }

            // Total de contrôle : Σ montants + écart vs montant saisi.
            function updateLinesTotal() {
                if (!totalEl) return;
                var sum = 0;
                var count = 0;
                Array.prototype.forEach.call(readLineRows(), function (r) {
                    var n = H.parseAmount(r.total);
                    if (isFinite(n) && n > 0) { sum += n; count++; }
                });
                if (count === 0) { totalEl.textContent = ''; return; }
                var text = 'Total lignes : ' + fr2(sum) + ' € (' + count + ' ligne' + (count > 1 ? 's' : '') + ')';
                var amount = H.parseAmount(amountEl.value);
                if (isFinite(amount) && amount > 0) {
                    var diff = sum - amount;
                    text += ' · montant saisi : ' + fr2(amount) + ' € · écart : ' + (diff >= 0 ? '+' : '\u2212') + fr2(Math.abs(diff)) + ' €';
                }
                totalEl.textContent = text;
            }

            function renderRows(invoice) {
                var rows = H.invoiceToRows(invoice);
                rowsBox.textContent = '';
                if (rows.length === 0) { linesBox.hidden = true; return; }
                Array.prototype.forEach.call(rows, function (r) { addLineRow(r); });
                linesBox.hidden = false;
                updateLinesTotal();
            }

            function hideScan() {
                lastInvoice = null;
                infoBox.hidden = true;
                if (linesBox) linesBox.hidden = true;
            }

            // Réception d'une analyse : préremplissage non destructif
            // immédiat (champs vides seulement), puis pavés.
            function handleInvoice(invoice) {
                lastInvoice = invoice;
                var res = H.applyInvoiceToExpense(currentState(), invoice);
                applyResult(res);
                renderInfo(invoice, res);
                if (linesBox) renderRows(invoice);
            }

            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                // PDF accepté au scan : le serveur rastérise les pages puis
                // OCRise (fusion ensembliste). Autre non-image : juste le
                // justificatif.
                var isPdf = !!file && (file.type === 'application/pdf' || /\.pdf$/i.test(String(file.name || '')));
                if (!file || (String(file.type || '').indexOf('image/') !== 0 && !isPdf)) {
                    hideScan();
                    status('');
                    return;
                }
                status('Analyse du ticket…');
                H.scanUpload(file, csrfInput ? csrfInput.value : '', scanUrl).then(function (json) {
                    status('');
                    handleInvoice(json && json.invoice ? json.invoice : null);
                }, function (err) {
                    status('Analyse automatique indisponible' + (err && err.message ? ' : ' + err.message : '') + ' — le justificatif reste utilisable, complète les champs à la main.');
                });
            });

            // « Utiliser ces infos » : écrase volontairement TOUS les
            // champs avec les valeurs du ticket (fusion depuis un état
            // vide = aucune valeur « déjà renseignée » à protéger).
            if (applyBtn) {
                applyBtn.addEventListener('click', function () {
                    if (!lastInvoice) return;
                    applyResult(H.applyInvoiceToExpense({
                        spent_at: '', label: '', amount: '', basis: basis(),
                        vat_rate: '', vat_amount: '', invoice_number: ''
                    }, lastInvoice));
                    updateLinesTotal();
                    status('Champs remplis avec les informations extraites — vérifie avant d\u2019enregistrer.');
                });
            }

            // « Ignorer » : referme seulement le pavé — le préremplissage
            // des champs vides, déjà appliqué, reste en place.
            if (ignoreBtn) {
                ignoreBtn.addEventListener('click', function () {
                    infoBox.hidden = true;
                });
            }

            if (rowAddBtn) {
                rowAddBtn.addEventListener('click', function () {
                    addLineRow(null);
                    updateLinesTotal();
                });
            }

            // Sérialisation du détail dans un input hidden `notes` au
            // moment du submit. Pavé vidé -> pas de notes injectées.
            form.addEventListener('submit', function () {
                if (!linesBox || linesBox.hidden) return;
                var data = H.serializeExpenseNotes(readLineRows());
                var existing = form.querySelector('input[name="notes"]');
                if (data === '') {
                    if (existing) existing.remove();
                    return;
                }
                if (!existing) {
                    existing = document.createElement('input');
                    existing.type = 'hidden';
                    existing.name = 'notes';
                    form.appendChild(existing);
                }
                existing.value = data;
            });
        })();
        </script>
    </section>
</div>

<!-- ==================== Onglet : TVA payée ==================== -->
<div class="compta-tabpane" data-pane="tva" hidden>
    <div class="compta-kpis">
        <div class="card surface glass kpi">
            <p class="kpi-label">TVA payée (dépenses)</p>
            <p class="kpi-value"><?= e(formatPrice($vatStats['vat'])) ?></p>
            <p class="kpi-sub">sur la période sélectionnée</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Écritures avec TVA</p>
            <p class="kpi-value"><?= (int) $vatStats['with_vat'] ?></p>
            <p class="kpi-sub">sur <?= (int) ($vatStats['with_vat'] + $vatStats['without_vat']) ?> écritures</p>
        </div>
        <div class="card surface glass kpi">
            <p class="kpi-label">Dépenses HT</p>
            <p class="kpi-value"><?= e(formatPrice($vatStats['ht'])) ?></p>
            <p class="kpi-sub">TTC : <?= e(formatPrice($vatStats['ttc'])) ?></p>
        </div>
    </div>

    <div class="card surface glass table-wrap">
        <h2 class="card-title">TVA par catégorie</h2>
        <p class="muted">Décomposition HT / TVA / TTC des dépenses de la période. Astuce : saisis le TTC avec un taux de TVA pour que la TVA soit décomposée automatiquement.</p>
        <table class="table">
            <thead>
                <tr>
                    <th>Catégorie</th>
                    <th class="th-num">Écritures</th>
                    <th class="th-num">Total HT</th>
                    <th class="th-num">TVA</th>
                    <th class="th-num">Total TTC</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byCategory as $c): ?>
                    <tr>
                        <td><?= e($categoryLabels[$c['category']] ?? $c['category']) ?></td>
                        <td class="num"><?= (int) $c['count'] ?></td>
                        <td class="num"><?= e(formatPrice($c['ht'])) ?></td>
                        <td class="num"><strong><?= e(formatPrice($c['vat'])) ?></strong></td>
                        <td class="num"><strong><?= e(formatPrice($c['ttc'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byCategory === []): ?>
                    <tr><td colspan="5" class="muted">Aucune dépense sur cette période.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($byCategory !== []): ?>
                <tfoot>
                    <tr>
                        <th>Total période</th>
                        <th class="num"><?= (int) ($vatStats['with_vat'] + $vatStats['without_vat']) ?></th>
                        <th class="num"><?= e(formatPrice($vatStats['ht'])) ?></th>
                        <th class="num"><?= e(formatPrice($vatStats['vat'])) ?></th>
                        <th class="num"><?= e(formatPrice($vatStats['ttc'])) ?></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- ==================== Onglet : Journal ==================== -->
<div class="compta-tabpane" data-pane="journal" hidden>
    <div class="card surface glass table-wrap">
        <h2 class="card-title">Écritures</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Catégorie</th>
                    <th>Libellé</th>
                    <th class="th-num">TTC</th>
                    <th class="th-num">HT</th>
                    <th class="th-num">TVA</th>
                    <th>Facture n°</th>
                    <th>Justif.</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expenses as $x): ?>
                    <?php
                        $cat = (string) ($x['category'] ?? '');
                        $badgeClass = match ($cat) {
                            'MATIERE'   => 'badge-success',
                            'EVENEMENT' => 'badge-warning',
                            default     => 'badge-muted',
                        };
                    ?>
                    <tr>
                        <td class="muted"><?= e(formatDate($x['spent_at'] ?? null)) ?></td>
                        <td><span class="badge <?= $badgeClass ?>"><?= e($categoryLabels[$cat] ?? $cat) ?></span></td>
                        <td><strong><?= e((string) ($x['label'] ?? '')) ?></strong></td>
                        <td class="num"><strong><?= e(formatPrice($x['amount_ttc'] ?? 0)) ?></strong></td>
                        <td class="num muted"><?= ($x['amount_ht'] ?? null) !== null ? e(formatPrice($x['amount_ht'])) : '—' ?></td>
                        <td class="num muted"><?= (($x['vat'] ?? null) !== null && (float) $x['vat'] > 0) ? e(formatPrice($x['vat'])) : '—' ?></td>
                        <td><?= (string) ($x['invoice_number'] ?? '') !== '' ? e((string) $x['invoice_number']) : '—' ?></td>
                        <td>
                            <?php if (!empty($x['receipt_path'])): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e(asset((string) $x['receipt_path'])) ?>" target="_blank" rel="noopener" title="Ouvrir le justificatif">Voir</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            <form method="post" action="<?= e(url('/admin/compta/depenses/' . rawurlencode((string) ($x['id'] ?? '')) . '/delete')) ?>" data-confirm="Supprimer cette dépense ?" data-preserve-scroll>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($expenses === []): ?>
                    <tr><td colspan="9" class="muted">Aucune dépense sur la période.</td></tr>
                <?php endif; ?>
            </tbody>
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
