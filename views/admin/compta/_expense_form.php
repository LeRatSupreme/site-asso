<?php

declare(strict_types=1);

/**
 * Vue PARTIELLE : formulaire « Ajouter une dépense » (frais divers),
 * factorisé depuis l'ex-onglet « Saisir une dépense » de la page
 * Dépenses. Utilisé par le volet « Frais divers » de l'onglet Saisir
 * de la page unifiée « Saisir une dépense » (views/admin/compta/purchases.php).
 *
 * Dépendances de la vue HÔTE :
 *  - charger public/assets/js/compta-saisie.js AVANT l'inclusion
 *    (helpers purs du scan : scanUpload, applyInvoiceToExpense,
 *    invoiceToRows, serializeExpenseNotes — testés dans
 *    tests/js/compta-saisie.test.js) ;
 *  - ids préfixés « xp- » : la page unifiée porte aussi les ids du
 *    volet Facture (vat_rate, invoice_number…) — aucun doublon d'id.
 *
 * POST /admin/compta/depenses/save — JAMAIS /admin/compta/achats/* :
 * ce formulaire écrit UNIQUEMENT dans expenses (fusion « Saisir une
 * dépense » × « Achats » : un ticket = un seul registre, invariant
 * anti-doublon garanti par la structure, les deux volets étant des
 * <form> distincts dont un seul est visible/soumissible).
 *
 * @var string $expenseReturnTo Chemin de retour après enregistrement
 *      (champ return_to ; défaut : journal des dépenses).
 */

// Libellés français des catégories de dépenses (Expense::CATEGORIES).
$categoryLabels = $categoryLabels ?? [
    'MATIERE'   => 'Matière (cafétéria)',
    'MATERIEL'  => 'Matériel',
    'EVENEMENT' => 'Événements',
    'FRAIS'     => 'Frais (bancaires, abonnements)',
    'DIVERS'    => 'Divers',
];

