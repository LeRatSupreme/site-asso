<?php

declare(strict_types=1);

/**
 * Tableau de bord élève.
 *
 * @var array<string,mixed> $user
 * @var list<array<string,mixed>> $upcoming
 * @var list<array<string,mixed>> $myUpcoming
 * @var list<array<string,mixed>> $recentOrders
 */
?>
<header class="dash-head">
    <span class="eyebrow">Espace membre</span>
    <h1 class="page-title">Bonjour, <?= e($user['prenom'] ?? '') ?> 👋</h1>
</header>

<div class="grid grid-2">
    <section class="card surface glass">
        <h2 class="card-title">Mes prochains événements</h2>
        <?php if (!empty($myUpcoming)): ?>
            <ul class="list-rows">
                <?php foreach ($myUpcoming as $ev): ?>
                    <li>
                        <a href="<?= e(url('/events/' . rawurlencode((string) $ev['slug']))) ?>">
                            <strong><?= e($ev['title'] ?? '') ?></strong>
                        </a>
                        <span class="card-meta"><?= e(formatDateTime((string) ($ev['date'] ?? ''))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="card-meta">Aucune inscription à venir.</p>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/eleve/inscriptions')) ?>">Voir mes inscriptions</a>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Mes dernières commandes</h2>
        <?php if (!empty($recentOrders)): ?>
            <ul class="list-rows">
                <?php foreach ($recentOrders as $o): ?>
                    <li>
                        <strong><?= e(formatPrice($o['total'] ?? 0)) ?></strong>
                        <?php $statusBadge = \App\Models\OrderWorkflow::isTerminal((string) $o['status']) ? 'badge-muted' : 'badge-warning'; ?>
                        <span class="badge <?= e($statusBadge) ?>"><?= e((string) $o['status']) ?></span>
                        <span class="card-meta"><?= e(formatDateTime((string) ($o['created_at'] ?? ''))) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="card-meta">Aucune commande pour le moment.</p>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/eleve/commandes')) ?>">Voir mes commandes</a>
    </section>
</div>

<section class="card surface glass">
    <h2 class="card-title">Prochains événements AEIC</h2>
    <?php if (!empty($upcoming)): ?>
        <div class="grid grid-3">
            <?php foreach ($upcoming as $event): ?>
                <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="card-meta">Aucun événement annoncé.</p>
    <?php endif; ?>
</section>

<section class="cta">
    <div class="container cta-inner">
        <a class="btn btn-primary btn-lg" href="<?= e(url('/eleve/cafeteria')) ?>">Commander à la cafétéria</a>
        <a class="btn btn-outline btn-lg" href="<?= e(url('/eleve/profile')) ?>">Mon profil</a>
    </div>
</section>
