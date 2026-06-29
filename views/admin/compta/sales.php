<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var list<array{value:string,label:string}> $months
 * @var list<string> $categories
 * @var list<string> $products
 * @var array{month:string,category:string,product:string,payment:string} $filters
 */
?>
<div class="admin-actions">
    <form method="get" class="compta-filters">
        <select name="month">
            <option value="all" <?= $filters['month'] === 'all' ? 'selected' : '' ?>>Tous les mois</option>
            <?php foreach ($months as $m): ?>
                <option value="<?= e($m['value']) ?>" <?= $m['value'] === $filters['month'] ? 'selected' : '' ?>><?= e($m['label']) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="category">
            <option value="">Toutes catégories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c) ?>" <?= $c === $filters['category'] ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="product">
            <option value="">Tous produits</option>
            <?php foreach ($products as $p): ?>
                <option value="<?= e($p) ?>" <?= $p === $filters['product'] ? 'selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
        </select>

        <select name="payment">
            <option value="">Tous paiements</option>
            <option value="CARTE" <?= $filters['payment'] === 'CARTE' ? 'selected' : '' ?>>Carte</option>
            <option value="LIQUIDE" <?= $filters['payment'] === 'LIQUIDE' ? 'selected' : '' ?>>Liquide</option>
        </select>

        <button type="submit" class="btn btn-outline btn-sm">Filtrer</button>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/ventes?' . http_build_query(array_merge($filters, ['export' => 'csv'])))) ?>">Exporter CSV</a>
    </form>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Date</th><th>Réf.</th><th>Paiement</th><th>Description</th><th>Produit</th><th>Cat.</th><th>Qté</th><th>TTC</th><th>Coût</th><th>Bénéfice</th></tr></thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e(formatDateTime($r['sold_at'] ?? null)) ?></td>
                    <td><code><?= e((string) ($r['transaction_ref'] ?? '')) ?></code></td>
                    <td>
                        <?php if (($r['payment_method'] ?? '') === 'LIQUIDE'): ?>
                            <span class="badge badge-muted">Liquide</span>
                        <?php else: ?>
                            <span class="badge badge-success">Carte</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e((string) ($r['description'] ?? '—')) ?>
                        <?php if (!empty($r['is_custom_amount'])): ?>
                            <span class="badge badge-warning">Montant perso</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($r['product_key'] ?? '—')) ?></td>
                    <td><?= e((string) ($r['category'] ?? '—')) ?></td>
                    <td><?= e((string) ($r['quantity'] ?? 1)) ?></td>
                    <td><?= e(formatPrice($r['price_ttc'] ?? 0)) ?></td>
                    <td><?= e(formatPrice($r['cost_price'] ?? 0)) ?></td>
                    <td><?= e(formatPrice($r['profit'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="10" class="muted">Aucune vente ne correspond aux filtres.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
