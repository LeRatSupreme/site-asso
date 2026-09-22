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
    <div class="mapping-sync-head">
        <div>
            <h2 class="card-title">🔗 Catégories du mapping à synchroniser</h2>
            <p class="mapping-sync-sub muted">Utilisées dans le mapping des libellés (compta) mais absentes de la carte.</p>
        </div>
        <?php if ($mappingMissing !== []): ?>
            <form method="post" action="<?= e(url('/admin/cafeteria/categories/sync-mapping')) ?>" data-preserve-scroll>
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary btn-sm">Tout ajouter (<?= count($mappingMissing) ?>)</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($mappingMissing === []): ?>
        <p class="muted">Toutes les catégories du mapping existent ici. 👍</p>
    <?php else: ?>
        <ul class="mapping-sync-list">
            <?php foreach ($mappingMissing as $name): ?>
                <li>
                    <form method="post" action="<?= e(url('/admin/cafeteria/categories/add-mapping')) ?>" class="mapping-sync-row" data-preserve-scroll>
                        <?= csrf_field() ?>
                        <input type="hidden" name="name" value="<?= e($name) ?>">
                        <span class="mapping-sync-name">🏷️ <?= e($name) ?></span>
                        <span class="mapping-sync-spacer"></span>
                        <button type="submit" class="btn btn-outline btn-sm">+ Ajouter</button>
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

<style>
.mapping-sync-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.7rem;
    flex-wrap: wrap;
    margin-bottom: 0.4rem;
}
.mapping-sync-head h2 { margin-bottom: 0.15rem; }
.mapping-sync-sub { margin: 0; font-size: 0.8rem; }
.mapping-sync-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 0.45rem;
}
.mapping-sync-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.6rem;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: rgba(255,255,255,0.03);
    height: 100%;
}
.mapping-sync-row:hover { border-color: var(--primary); }
.mapping-sync-name {
    font-weight: 600;
    font-size: 0.84rem;
    min-width: 0;
    overflow-wrap: anywhere;
}
.mapping-sync-spacer { flex: 1; }
</style>
