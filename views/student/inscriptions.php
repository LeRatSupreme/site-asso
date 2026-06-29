<?php

declare(strict_types=1);

/**
 * Liste des inscriptions de l'utilisateur.
 *
 * @var list<array<string,mixed>> $registrations
 */
?>
<header class="dash-head">
    <span class="eyebrow">Événements</span>
    <h1 class="page-title">Mes inscriptions</h1>
</header>

<?php if (empty($registrations)): ?>
    <div class="empty-state surface glass">
        <p>Vous n'êtes inscrit·e à aucun événement pour le moment.</p>
        <a class="btn btn-primary" href="<?= e(url('/events')) ?>">Voir les événements</a>
    </div>
<?php else: ?>
    <div class="card surface glass table-wrap">
        <table class="table">
            <thead>
                <tr><th>Événement</th><th>Date</th><th>Lieu</th><th>Statut</th></tr>
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
                                <span class="badge badge-muted">Passé</span>
                            <?php else: ?>
                                <span class="badge badge-success">À venir</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
