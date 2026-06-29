<?php

declare(strict_types=1);

/**
 * Agenda des événements.
 *
 * @var list<array<string,mixed>> $upcoming
 * @var list<array<string,mixed>> $past
 * @var int $countUpcoming
 * @var int $countPast
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Agenda AEIC</span>
        <h1 class="page-title">Les prochains rendez-vous.</h1>
        <p class="page-lead">
            <?= e((string) $countUpcoming) ?> à venir · <?= e((string) $countPast) ?> passés
        </p>
    </div>
</header>

<section class="section">
    <div class="container">
        <div class="section-head">
            <h2 class="section-title">À venir</h2>
        </div>
        <?php if (empty($upcoming)): ?>
            <div class="empty-state surface glass">
                <p>Aucun événement à venir pour le moment. Revenez vite !</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($upcoming as $event): ?>
                    <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <h2 class="section-title">Archives</h2>
        </div>
        <?php if (empty($past)): ?>
            <div class="empty-state surface glass">
                <p>Aucune archive pour le moment.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($past as $event): ?>
                    <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
