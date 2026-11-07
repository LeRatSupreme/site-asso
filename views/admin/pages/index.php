<?php

declare(strict_types=1);

use App\Core\Permissions;

/**
 * Liste des pages CMS, dans l'ordre canonique (position). L'ordre se
 * change par glisser-déposer DIRECT des lignes (admin-pages-order.js,
 * même pattern « toujours actif » que la sidebar : seuil 5 px, aucune
 * poignée, aucun mode) — auto-save POST /admin/pages/reorder.
 *
 * @var list<array<string,mixed>> $pages
 */
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/pages/new')) ?>">+ Nouvelle page</a>
</div>
<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Titre</th><th>Slug</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody id="pages-tbody">
            <?php foreach ($pages as $p): ?>
                <tr data-id="<?= e((string) ($p['id'] ?? '')) ?>">
                    <td><strong><?= e($p['title'] ?? '') ?></strong></td>
                    <td><code>/p/<?= e($p['slug'] ?? '') ?></code></td>
                    <td>
                        <?php if (!empty($p['is_published'])): ?>
                            <span class="badge badge-success">Publiée</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Brouillon</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/pages/' . rawurlencode((string) $p['slug']))) ?>">Éditer</a>
                        <form method="post" action="<?= e(url('/admin/pages/' . rawurlencode((string) $p['slug']) . '/delete')) ?>" data-confirm="Supprimer cette page ?" data-preserve-scroll>
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php /* Réordonnancement par drag & drop (admin-pages-order.js). Config
   + styles posés seulement hors mode lecture seule : le POST
   /admin/pages/reorder est refusé au rôle concerné (403), le drag
   n'a rien à faire là — même gating que la sidebar dans le layout. */ ?>
<?php if (!Permissions::isReadOnly()): ?>
<style>
/* Drag & drop des lignes — styles SCOPE à cette vue uniquement
   (admin.css / base.css ne sont pas modifiés). La silhouette réutilise
   .drag-ghost (global : position:fixed, ombre, snap-back et
   prefers-reduced-motion déjà gérés) ; ici seulement le propre des
   lignes d'un tableau à bordures fusionnées. */
#pages-tbody tr[data-id] {
    /* Tactile : le geste VERTICAL sur une ligne appartient au drag (le
       doigt déplace la ligne, il ne fait pas défiler la page), le pan
       HORIZONTAL reste au navigateur (défilement de la table en
       mobile — il émet pointercancel → restitution propre). */
    touch-action: pan-x;
}

/* Ligne saisie : reste en place, estompée (la silhouette flotte au
   pointeur avec son ombre de légère élévation, cf. .drag-ghost). */
#pages-tbody tr.dragging { opacity: 0.45; }

/* Zone de dépôt soulignée : lignes fines par box-shadow INSET sur les
   cellules — un box-shadow sur <tr> n'est pas fiable avec
   border-collapse:collapse ; aucun décalage de mise en page. */
#pages-tbody tr.drag-over-above td { box-shadow: inset 0 2px 0 0 var(--primary); }
#pages-tbody tr.drag-over-below td { box-shadow: inset 0 -2px 0 0 var(--primary); }

/* Pendant le drag seulement (classe posée/retirée par le JS, jamais au
   simple survol) : curseur grabbing partout, pas de sélection de
   texte ni de bulle tactile. */
html.aeic-pages-drag,
html.aeic-pages-drag * {
    cursor: grabbing !important;
    user-select: none;
    -webkit-user-select: none;
    -webkit-touch-callout: none;
}

/* Silhouette (clone <tr> posé dans <body> par le JS) : rendu en table
   autonome, largeur fixée au rect d'origine — les cellules s'alignent
   par le contenu, pas de déformation (desktop comme mobile). */
.pages-ghost-row { display: table; }
</style>
<script>window.AEIC_PAGES_ORDER = { csrf: <?= json_encode(csrf_token()) ?>, endpoint: <?= json_encode(url('/admin/pages/reorder')) ?> };</script>
<script src="<?= e(rootAssetVersioned('/assets/js/admin-pages-order.js')) ?>" defer></script>
<?php endif; ?>
