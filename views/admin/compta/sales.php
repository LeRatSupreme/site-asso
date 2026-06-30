<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var list<array{value:string,label:string}> $months
 * @var list<string> $categories
 * @var list<string> $products
 * @var array{month:string,category:string,product:string,payment:string} $filters
 */

// Totaux sur les ventes affichées.
$totQty = 0;
$totTtc = 0.0;
$totProfit = 0.0;
foreach ($rows as $r) {
    $totQty    += (int) ($r['quantity'] ?? 0);
    $totTtc    += (float) ($r['price_ttc'] ?? 0);
    $totProfit += (float) ($r['profit'] ?? 0);
}
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Journal des ventes</h1>
        <p class="muted">Toutes les ventes importées de SumUp, filtrables par mois, catégorie, produit et moyen de paiement.</p>
    </div>
</div>

<!-- Résumé -->
<div class="cat-total card surface glass">
    <div class="cat-total-item">
        <span class="cat-total-label">Ventes</span>
        <strong class="cat-total-value"><?= count($rows) ?></strong>
    </div>
    <span class="cat-total-sep"></span>
    <div class="cat-total-item">
        <span class="cat-total-label">Quantité</span>
        <strong class="cat-total-value"><?= $totQty ?></strong>
    </div>
    <span class="cat-total-sep"></span>
    <div class="cat-total-item">
        <span class="cat-total-label">CA (TTC)</span>
        <strong class="cat-total-value"><?= e(formatPrice($totTtc)) ?></strong>
    </div>
    <span class="cat-total-sep"></span>
    <div class="cat-total-item">
        <span class="cat-total-label">Bénéfice</span>
        <strong class="cat-total-value is-positive"><?= e(formatPrice($totProfit)) ?></strong>
    </div>
</div>

<!-- Barre de filtres -->
<form method="get" class="costs-toolbar">
    <select name="month" aria-label="Mois">
        <option value="all" <?= ($filters['month'] ?? '') === 'all' ? 'selected' : '' ?>>Tous les mois</option>
        <?php foreach ($months as $m): ?>
            <option value="<?= e($m['value']) ?>" <?= $m['value'] === ($filters['month'] ?? '') ? 'selected' : '' ?>><?= e($m['label']) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="category" aria-label="Catégorie">
        <option value="">Toutes catégories</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= e($c) ?>" <?= $c === ($filters['category'] ?? '') ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="product" aria-label="Produit">
        <option value="">Tous produits</option>
        <?php foreach ($products as $p): ?>
            <option value="<?= e($p) ?>" <?= $p === ($filters['product'] ?? '') ? 'selected' : '' ?>><?= e($p) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="payment" aria-label="Paiement">
        <option value="">Tous paiements</option>
        <option value="CARTE" <?= ($filters['payment'] ?? '') === 'CARTE' ? 'selected' : '' ?>>Carte</option>
        <option value="LIQUIDE" <?= ($filters['payment'] ?? '') === 'LIQUIDE' ? 'selected' : '' ?>>Liquide</option>
    </select>

    <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/ventes?' . http_build_query(array_merge($filters, ['export' => 'csv'])))) ?>">📄 Exporter CSV</a>
</form>

<!-- Tableau -->
<div class="card surface glass table-wrap">
    <table class="table sales-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Produit</th>
                <th>Cat.</th>
                <th class="th-num">Qté</th>
                <th class="th-num">TTC</th>
                <th class="th-num">Coût</th>
                <th class="th-num">Bénéfice</th>
                <th>Paiement</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <?php
                    $profit = (float) ($r['profit'] ?? 0);
                    $isCustom = !empty($r['is_custom_amount']);
                ?>
                <tr class="<?= $isCustom ? 'row-muted' : '' ?>">
                    <td class="muted"><?= e(formatDateTime($r['sold_at'] ?? null)) ?></td>
                    <td>
                        <strong><?= e((string) ($r['description'] ?? '—')) ?></strong>
                        <?php if (!empty($r['product_key']) && $r['product_key'] !== $r['description']): ?>
                            <span class="muted">(<?= e((string) $r['product_key']) ?>)</span>
                        <?php endif; ?>
                        <?php if ($isCustom): ?>
                            <span class="badge badge-warning">Perso</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($r['category'] ?? '—')) ?></td>
                    <td class="num"><?= e((string) ($r['quantity'] ?? 1)) ?></td>
                    <td class="num"><strong><?= e(formatPrice($r['price_ttc'] ?? 0)) ?></strong></td>
                    <td class="num muted"><?= e(formatPrice($r['cost_price'] ?? 0)) ?></td>
                    <td class="num <?= $profit >= 0 ? 'is-positive' : 'is-negative' ?>"><?= $isCustom ? '<span class="muted">—</span>' : e(formatPrice($profit)) ?></td>
                    <td>
                        <?php if (($r['payment_method'] ?? '') === 'LIQUIDE'): ?>
                            <span class="badge badge-muted">💵 Liquide</span>
                        <?php else: ?>
                            <span class="badge badge-success">💳 Carte</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="muted" style="text-align:center;padding:2rem">Aucune vente ne correspond à ces filtres.</td></tr>
            <?php endif; ?>
        </tbody>
        <?php if ($rows !== []): ?>
            <tfoot>
                <tr>
                    <th colspan="3" style="text-align:right">Total (<?= count($rows) ?> ventes) :</th>
                    <th class="num"><?= $totQty ?></th>
                    <th class="num"><?= e(formatPrice($totTtc)) ?></th>
                    <th class="num"></th>
                    <th class="num is-positive"><?= e(formatPrice($totProfit)) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>
</div>

<p class="card-meta">
    Le journal liste chaque ligne du rapport SumUp importé. Les « Montants personnalisés » (perso) sont inclus dans le CA mais exclus du bénéfice.
</p>
