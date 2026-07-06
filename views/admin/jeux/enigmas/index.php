<?php

declare(strict_types=1);

/**
 * Liste des énigmes (admin).
 *
 * @var string $title
 * @var list<array<string,mixed>> $enigmas
 */
?>
<div class="admin-actions">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/jeux')) ?>">← Retour</a>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/jeux/enigmes/new')) ?>">+ Nouvelle énigme</a>
</div>

<?php if ($enigmas === []): ?>
    <div class="card surface glass" style="text-align:center;padding:2rem;color:var(--muted);">
        Aucune énigme. Clique sur « + Nouvelle énigme ».
    </div>
<?php else: ?>
    <div class="card surface glass table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Question (FR)</th>
                    <th>Réponse</th>
                    <th>Actif</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enigmas as $en): ?>
                    <tr>
                        <td class="num"><?= (int) $en['id'] ?></td>
                        <td style="max-width:420px;"><?= e(mb_strimwidth((string) $en['question_fr'], 0, 120, '…')) ?></td>
                        <td><code style="background:rgba(255,255,255,0.06);padding:0.15rem 0.4rem;border-radius:0.3rem;"><?= e((string) $en['answer']) ?></code></td>
                        <td><?= ((int) $en['is_active']) === 1
                                ? '<span class="badge badge-success">Oui</span>'
                                : '<span class="badge badge-muted">Non</span>' ?></td>
                        <td class="row-actions">
                            <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/jeux/enigmes/' . (int) $en['id'])) ?>" title="Modifier">✏️</a>
                            <form method="post" action="<?= e(url('/admin/jeux/enigmes/' . (int) $en['id'] . '/delete')) ?>" class="inline-form"
                                  data-confirm="Supprimer cette énigme ?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger btn-sm" title="Supprimer">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
