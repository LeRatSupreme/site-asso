<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $members
 */
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/team/new')) ?>">+ Nouveau membre</a>
</div>
<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Nom</th><th>Rôle</th><th>Pôle</th><th>Ordre</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td>
                        <strong><?= e(trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''))) ?></strong>
                        <?= !empty($m['is_highlight']) ? '<span class="badge badge-info">bureau</span>' : '' ?>
                    </td>
                    <td><?= e($m['role'] ?? '') ?></td>
                    <td><?= e($m['pole'] ?? '') ?></td>
                    <td><?= e((string) ($m['order'] ?? 0)) ?></td>
                    <td>
                        <?php if (!empty($m['is_active'])): ?>
                            <span class="badge badge-success">Actif</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Masqué</span>
                        <?php endif; ?>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/team/' . rawurlencode((string) $m['id']))) ?>">Éditer</a>
                        <form method="post" action="<?= e(url('/admin/team/' . rawurlencode((string) $m['id']) . '/delete')) ?>" onsubmit="return confirm('Supprimer ce membre ?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
