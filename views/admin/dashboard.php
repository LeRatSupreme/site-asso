<?php

declare(strict_types=1);

use App\Core\Auth;

/**
 * Tableau de bord admin.
 *
 * @var int $usersCount
 * @var int $eventsCount
 * @var float $monthCa
 * @var float $monthProfit
 * @var bool $showFinance
 * @var list<array<string,mixed>> $recentAudit
 */
$showFinance = $showFinance ?? false;
$gridClass = $showFinance ? 'grid grid-4' : 'grid grid-2';
?>
<div class="<?= e($gridClass) ?> stat-cards">
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e((string) $usersCount) ?></span>
        <span class="stat-label">Membres actifs</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e((string) $eventsCount) ?></span>
        <span class="stat-label">Événements publiés</span>
    </div>
    <?php if ($showFinance): ?>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(formatPrice($monthCa)) ?></span>
        <span class="stat-label">CA ce mois</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value is-positive"><?= e(formatPrice($monthProfit)) ?></span>
        <span class="stat-label">Bénéfice ce mois</span>
    </div>
    <?php endif; ?>
</div>

<?php if (Auth::isAdmin()): ?>
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
<?php endif; ?>
