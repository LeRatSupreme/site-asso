<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $articles
 */
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/articles/new')) ?>">+ Nouvel article</a>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead>
            <tr><th>Titre</th><th>Catégorie</th><th>Statut</th><th>Date</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach ($articles as $a):
                $id = (string) ($a['id'] ?? '');
                $slug = (string) ($a['slug'] ?? '');
                $dateRaw = (string) ($a['published_at'] ?? ($a['created_at'] ?? ''));
            ?>
                <tr>
                    <td>
                        <strong><?= e($a['title'] ?? '') ?></strong>
                        <br><span class="card-meta">/<?= e($slug) ?></span>
                    </td>
                    <td>
                        <?php if (!empty($a['category'])): ?>
                            <span class="badge badge-info"><?= e($a['category']) ?></span>
                        <?php else: ?>
                            <span class="card-meta">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($a['is_published'])): ?>
                            <span class="badge badge-success">Publié</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Brouillon</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(formatDate($dateRaw)) ?></td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/articles/' . rawurlencode($id))) ?>">✏️</a>
                        <?php if (!empty($a['is_published'])): ?>
                            <a class="btn btn-outline btn-sm" href="<?= e(url('/blog/' . rawurlencode($slug))) ?>" target="_blank" title="Voir">👁️</a>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/admin/articles/' . rawurlencode($id) . '/delete')) ?>"
                              data-confirm="Supprimer « <?= e($a['title'] ?? '') ?> » ?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm" title="Supprimer">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($articles)): ?>
                <tr><td colspan="5" class="card-meta">Aucun article.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
