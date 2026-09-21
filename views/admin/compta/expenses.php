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
        <form method="post" action="<?= e(url('/admin/compta/depenses/save')) ?>">
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
                    <label for="amount_ht">Montant HT (€)</label>
                    <input type="text" id="amount_ht" name="amount_ht" inputmode="decimal" placeholder="ex: 21,58" required>
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

            <p class="field-meta">Montant TTC calculé : <strong id="ttc-preview">0,00 €</strong> <span class="muted">(la TVA est ajoutée au montant HT)</span></p>

            <details style="margin:12px 0;">
                <summary>Détails (fournisseur)</summary>
                <div class="field">
                    <label for="supplier">Fournisseur <span class="muted">(optionnel)</span></label>
                    <input type="text" id="supplier" name="supplier" placeholder="ex: Metro…">
                </div>
            </details>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer</button>
                <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler</button>
            </div>
        </form>

        <script>
        (function () {
            var ht = document.getElementById('amount_ht');
            var rate = document.getElementById('vat_rate');
            var preview = document.getElementById('ttc-preview');
            if (!ht || !rate || !preview) return;
            function fr(n) { return n.toFixed(2).replace('.', ',') + ' €'; }
            function update() {
                var h = parseFloat(String(ht.value).replace(',', '.'));
                if (!isFinite(h) || h < 0) h = 0;
                var r = parseFloat(rate.value);
                var ttc = h + (isFinite(r) ? h * r / 100 : 0);
                preview.textContent = fr(ttc);
            }
            ht.addEventListener('input', update);
            rate.addEventListener('change', update);
            update();
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
                <th>Fournisseur</th>
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
                    <td><?= (string) ($x['supplier'] ?? '') !== '' ? e((string) $x['supplier']) : '—' ?></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/compta/depenses/' . rawurlencode((string) ($x['id'] ?? '')) . '/delete')) ?>" data-confirm="Supprimer cette dépense ?" data-preserve-scroll>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($expenses === []): ?>
                <tr><td colspan="7" class="muted">Aucune dépense sur cette période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

