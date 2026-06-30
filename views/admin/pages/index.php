<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $pages
 */
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/pages/new')) ?>">+ Nouvelle page</a>
</div>
<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Titre</th><th>Slug</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($pages as $p): ?>
                <tr>
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
                        <form method="post" action="<?= e(url('/admin/pages/' . rawurlencode((string) $p['slug']) . '/delete')) ?>" data-confirm="Supprimer cette page ?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
