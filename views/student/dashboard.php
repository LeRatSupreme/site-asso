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
    <span class="eyebrow"><?= e(t('dash.eyebrow.member')) ?></span>
    <h1 class="page-title"><?= e(tt('dash.hello', ['{name}' => $user['prenom'] ?? ''])) ?></h1>
</header>

<div class="grid grid-2">
    <section class="card surface glass">
        <h2 class="card-title"><?= e(t('dash.my_events')) ?></h2>
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
            <p class="card-meta"><?= e(t('dash.no_registrations')) ?></p>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('/eleve/inscriptions')) ?>"><?= e(t('dash.view_registrations')) ?></a>
    </section>
</div>

<section class="card surface glass">
    <h2 class="card-title"><?= e(t('dash.upcoming_aeic')) ?></h2>
    <?php if (!empty($upcoming)): ?>
        <div class="grid grid-3">
            <?php foreach ($upcoming as $event): ?>
                <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="card-meta"><?= e(t('dash.no_events')) ?></p>
    <?php endif; ?>
</section>

<section class="cta">
    <div class="container cta-inner">
        <a class="btn btn-outline btn-lg" href="<?= e(url('/eleve/profile')) ?>"><?= e(t('dash.my_profile')) ?></a>
    </div>
</section>
