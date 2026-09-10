<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $user
 * @var list<array<string,mixed>> $rows
 * @var list<string>              $products
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string>      $periodOptions
 * @var float                     $total
 * @var int                       $count
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Achats & stock</h1>
        <p class="muted">Note ici <strong>ce que tu commandes vraiment</strong>. Ces achats alimentent le stock théorique de l'inventaire : dernier comptage + achats − ventes.</p>
    </div>
</div>

<div class="admin-actions">
    <form method="get" class="reappro-bar" id="period-filters">
        <div class="reappro-field">
            <label class="field-label" for="period">📅 Période</label>
            <select name="period" id="period">
                <?php foreach ($periodOptions as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $k === $period['preset'] ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="reappro-dates" id="period-custom" <?= $period['preset'] === 'custom' ? '' : 'hidden' ?>>
            <div>
                <label class="field-label" for="from">Du</label>
                <input type="date" name="from" id="from" value="<?= e($period['from'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="to">Au</label>
                <input type="date" name="to" id="to" value="<?= e($period['to'] ?? '') ?>">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Appliquer</button>
    </form>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Total des achats</p>
        <p class="kpi-value"><?= e(formatPrice($total)) ?></p>
        <p class="kpi-sub">sur la période sélectionnée</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Commandes</p>
        <p class="kpi-value"><?= (int) $count ?></p>
        <p class="kpi-sub">sur la période sélectionnée</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Voir aussi</p>
        <p class="kpi-sub">
            <a href="<?= e(url('/admin/compta/inventaire')) ?>">Faire un inventaire →</a><br>
            <a href="<?= e(url('/admin/compta/reappro')) ?>">Calculer le réappro →</a>
        </p>
    </div>
</div>

<div class="compta-grid">
    <section class="card surface glass">
        <h2 class="card-title">Enregistrer un achat</h2>
        <form method="post" action="<?= e(url('/admin/compta/achats/save')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label for="purchased_at">Date</label>
                <input type="date" id="purchased_at" name="purchased_at" value="<?= e(date('Y-m-d')) ?>" required>
            </div>

            <div class="field">
                <label for="product_key">Produit</label>
                <input type="text" id="product_key" name="product_key" list="purchase-products"
                    placeholder="ex: Coca 33cl" autocomplete="off" required>
                <datalist id="purchase-products">
                    <?php foreach ($products as $p): ?>
                        <option value="<?= e($p) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <p class="field-help">Choisis un nom existant (mêmes noms que dans les ventes) pour alimenter le bon stock théorique.</p>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="quantity">Quantité</label>
                    <input type="number" id="quantity" name="quantity" value="1" min="1" step="1" required>
                </div>
                <div class="field">
                    <label for="unit_cost">Coût unitaire (€)</label>
                    <input type="text" id="unit_cost" name="unit_cost" placeholder="ex: 0,45" inputmode="decimal" required>
                </div>
            </div>

            <div class="field">
                <label for="supplier">Fournisseur <span class="muted">(optionnel)</span></label>
                <input type="text" id="supplier" name="supplier" placeholder="ex: Metro">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Enregistrer l'achat</button>
            <button type="button" class="btn btn-ghost btn-block" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler la saisie</button>
        </form>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Comment ça marche</h2>
        <p>📌 Le <a href="<?= e(url('/admin/compta/reappro')) ?>">réappro</a> calcule ce qu'il <strong>FAUT</strong> commander ; cette page trace ce qui a <strong>ÉTÉ</strong> commandé.</p>
        <p>📌 Le coût saisi ici peut aussi être reporté dans <a href="<?= e(url('/admin/compta/couts')) ?>">Coûts de revient</a> (lot daté) pour un bénéfice juste.</p>
        <p>📌 Les achats alimentent le <strong>stock théorique</strong> visible dans <a href="<?= e(url('/admin/compta/inventaire')) ?>">l'inventaire</a> : dernier comptage + achats − ventes.</p>
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
                <th class="th-num">Total</th>
                <th>Fournisseur</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e(formatDate((string) $r['purchased_at'])) ?></td>
                    <td><strong><?= e((string) $r['product_key']) ?></strong></td>
                    <td class="num"><?= (int) $r['quantity'] ?></td>
                    <td class="num"><?= e(formatPrice((float) $r['unit_cost'])) ?></td>
                    <td class="num"><strong><?= e(formatPrice((float) $r['total_ttc'])) ?></strong></td>
                    <td><?= e((string) ($r['supplier'] ?? '—')) ?></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/compta/achats/' . rawurlencode((string) $r['id']) . '/delete')) ?>"
                              data-confirm="Supprimer cet achat ?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="muted">Aucun achat sur la période sélectionnée.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
(function () {
    // Sélecteur « 📅 Période » : les presets soumettent seuls, comme sur
    // Réappro ; « Personnalisé » révèle d'abord les bornes de dates.
    var form = document.getElementById('period-filters');
    var custom = document.getElementById('period-custom');
    if (!form) return;

    var select = form.querySelector('select[name="period"]');
    if (!select) return;

    select.addEventListener('change', function () {
        if (custom) {
            custom.hidden = select.value !== 'custom';
        }
        if (select.value === 'custom') {
            var from = document.getElementById('from');
            if (from) from.focus();
        } else {
            form.submit();
        }
    });
})();
</script>
