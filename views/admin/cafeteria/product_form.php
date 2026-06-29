<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $product
 * @var list<array<string,mixed>> $categories
 */
?>
<form class="card surface glass" method="post" action="<?= e(url('/admin/cafeteria/save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($product['id'] ?? '')) ?>">

    <div class="field">
        <label for="name">Nom</label>
        <input type="text" id="name" name="name" value="<?= e($product['name'] ?? '') ?>" required>
    </div>

    <div class="field">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3"><?= e($product['description'] ?? '') ?></textarea>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="price">Prix (€)</label>
            <input type="text" id="price" name="price" value="<?= e($product['price'] ?? '0') ?>" required>
        </div>
        <div class="field">
            <label for="stock">Stock</label>
            <input type="number" id="stock" name="stock" value="<?= e((string) ($product['stock'] ?? 0)) ?>">
        </div>
        <div class="field">
            <label for="category_id">Catégorie</label>
            <select id="category_id" name="category_id">
                <option value="">—</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e((string) $c['id']) ?>" <?= ($product['category_id'] ?? '') === $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['name'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="field">
        <label for="image">Image (URL ou chemin)</label>
        <input type="text" id="image" name="image" value="<?= e($product['image'] ?? '') ?>">
    </div>

    <div class="field field-row">
        <label><input type="checkbox" name="is_available" value="1" <?= !empty($product['is_available']) ? 'checked' : '' ?>> Disponible à la vente</label>
        <label><input type="checkbox" name="is_active" value="1" <?= !empty($product['is_active']) ? 'checked' : '' ?>> Actif</label>
    </div>

    <div class="admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/cafeteria')) ?>">Annuler</a>
    </div>
</form>