$expenseReturnTo = $expenseReturnTo ?? '/admin/compta/depenses';
?>
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
<form method="post" action="<?= e(url('/admin/compta/depenses/save')) ?>" enctype="multipart/form-data" data-expense-form data-scan-url="<?= e(url('/admin/compta/depenses/scan')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= e($expenseReturnTo) ?>">

    <div class="field-row">
        <div class="field">
            <label for="xp-spent-at">Date</label>
            <input type="date" id="xp-spent-at" name="spent_at" value="<?= e(date('Y-m-d')) ?>" required>
        </div>
        <div class="field">
            <label for="xp-category">Catégorie</label>
            <select id="xp-category" name="category">
                <?php foreach (\App\Models\Expense::CATEGORIES as $cat): ?>
                    <option value="<?= e($cat) ?>"><?= e($categoryLabels[$cat] ?? $cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field">
        <label for="xp-label">Libellé</label>
        <input type="text" id="xp-label" name="label" placeholder="ex: Achat frigo portable" required>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="xp-amount">Montant (€)</label>
            <input type="text" id="xp-amount" name="amount" inputmode="decimal" placeholder="ex: 25,90" required>
        </div>
        <div class="field">
            <label>Le montant saisi est…</label>
            <div class="chip-row" role="radiogroup" aria-label="Base du montant">
                <label class="chip"><input type="radio" name="amount_basis" value="ttc" checked><span>TTC</span></label>
                <label class="chip"><input type="radio" name="amount_basis" value="ht"><span>HT</span></label>
            </div>
        </div>
        <div class="field">
            <label for="xp-vat-rate">Taux de TVA</label>
            <select id="xp-vat-rate" name="vat_rate">
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
        <strong id="xp-vat-preview">TVA 0,00 €</strong> ·
        <strong id="xp-ht-preview">HT 0,00 €</strong> ·
        <strong id="xp-ttc-preview">TTC 0,00 €</strong>
    </p>

    <details style="margin:12px 0;">
        <summary>Détails (facture)</summary>
        <div class="field-row">
            <div class="field">
                <label for="xp-invoice-number">Numéro de facture <span class="muted">(optionnel)</span></label>
                <input type="text" id="xp-invoice-number" name="invoice_number" placeholder="ex: FAC-2026-001">
            </div>
            <div class="field">
                <label for="xp-vat-amount">TVA en € <span class="muted">(optionnel — prime sur le taux si le ticket l'indique)</span></label>
                <input type="text" id="xp-vat-amount" name="vat_amount" inputmode="decimal" placeholder="ex: 4,32">
            </div>
        </div>
    </details>

    <div class="field">
        <label for="xp-receipt">Justificatif (ticket de caisse) <span class="muted">(optionnel — PDF, JPG, PNG ou WEBP · 5 Mo max)</span></label>
        <input type="file" id="xp-receipt" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp">
        <!-- Scan automatique : occupation (texte simple, pas de
             spinner) puis message discret en cas d'échec réseau —
             le justificatif reste utilisable dans tous les cas. -->
        <p class="muted" id="xp-scan-status" hidden style="margin:0.45rem 0 0; font-size:0.82rem;"></p>
    </div>

    <!-- ── Scan automatique du ticket : pavés remplis par le JS
         ci-dessous. « Informations extraites du ticket » récapitule
         l'analyse (préremplissage non destructif déjà fait à
         réception) ; « Détail des produits » est éditable et part
         dans le champ `notes` à l'enregistrement. -->
    <div id="xp-extracted" class="exp-scan-info" hidden>
        <p class="exp-scan-title">Informations extraites du ticket</p>
        <div id="xp-extracted-list"></div>
        <ul id="xp-extracted-warnings" class="field-help exp-scan-warnings" hidden></ul>
        <p class="muted" id="xp-extracted-vatmix" hidden style="margin:0.45rem 0 0; font-size:0.8rem;"></p>
        <div class="form-actions">
            <button type="button" id="xp-extracted-apply" class="btn btn-primary btn-sm">Utiliser ces infos</button>
            <button type="button" id="xp-extracted-ignore" class="btn btn-ghost btn-sm">Ignorer</button>
        </div>
    </div>
    <div id="xp-extracted-lines" class="exp-scan-lines" hidden>
        <p class="exp-scan-title">Détail des produits détectés</p>
        <div id="xp-extracted-rows"></div>
        <div class="form-actions">
            <button type="button" id="xp-extracted-row-add" class="btn btn-ghost btn-sm">+ Ligne</button>
        </div>
        <p class="muted" id="xp-extracted-total" style="margin:0.3rem 0 0; font-size:0.8rem;"></p>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler</button>
    </div>
</form>

<script>
// Aperçu TVA/HT/TTC en direct : le montant saisi est la seule source
// de vérité, les autres valeurs sont toujours dérivées. Requêtes
// scopées au formulaire (la page unifiée porte aussi les champs du
// volet « Facture »).
(function () {
    var form = document.querySelector('form[data-expense-form]');
    if (!form) return;
    var amount = form.querySelector('#xp-amount');
    var rateEl = form.querySelector('#xp-vat-rate');
    var vatOverride = form.querySelector('#xp-vat-amount');
    var htPrev = form.querySelector('#xp-ht-preview');
    var ttcPrev = form.querySelector('#xp-ttc-preview');
    var vatPrev = form.querySelector('#xp-vat-preview');
    if (!amount || !rateEl || !vatOverride || !htPrev || !ttcPrev || !vatPrev) return;

    function num(el) {
        var v = parseFloat(String(el.value).replace(/\s/g, '').replace(',', '.'));
        return isFinite(v) && v > 0 ? v : null;
    }
    function fr(n) { return n.toFixed(2).replace('.', ',') + ' €'; }
    function basis() {
        var checked = form.querySelector('input[name="amount_basis"]:checked');
        return checked ? checked.value : 'ttc';
    }
    // Recalcul COMPLET à chaque changement (jamais réutilisé d'un
    // calcul précédent).
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
    Array.prototype.forEach.call(form.querySelectorAll('input[name="amount_basis"]'), function (input) {
        input.addEventListener('change', recalc);
    });
    recalc();
})();
</script>
<script>
// ── Scan automatique du ticket (dépense seule) ──
// À l'image choisie dans #xp-receipt (change) : analyse serveur
// immédiate sans bouton, préremplissage NON destructif des champs
// vides (applyInvoiceToExpense, pur et testé — les recalculs TVA live
// sont déclenchés par les events input/change dispatchés après
// remplissage), puis pavé « Informations extraites du ticket » et pavé
// « Détail des produits » éditable -> notes. PDF ou fichier non
// image : justificatif seulement. Nécessite window.ComptaSaisie
// (compta-saisie.js chargé par la vue hôte AVANT cette inclusion).
(function () {
    var form = document.querySelector('form[data-expense-form]');
    var input = form ? form.querySelector('#xp-receipt') : null;
    if (!form || !input || !window.ComptaSaisie) return;
    var H = window.ComptaSaisie;

    // Requêtes scopées au formulaire (la page unifiée « Opérations »
    // réutilise certains noms de champs dans le volet « Facture »).
    var spentAtEl = form.querySelector('#xp-spent-at');
    var labelEl = form.querySelector('#xp-label');
    var amountEl = form.querySelector('#xp-amount');
    var invoiceEl = form.querySelector('#xp-invoice-number');
    var vatEl = form.querySelector('#xp-vat-rate');
    var vatOverride = form.querySelector('#xp-vat-amount');
    var statusEl = form.querySelector('#xp-scan-status');
    var infoBox = form.querySelector('#xp-extracted');
    var infoList = form.querySelector('#xp-extracted-list');
    var infoWarn = form.querySelector('#xp-extracted-warnings');
    var vatMixEl = form.querySelector('#xp-extracted-vatmix');
    var applyBtn = form.querySelector('#xp-extracted-apply');
    var ignoreBtn = form.querySelector('#xp-extracted-ignore');
    var linesBox = form.querySelector('#xp-extracted-lines');
    var rowsBox = form.querySelector('#xp-extracted-rows');
    var rowAddBtn = form.querySelector('#xp-extracted-row-add');
    var totalEl = form.querySelector('#xp-extracted-total');
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
    // existant (#xp-vat-preview, #xp-ht-preview, #xp-ttc-preview).
    function applyResult(res) {
        setField(spentAtEl, res.spent_at);
        setField(labelEl, res.label);
        setField(amountEl, res.amount);
        // Référence du ticket : le n° extrait remplit le champ
        // vide (non-destructif géré par applyInvoiceToExpense).
        if (invoiceEl) setField(invoiceEl, res.invoice_number);
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
        // TVA multi-taux : le champ #xp-vat-amount existe ici (dans
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
        // dans #xp-vat-amount, le recalcul live l'affiche).
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
