<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 * @var list<array<string,mixed>> $expenses
 * @var array{ttc:float,ht:float,count:int} $agg
 * @var list<array{category:string,ttc:float,count:int}> $byCategory
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
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Dépenses</h1>
        <p class="muted">Les charges de l'asso (matériel, événements, frais...). Le <strong>résultat net</strong> = bénéfice cafétéria − ces dépenses.</p>
    </div>
</div>

<div class="admin-actions">
    <?php require AEIC_VIEWS . '/admin/compta/_period_bar.php'; ?>
</div>

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
        <p class="kpi-label">Part matière</p>
        <p class="kpi-value"><?= e(formatPrice($matiereTtc)) ?></p>
        <p class="kpi-sub">achats cafétéria</p>
    </div>
</div>

<div class="compta-grid">
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
                    <label for="amount_ht">Montant HT (€) <span class="muted">(ou laisse vide)</span></label>
                    <input type="text" id="amount_ht" name="amount_ht" inputmode="decimal" placeholder="ex: 21,58">
                </div>
                <div class="field">
                    <label for="amount_ttc">Montant TTC (€) <span class="muted">(ou laisse vide)</span></label>
                    <input type="text" id="amount_ttc" name="amount_ttc" inputmode="decimal" placeholder="ex: 25,90">
                </div>
                <div class="field">
                    <label for="vat_amount">TVA (€) <span class="muted">(optionnel)</span></label>
                    <input type="text" id="vat_amount" name="vat_amount" inputmode="decimal" placeholder="ex: 4,32">
                </div>
                <div class="field">
                    <label for="vat_rate">Taux de TVA</label>
                    <select id="vat_rate" name="vat_rate">
                        <option value="">Aucune (0 %)</option>
                        <option value="20">20 %</option>
                        <option value="10">10 %</option>
                        <option value="5.5">5,5 %</option>
                        <option value="2.1">2,1 %</option>
                        <option value="0">0 %</option>
                    </select>
                </div>
            </div>

            <p class="field-meta">
                Saisis ce que ton ticket indique : <strong>HT</strong> ou <strong>TTC</strong> (l'app déduit l'autre).
                Le montant de TVA saisi prime sur le taux. TTC calculé : <strong id="ttc-preview">0,00 €</strong>
            </p>

            <details style="margin:12px 0;">
                <summary>Détails (facture)</summary>
                <div class="field">
                    <label for="invoice_number">Numéro de facture <span class="muted">(optionnel)</span></label>
                    <input type="text" id="invoice_number" name="invoice_number" placeholder="ex: FAC-2026-001">
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
            var ht = document.getElementById('amount_ht');
            var ttc = document.getElementById('amount_ttc');
            var vat = document.getElementById('vat_amount');
            var rate = document.getElementById('vat_rate');
            var preview = document.getElementById('ttc-preview');
            if (!ht || !ttc || !vat || !rate || !preview) return;
            function fr(n) { return n.toFixed(2).replace('.', ',') + ' €'; }
            function num(el) {
                var v = parseFloat(String(el.value).replace(',', '.'));
                return isFinite(v) && v >= 0 ? v : null;
            }
            // Chaque saisie recalcule les autres champs à partir du taux
            // (ou du montant de TVA saisi, prioritaire).
            function recalc(source) {
                var h = num(ht), t = num(ttc), v = num(vat);
                var r = parseFloat(rate.value);
                var hasRate = isFinite(r);

                if (source === 'ttc') {
                    if (t === null) { preview.textContent = fr(h ?? 0); return; }
                    if (v !== null && v > 0) { ht.value = Math.max(0, t - v).toFixed(2).replace('.', ','); }
                    else if (hasRate && r > 0) {
                        var hh = t / (1 + r / 100);
                        ht.value = hh.toFixed(2).replace('.', ',');
                        vat.value = (t - hh).toFixed(2).replace('.', ',');
                    } else if (h === null) { ht.value = t.toFixed(2).replace('.', ','); }
                } else if (source === 'vat') {
                    if (h !== null) { ttc.value = (h + (v ?? 0)).toFixed(2).replace('.', ','); }
                } else { // source === 'ht'
                    var vatUsed = (v !== null && v > 0) ? v : (hasRate ? h * r / 100 : 0);
                    if (isFinite(vatUsed) && (ttc.value === '' || num(ttc) === null)) {
                        ttc.value = (h + vatUsed).toFixed(2).replace('.', ',');
                    }
                }
                var tf = num(ttc);
                preview.textContent = fr(tf ?? h ?? 0);
            }
            ht.addEventListener('input', function () { recalc('ht'); });
            ttc.addEventListener('input', function () { recalc('ttc'); });
            vat.addEventListener('input', function () { recalc('vat'); });
            rate.addEventListener('change', function () { recalc('ht'); });
            recalc('ht');
        })();
        </script>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Par catégorie</h2>
        <table class="table">
            <thead><tr><th>Catégorie</th><th>TTC</th><th>Écritures</th></tr></thead>
            <tbody>
                <?php foreach ($byCategory as $c): ?>
                    <tr>
                        <td><?= e($categoryLabels[$c['category']] ?? $c['category']) ?></td>
                        <td>
                            <?php if ($c['ttc'] > 0): ?>
                                <span class="badge badge-warning"><?= e(formatPrice($c['ttc'])) ?></span>
                            <?php else: ?>
                                <span class="muted"><?= e(formatPrice($c['ttc'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $c['count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byCategory === []): ?>
                    <tr><td colspan="3" class="muted">Aucune dépense sur cette période.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

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
                <tr><td colspan="8" class="muted">Aucune dépense sur cette période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

