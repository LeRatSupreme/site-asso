<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $products
 */
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/cafeteria/new')) ?>">+ Nouveau produit</a>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Produit</th><th>Catégorie</th><th>Prix</th><th>Stock</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><strong><?= e($p['name'] ?? '') ?></strong></td>
                    <td><?= e($p['category_name'] ?? '—') ?></td>
                    <td><?= e(formatPrice($p['price'] ?? 0)) ?></td>
                    <td><?= e((string) ($p['stock'] ?? 0)) ?></td>
                    <td>
                        <?php if (!empty($p['is_available']) && !empty($p['is_active'])): ?>
                            <span class="badge badge-success">Dispo</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Masqué</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/cafeteria/' . rawurlencode((string) $p['id']) . '/edit')) ?>">Éditer</a>
                        <form method="post" action="<?= e(url('/admin/cafeteria/' . rawurlencode((string) $p['id']) . '/delete')) ?>" data-confirm="Supprimer ce produit ?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
