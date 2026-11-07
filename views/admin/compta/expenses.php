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
    /* Édition inline d'une dépense (Journal) : ligne « Modifier » sous
       chaque écriture — formulaire compact POST /depenses/{id}/update.
       (Les styles du pavé de scan .exp-scan-* ont suivi le formulaire
       de dépense dans la vue partielle _expense_form.php.) */
    .exp-edit summary {
        display: inline-block; cursor: pointer; list-style: none;
        padding: 0.2rem 0.6rem; border-radius: 999px;
        border: 1px solid var(--border, rgba(255,255,255,0.12));
        background: rgba(255, 255, 255, 0.04);
        font-size: 0.76rem; font-weight: 800; color: var(--foreground, inherit);
    }
    .exp-edit summary::-webkit-details-marker { display: none; }
    .exp-edit[open] summary { margin-bottom: 0.45rem; }
    .exp-edit-form {
        display: flex; flex-wrap: wrap; gap: 0.45rem; align-items: flex-end;
        padding: 0.6rem 0.7rem;
        border: 1px dashed rgba(255, 255, 255, 0.2); border-radius: 10px;
        background: rgba(255, 255, 255, 0.03);
    }
    .exp-edit-form label {
        display: grid; gap: 0.2rem;
        font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em;
        color: var(--muted, #8892a6);
    }
    .exp-edit-form input, .exp-edit-form select, .exp-edit-form textarea {
        padding: 0.35rem 0.5rem; border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 8px; background: rgba(255, 255, 255, 0.05); color: var(--foreground, inherit);
        font-size: 0.82rem; min-width: 0;
    }
    .exp-edit-form textarea { width: 100%; resize: vertical; }
</style>
<div class="compta-tabpane" data-pane="saisie" hidden>
    <!-- Fusion « Saisir une dépense » × « Achats » : le formulaire de
         dépense (catégorie, HT/TTC, TVA, justificatif, scan du ticket)
         vit désormais dans la page unifiée « Saisir une dépense » de la
         sidebar (Comptabilité) — vue partielle _expense_form.php, onglet
         Saisir, volet « Frais divers ». Cette page garde le journal, la
         TVA et l'édition/suppression des dépenses existantes. -->
    <section class="card surface glass">
        <h2 class="card-title">La saisie a déménagé</h2>
        <p>Les dépenses se saisissent maintenant au même endroit que les factures d'achats, dans <strong>« Saisir une dépense »</strong> (menu Comptabilité) :</p>
        <ul>
            <li><strong>Facture avec des produits</strong> (Metro…) → volet « Facture (lignes d'achats) » : stock et coûts de revient ;</li>
            <li><strong>Frais divers</strong> (matériel, événements, frais…) → volet « Frais divers (sans produits) » : le même formulaire qu'ici auparavant.</li>
        </ul>
        <p class="muted">Une facture n'est comptée qu'une seule fois — jamais à la fois en dépense et en achats.</p>
        <div class="form-actions">
            <a class="btn btn-primary" href="<?= e(url('/admin/compta/achats')) ?>?tab=saisie&amp;volet=frais">Saisir une dépense (frais divers) →</a>
        </div>
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
        <!-- Filtre rapide (lot 4B) : masque les écritures ne correspondant
             pas ; les blocs d'édition inline suivent leur ligne. Aucun
             formulaire n'est touché. -->
        <div class="table-tools">
            <input type="search" class="tt-filter" data-table-filter="#expenses-journal"
                   aria-label="Filtrer les écritures" placeholder="Filtrer le journal…">
            <span class="tt-count muted" data-table-count="#expenses-journal" aria-live="polite"></span>
        </div>
        <table class="table" id="expenses-journal" data-table data-table-nosort>
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
                    <?php if ((string) ($x['id'] ?? '') !== ''): ?>
                    <tr class="exp-edit-row tt-sub-row">
                        <td colspan="9">
                            <details class="exp-edit">
                                <summary>Modifier</summary>
                                <!-- Édition inline (POST /admin/compta/depenses/{id}/update) :
                                     montant prérempli en TTC (amount_basis caché) ;
                                     sans nouveau fichier, le justificatif existant
                                     est conservé ; avec fichier, il REMPLACE
                                     l'ancien. -->
                                <form class="exp-edit-form" method="post" action="<?= e(url('/admin/compta/depenses/' . rawurlencode((string) $x['id']) . '/update')) ?>" enctype="multipart/form-data">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="return_to" value="/admin/compta/depenses">
                                    <input type="hidden" name="amount_basis" value="ttc">
                                    <label>Date <input type="date" name="spent_at" value="<?= e((string) ($x['spent_at'] ?? '')) ?>" required></label>
                                    <label style="flex: 2 1 200px;">Libellé <input type="text" name="label" value="<?= e((string) ($x['label'] ?? '')) ?>" required style="width: 100%;"></label>
                                    <label>Catégorie
                                        <select name="category">
                                            <?php foreach (\App\Models\Expense::CATEGORIES as $editCat): ?>
                                                <option value="<?= e($editCat) ?>" <?= $editCat === $cat ? 'selected' : '' ?>><?= e($categoryLabels[$editCat] ?? $editCat) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Montant TTC (€) <input type="number" name="amount" step="0.01" min="0.01" value="<?= e(number_format((float) ($x['amount_ttc'] ?? 0), 2, '.', '')) ?>" required></label>
                                    <?php $editVat = (float) ($x['vat'] ?? 0); ?>
                                    <label>TVA (€) <input type="number" name="vat_amount" step="0.01" min="0" value="<?= $editVat > 0 ? e(number_format($editVat, 2, '.', '')) : '' ?>"></label>
                                    <label>Taux
                                        <select name="vat_rate">
                                            <option value="">—</option>
                                            <?php foreach (['20', '10', '5.5', '2.1', '0'] as $editRate): ?>
                                                <option value="<?= e($editRate) ?>"><?= e($editRate) ?> %</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Facture n° <input type="text" name="invoice_number" value="<?= e((string) ($x['invoice_number'] ?? '')) ?>"></label>
                                    <label style="flex: 1 1 100%;">Notes <textarea name="notes" rows="2"><?= e((string) ($x['notes'] ?? '')) ?></textarea></label>
                                    <label style="flex: 1 1 100%;">Preuve (remplace l'existante · PDF/JPG/PNG/WEBP · 5 Mo max) <input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>
                                    <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                    <?php endif; ?>
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
