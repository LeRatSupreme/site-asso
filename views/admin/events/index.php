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
            <?php foreach ($events as $ev):
                $slug = (string) ($ev['slug'] ?? '');
                $regCount = \App\Models\Event::registrationsCount((string) $ev['id']);
            ?>
                <tr>
                    <td>
                        <strong><?= e($ev['title'] ?? '') ?></strong>
                        <?php if (!empty($ev['category'])): ?>
                            <span class="badge badge-info" style="font-size:0.68rem;vertical-align:middle;"><?= e($ev['category']) ?></span>
                        <?php endif; ?>
                        <br><span class="card-meta">/<?= e($slug) ?></span>
                    </td>
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
                        <a href="<?= e(url('/admin/events/' . rawurlencode($slug) . '/registrations')) ?>">
                            <?= e((string) $regCount) ?>
                        </a>
                    </td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/events/' . rawurlencode($slug))) ?>">✏️</a>
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/events/' . rawurlencode($slug) . '/registrations')) ?>" title="Inscrits">📋</a>
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/events/' . rawurlencode($slug) . '/checkin')) ?>" title="Check-in">📱</a>
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/events/' . rawurlencode($slug))) ?>" target="_blank" title="Voir">👁️</a>
                        <form method="post" action="<?= e(url('/admin/events/' . rawurlencode($slug) . '/delete')) ?>"
                              data-confirm="Supprimer « <?= e($ev['title'] ?? '') ?> » ?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm" title="Supprimer">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
