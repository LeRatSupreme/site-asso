<?php

declare(strict_types=1);

/**
 * Tableau de bord admin.
 *
 * @var int $usersCount
 * @var int $eventsCount
 * @var int $ordersCount
 * @var float $revenue
 * @var list<array<string,mixed>> $recentAudit
 * @var list<array<string,mixed>> $recentOrders
 */
?>
<div class="grid grid-4 stat-cards">
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e((string) $usersCount) ?></span>
        <span class="stat-label">Membres actifs</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e((string) $eventsCount) ?></span>
        <span class="stat-label">Événements publiés</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e((string) $ordersCount) ?></span>
        <span class="stat-label">Commandes cafétéria</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(formatPrice($revenue)) ?></span>
        <span class="stat-label">Chiffre d'affaires</span>
    </div>
</div>

<div class="grid grid-2">
    <section class="card surface glass">
        <h2 class="card-title">Dernières commandes</h2>
        <?php if (!empty($recentOrders)): ?>
            <ul class="list-rows">
                <?php foreach ($recentOrders as $o): ?>
                    <li>
                        <span><strong><?= e(formatPrice($o['total'] ?? 0)) ?></strong> · <?= e(trim(($o['prenom'] ?? '') . ' ' . ($o['nom'] ?? 'Client')) ) ?></span>
                        <span class="badge badge-muted"><?= e((string) $o['status']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="card-meta">Aucune commande.</p>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/cafeteria/commandes')) ?>">Voir toutes les commandes</a>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Journal d'audit</h2>
        <?php if (!empty($recentAudit)): ?>
            <ul class="list-rows audit-list">
                <?php foreach ($recentAudit as $a): ?>
                    <li>
                        <code><?= e((string) $a['action']) ?></code>
                        <span class="card-meta"><?= e(trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? 'système'))) ?></span>
                        <span class="card-meta"><?= e(formatDateTime((string) $a['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="card-meta">Aucune action enregistrée.</p>
        <?php endif; ?>
    </section>
</div>
