<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $events
 */
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/events/new')) ?>">+ Nouvel événement</a>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead>
            <tr><th>Titre</th><th>Date</th><th>Lieu</th><th>Statut</th><th>Inscrits</th><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td><strong><?= e($ev['title'] ?? '') ?></strong><br><span class="card-meta">/<?= e($ev['slug'] ?? '') ?></span></td>
                    <td><?= e(formatDateTime((string) ($ev['date'] ?? ''))) ?></td>
                    <td><?= e($ev['location'] ?? '') ?></td>
                    <td>
                        <?php if (!empty($ev['is_published'])): ?>
                            <span class="badge badge-success">Publié</span>
                        <?php else: ?>
                            <span class="badge badge-muted">Brouillon</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= e(url('/admin/events/' . rawurlencode((string) $ev['slug']) . '/registrations')) ?>">
                            <?= e((string) \App\Models\Event::registrationsCount((string) $ev['id'])) ?>
                        </a>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/events/' . rawurlencode((string) $ev['slug']))) ?>">Éditer</a>
                        <form method="post" action="<?= e(url('/admin/events/' . rawurlencode((string) $ev['slug']) . '/delete')) ?>"
                              onsubmit="return confirm('Supprimer cet événement ?');">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
