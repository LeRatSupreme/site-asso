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
                    <article class="card card-hover surface glass">
                        <?php if (!empty($event['is_featured'])): ?>
                            <span class="badge badge-gradient">À la une</span>
                        <?php endif; ?>
                        <p class="card-date"><?= e(formatDate($event['date'])) ?></p>
                        <h3 class="card-title"><?= e($event['title']) ?></h3>
                        <?php if (!empty($event['excerpt'])): ?>
                            <p class="card-excerpt"><?= e($event['excerpt']) ?></p>
                        <?php endif; ?>
                        <p class="card-meta"><?= e($event['location'] ?? '') ?></p>
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/events/' . $event['slug'])) ?>">Détails</a>
                    </article>
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
                    <article class="card surface glass">
                        <span class="badge badge-muted">Terminé</span>
                        <p class="card-date"><?= e(formatDate($event['date'])) ?></p>
                        <h3 class="card-title"><?= e($event['title']) ?></h3>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
