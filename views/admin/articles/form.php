<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $article
 */
$isNew = empty($article['id']);
?>
<form method="post" action="<?= e(url('/admin/articles/save')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e((string) ($article['id'] ?? '')) ?>">

    <section class="card surface glass form-section">
        <h2 class="form-section-title">📝 Contenu</h2>
        <div class="field-row">
            <div class="field">
                <label for="title">Titre</label>
                <input type="text" id="title" name="title" value="<?= e($article['title'] ?? '') ?>" required placeholder="Ex: Récap de la Nuit de l'Info">
            </div>
            <div class="field">
                <label for="slug">URL (slug)</label>
                <input type="text" id="slug" name="slug" value="<?= e($article['slug'] ?? '') ?>" placeholder="laisser vide = auto depuis le titre">
            </div>
        </div>
        <div class="field">
            <label for="category">Catégorie</label>
            <input type="text" id="category" name="category" list="article-categories" value="<?= e($article['category'] ?? '') ?>" placeholder="Choisir une catégorie…">
            <datalist id="article-categories">
                <option value="Actualité">
                <option value="Événement">
                <option value="Tutoriel">
                <option value="Vie associative">
                <option value="Annonce">
                <option value="Couleurs">
            </datalist>
        </div>
        <div class="field">
            <label for="excerpt">Extrait (carte + SEO)</label>
            <textarea id="excerpt" name="excerpt" rows="2" maxlength="500" placeholder="Résumé court affiché sur les cartes."><?= e($article['excerpt'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label for="content">Contenu (HTML)</label>
            <textarea id="content" name="content" rows="14" placeholder="<p>Contenu de l'article…</p>"><?= e($article['content'] ?? '') ?></textarea>
        </div>
    </section>

    <section class="card surface glass form-section">
        <h2 class="form-section-title">🖼️ Image & Publication</h2>
        <div class="field">
            <label for="image">Image de couverture (URL)</label>
            <input type="text" id="image" name="image" value="<?= e($article['image'] ?? '') ?>" placeholder="/assets/uploads/...">
        </div>
        <div class="field">
            <label class="toggle-switch">
                <input type="checkbox" name="is_published" value="1" <?= !empty($article['is_published']) ? 'checked' : '' ?>
                       onchange="this.value = this.checked ? '1' : '0';">
                <span class="toggle-slider-inline"></span>
                <span>✅ Publier</span>
            </label>
        </div>
    </section>

    <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('/admin/articles')) ?>">← Retour</a>
        <button type="submit" class="btn btn-primary btn-lg"><?= $isNew ? '📰 Créer l\'article' : '💾 Enregistrer' ?></button>
    </div>
</form>

<style>
.form-section { margin-bottom: 1.25rem; padding: 1.5rem 1.6rem; }
.form-section-title {
    font-size: 1rem; font-weight: 700; margin: 0 0 1.25rem;
    padding-bottom: 0.6rem; border-bottom: 1px solid var(--border);
    color: var(--primary);
}
.form-actions {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; padding: 1rem 0;
}
.toggle-slider-inline {
    display: inline-block;
    width: 40px; height: 22px;
    background: rgba(255,255,255,.1);
    border-radius: 999px;
    position: relative;
    transition: background .2s;
    cursor: pointer;
}
.toggle-switch input { position: absolute; opacity: 0; pointer-events: none; }
.toggle-switch input:checked ~ .toggle-slider-inline {
    background: rgba(72,189,211,.4);
}
.toggle-slider-inline::after {
    content: ''; position: absolute;
    width: 16px; height: 16px;
    border-radius: 50%; background: #fff;
    top: 3px; left: 3px;
    transition: transform .2s;
}
.toggle-switch input:checked ~ .toggle-slider-inline::after {
    transform: translateX(18px);
    background: var(--primary);
}
.toggle-switch { display: inline-flex; align-items: center; gap: .6rem; cursor: pointer; user-select: none; }
</style>
