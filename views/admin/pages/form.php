<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $page
 */
?>
<form class="card surface glass" method="post" action="<?= e(url('/admin/pages/save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($page['id'] ?? '')) ?>">

    <div class="field-row">
        <div class="field">
            <label for="title">Titre</label>
            <input type="text" id="title" name="title" value="<?= e($page['title'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="slug">Slug</label>
            <input type="text" id="slug" name="slug" value="<?= e($page['slug'] ?? '') ?>" required>
        </div>
    </div>

    <div class="field">
        <label for="content">Contenu (HTML)</label>
        <textarea id="content" name="content" rows="14"><?= e($page['content'] ?? '') ?></textarea>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="meta_title">Meta titre (SEO)</label>
            <input type="text" id="meta_title" name="meta_title" value="<?= e($page['meta_title'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="meta_description">Meta description (SEO)</label>
            <input type="text" id="meta_description" name="meta_description" value="<?= e($page['meta_description'] ?? '') ?>">
        </div>
    </div>

    <div class="field">
        <label><input type="checkbox" name="is_published" value="1" <?= !empty($page['is_published']) ? 'checked' : '' ?>> Publiée</label>
    </div>

    <div class="admin-actions">
        <button type="submit" class="btn btn-primary">Enregistrer</button>
        <a class="btn btn-ghost" href="<?= e(url('/admin/pages')) ?>">Annuler</a>
    </div>
</form>
