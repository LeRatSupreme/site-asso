<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $promotions
 */
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/promotions/new')) ?>">+ Nouvelle promotion</a>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Titre</th>
                <th>Badge</th>
                <th>Prix</th>
                <th>Période</th>
                <th>Statut</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($promotions)): ?>
                <tr><td colspan="6" class="card-meta">Aucune promotion pour le moment.</td></tr>
            <?php endif; ?>
            <?php foreach ($promotions as $promo): ?>
                <?php
                $promoId = (string) $promo['id'];
                $old     = ($promo['old_price'] ?? null) !== null && $promo['old_price'] !== '';
                $isActive = !empty($promo['is_active']);
                $endsAt  = (string) ($promo['ends_at'] ?? '');
                ?>
                <tr>
                    <td>
                        <strong><?= e($promo['title'] ?? '') ?></strong>
                        <?php if (!empty($promo['product_key'])): ?>
                            <br><span class="card-meta"><?= e($promo['product_key']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($promo['badge'])): ?>
                            <span class="badge badge-warning"><?= e($promo['badge']) ?></span>
                        <?php else: ?>
                            <span class="card-meta">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($old): ?>
                            <span class="card-meta" style="text-decoration: line-through;"><?= e(formatPrice($promo['old_price'])) ?></span>
                        <?php endif; ?>
                        <strong style="color: var(--primary);"><?= e(formatPrice($promo['new_price'] ?? 0)) ?></strong>
                    </td>
                    <td>
                        <span class="card-meta">
                            <?= e(formatDate((string) ($promo['starts_at'] ?? ''))) ?>
                            <?php if ($endsAt !== '' && $endsAt !== '0000-00-00 00:00:00'): ?>
                                → <?= e(formatDate($endsAt)) ?>
                            <?php else: ?>
                                → ∞
                            <?php endif; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($isActive): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/promotions/' . rawurlencode($promoId))) ?>">Éditer</a>
                        <form method="post" action="<?= e(url('/admin/promotions/' . rawurlencode($promoId) . '/delete')) ?>"
                              data-confirm="Supprimer cette promotion ?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
