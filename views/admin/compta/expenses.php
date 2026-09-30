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
<div class="compta-tabpane" data-pane="saisie" hidden>
    <section class="card surface glass">
        <h2 class="card-title">Ajouter une dépense</h2>
        <form method="post" action="<?= e(url('/admin/compta/depenses/save')) ?>" enctype="multipart/form-data">
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
