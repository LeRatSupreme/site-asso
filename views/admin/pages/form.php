<?php

declare(strict_types=1);

/**
 * Formulaire d'édition d'une page CMS (admin › Pages).
 *
 * Deux colonnes : formulaire à gauche, APERÇU LIVE du rendu public à
 * droite. L'aperçu POSTe les valeurs EN COURS (non enregistrées) vers
 * /admin/pages/preview et injecte la réponse (rendu public complet,
 * gabarit /p/{slug}) dans une iframe via srcdoc — logique dans
 * assets/js/admin-page-preview.js, styles scoped ci-dessous.
 *
 * Écran étroit : l'aperçu passe SOUS le formulaire (une seule colonne,
 * aucune déformation horizontale). La soumission « Enregistrer » reste
 * un POST normal vers /admin/pages/save : l'aperçu ne modifie jamais
 * l'action ni la cible du formulaire.
 *
 * @var array<string,mixed> $page
 */
?>
<div class="page-edit" id="page-edit">
    <form class="card surface glass page-edit__form" id="page-edit-form" method="post" action="<?= e(url('/admin/pages/save')) ?>">
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

    <aside class="card surface glass page-edit__preview" aria-label="Aperçu du rendu public de la page">
        <div class="page-edit__head">
            <strong class="page-edit__title">Aperçu public</strong>
            <span class="page-edit__status" id="page-preview-status" role="status" aria-live="polite">Chargement de l'aperçu…</span>
            <button type="button" class="btn btn-outline btn-sm page-edit__refresh" id="page-preview-refresh">Actualiser l'aperçu</button>
        </div>
        <div class="page-edit__frame">
            <iframe id="page-preview-frame" title="Aperçu du rendu public de la page (modifications non enregistrées)"></iframe>
        </div>
        <p class="muted page-edit__hint">Rendu public de vos modifications en cours — rien n'est enregistré avant « Enregistrer ».</p>
        <p class="alert alert-danger page-edit__error" id="page-preview-error" role="alert" hidden></p>
    </aside>
</div>

<style>
/* Aperçu live des pages CMS — styles SCOPE à cette vue uniquement
   (admin.css / base.css ne sont pas modifiés ; classes préfixées
   « page-edit__ » pour éviter toute collision). */
.page-edit {
    display: grid;
    grid-template-columns: minmax(0, 7fr) minmax(0, 5fr);
    gap: 1.25rem;
    align-items: start;
}
/* Leçon déformation horizontale : min-width 0 sur chaque colonne du
   grid + césure douce — les contenus longs (URL, mots collés) ne
   peuvent plus étirer la mise en page. */
.page-edit > * { min-width: 0; overflow-wrap: break-word; }

/* Colonne d'aperçu : suit le défilement pendant l'édition du
   formulaire (colonne gauche plus haute). */
.page-edit__preview { position: sticky; top: 1rem; }

.page-edit__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.75rem;
}
.page-edit__title { font-size: 1rem; }
.page-edit__status { font-size: 0.8rem; color: var(--muted); }
.page-edit__status[data-state="loading"] { color: var(--primary); }
.page-edit__status[data-state="error"] { color: #fca5a5; }
.page-edit__refresh { margin-left: auto; }

.page-edit__frame {
    border: 1px solid var(--border);
    border-radius: 0.6rem;
    overflow: hidden;
    background: var(--card);
    transition: opacity 0.15s ease;
}
.page-edit__frame iframe {
    display: block;
    width: 100%;
    height: clamp(420px, 72vh, 880px);
    border: 0;
    background: var(--card);
}
/* Indicateur discret de chargement : léger voile sur l'aperçu. */
.page-edit.is-loading .page-edit__frame { opacity: 0.55; }
.page-edit__hint { font-size: 0.82rem; margin: 0; }
.page-edit__error { margin: 0; }

/* Écran étroit : une seule colonne, l'aperçu passe SOUS le formulaire. */
@media (max-width: 1080px) {
    .page-edit { grid-template-columns: 1fr; }
    .page-edit__preview { position: static; }
    .page-edit__frame iframe { height: 70vh; }
}

/* Leçon zoom iOS : aucun champ de saisie sous 16 px sur mobile. */
@media (max-width: 960px) {
    .page-edit input[type="text"],
    .page-edit textarea {
        font-size: 16px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .page-edit__frame { transition: none; }
}
</style>

<script>window.AEIC_PAGE_PREVIEW = { endpoint: <?= json_encode(url('/admin/pages/preview')) ?> };</script>
<script src="<?= e(rootAssetVersioned('/assets/js/admin-page-preview.js')) ?>" defer></script>
