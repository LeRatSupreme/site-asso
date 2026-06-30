<?php

declare(strict_types=1);

use App\Models\Poll;

/**
 * @var list<array<string,mixed>> $polls
 */
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/sondages/new')) ?>">+ Nouveau sondage</a>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead>
            <tr><th>Titre</th><th>Statut</th><th>Votes</th><th>Créé le</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php if (empty($polls)): ?>
                <tr><td colspan="5" class="card-meta">Aucun sondage pour le moment.</td></tr>
            <?php endif; ?>
            <?php foreach ($polls as $poll): ?>
                <?php
                $pollId   = (string) $poll['id'];
                $isClosed = Poll::isClosed($poll);
                $voters   = Poll::totalVoters($pollId);
                ?>
                <tr>
                    <td><strong><?= e($poll['title'] ?? '') ?></strong><br><span class="card-meta">/<?= e($poll['slug'] ?? '') ?></span></td>
                    <td>
                        <?php if (!empty($poll['is_published'])): ?>
                            <?php if ($isClosed): ?>
                                <span class="badge badge-muted">Fermé</span>
                            <?php else: ?>
                                <span class="badge badge-success">Publié</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-muted">Brouillon</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) $voters) ?></td>
                    <td><?= e(formatDate((string) ($poll['created_at'] ?? ''))) ?></td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/sondages/' . rawurlencode($pollId))) ?>">Éditer</a>
                        <?php if (!empty($poll['is_published'])): ?>
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('/sondages/' . rawurlencode((string) $poll['slug']))) ?>" target="_blank">Voir</a>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('/admin/sondages/' . rawurlencode($pollId) . '/delete')) ?>"
                              onsubmit="return confirm('Supprimer ce sondage ?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
