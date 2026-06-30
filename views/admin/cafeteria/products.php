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
        <thead><tr>
            <th>Image</th>
            <th>Produit</th>
            <th>Description</th>
            <th>Catégorie</th>
            <th>Prix</th>
            <th>Stock</th>
            <th>Statut</th>
            <th>Actions</th>
        </tr></thead>
        <tbody>
            <?php foreach ($products as $p):
                $imgUrl = (string) ($p['image'] ?? '');
                $imgFull = $imgUrl !== '' ? (is_absolute_url($imgUrl) ? $imgUrl : asset(ltrim($imgUrl, '/'))) : '';
            ?>
                <tr>
                    <td>
                        <?php if ($imgFull !== ''): ?>
                            <img src="<?= e($imgFull) ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;">
                        <?php else: ?>
                            <span style="font-size:1.4rem;"><?= e(\product_emoji((string) ($p['name'] ?? ''))) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e($p['name'] ?? '') ?></strong>
                    </td>
                    <td>
                        <?php $desc = trim((string) ($p['description'] ?? '')); ?>
                        <?php if ($desc !== ''): ?>
                            <span class="muted" style="font-size:0.82rem;max-width:200px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($desc) ?>"><?= e($desc) ?></span>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($p['category_name'] ?? '—') ?></td>
                    <td><strong><?= e(formatPrice($p['price'] ?? 0)) ?></strong></td>
                    <td><?= e((string) ($p['stock'] ?? 0)) ?></td>
                    <td>
                        <?php if (!empty($p['is_available']) && !empty($p['is_active'])): ?>
                            <span class="badge badge-success">Dispo</span>
                        <?php elseif (!empty($p['is_active'])): ?>
                            <span class="badge badge-warning">Indispo</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Masqué</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/cafeteria/' . rawurlencode((string) $p['id']) . '/edit')) ?>">✏️</a>
                        <form method="post" action="<?= e(url('/admin/cafeteria/' . rawurlencode((string) $p['id']) . '/delete')) ?>" data-confirm="Supprimer ce produit ?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
