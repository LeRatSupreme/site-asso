<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $categories
 * @var list<string> $mappingMissing
 */
?>
<section class="card surface glass">
    <h2 class="card-title">Ajouter / modifier une catégorie</h2>
    <form method="post" action="<?= e(url('/admin/cafeteria/categories/save')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="" placeholder="id (vide = création)">
        <input type="text" name="name" placeholder="Nom de la catégorie" required>
        <input type="number" name="order" placeholder="Ordre" value="0">
        <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1" checked> Active</label>
        <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
    </form>
</section>

<section class="card surface glass">
    <h2 class="card-title">🔗 Catégories du mapping des libellés non présentes ici</h2>
    <?php if ($mappingMissing === []): ?>
        <p class="muted">Toutes les catégories du mapping existent ici.</p>
    <?php else: ?>
        <p class="admin-actions">
            <form method="post" action="<?= e(url('/admin/cafeteria/categories/sync-mapping')) ?>" data-preserve-scroll>
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary btn-sm">Tout ajouter (<?= count($mappingMissing) ?>)</button>
            </form>
            <span class="muted">Crée chaque catégorie utilisée par le mapping des libellés (compta) absente de la carte.</span>
        </p>
        <ul>
            <?php foreach ($mappingMissing as $name): ?>
                <li>
                    <form method="post" action="<?= e(url('/admin/cafeteria/categories/add-mapping')) ?>" class="inline-form" data-preserve-scroll>
                        <?= csrf_field() ?>
                        <input type="hidden" name="name" value="<?= e($name) ?>">
                        <strong><?= e($name) ?></strong>
                        <button type="submit" class="btn btn-sm">Ajouter</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Nom</th><th>Ordre</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><strong><?= e($c['name'] ?? '') ?></strong></td>
                    <td><?= e((string) ($c['order'] ?? 0)) ?></td>
                    <td>
                        <?php if (!empty($c['is_active'])): ?>
                            <span class="badge badge-success">Active</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?= e(url('/admin/cafeteria/categories/' . rawurlencode((string) $c['id']) . '/delete')) ?>" data-confirm="Supprimer cette catégorie ?" data-preserve-scroll>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
