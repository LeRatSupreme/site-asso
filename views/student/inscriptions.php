<?php

declare(strict_types=1);

/**
 * Liste des inscriptions de l'utilisateur.
 *
 * @var list<array<string,mixed>> $registrations
 */
?>
<header class="dash-head">
    <span class="eyebrow"><?= e(t('inscriptions.eyebrow')) ?></span>
    <h1 class="page-title"><?= e(t('inscriptions.title')) ?></h1>
</header>

<?php if (empty($registrations)): ?>
    <div class="empty-state surface glass">
        <p><?= e(t('inscriptions.empty')) ?></p>
        <a class="btn btn-primary" href="<?= e(url('/events')) ?>"><?= e(t('inscriptions.see_events')) ?></a>
    </div>
<?php else: ?>
    <div class="card surface glass table-wrap">
        <table class="table">
            <thead>
                <tr><th><?= e(t('inscriptions.col.event')) ?></th><th><?= e(t('inscriptions.col.date')) ?></th><th><?= e(t('inscriptions.col.location')) ?></th><th><?= e(t('inscriptions.col.status')) ?></th></tr>
            </thead>
            <tbody>
                <?php foreach ($registrations as $r): ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('/events/' . rawurlencode((string) $r['slug']))) ?>">
                                <strong><?= e($r['title'] ?? '') ?></strong>
                            </a>
                        </td>
                        <td><?= e(formatDateTime((string) ($r['date'] ?? ''))) ?></td>
                        <td><?= e($r['location'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($r['is_past'])): ?>
                                <span class="badge badge-muted"><?= e(t('inscriptions.status.past')) ?></span>
                            <?php else: ?>
                                <span class="badge badge-success"><?= e(t('inscriptions.status.upcoming')) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
