<?php

declare(strict_types=1);

/**
 * Page d'accueil AEIC.
 *
 * @var string $siteName
 * @var string $description
 * @var list<array<string,mixed>> $upcoming
 * @var int $eventsCount
 * @var int $usersCount
 */
?>
<section class="hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container hero-grid">
        <div class="hero-content">
            <span class="eyebrow"><?= e(t('home.eyebrow')) ?></span>
            <h1 class="hero-title">
                <?= e(t('home.title.line1')) ?>
                <span class="accent"><?= e(t('home.title.line2')) ?></span>
            </h1>
            <p class="hero-lead">
                <?= e(tc($description ?: t('home.description'))) ?>
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="<?= e(url('/presentation')) ?>"><?= e(t('home.cta.join')) ?></a>
                <a class="btn btn-outline btn-lg" href="<?= e(url('/events')) ?>"><?= e(t('home.cta.events')) ?></a>
            </div>
        </div>

        <aside class="hero-stats surface glass" aria-label="<?= e(t('home.stats.aria')) ?>">
            <div class="stat">
                <span class="stat-value">100 %</span>
                <span class="stat-label"><?= e(t('home.stat.student')) ?></span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= e((string) max($usersCount, 0)) ?></span>
                <span class="stat-label"><?= e(t('home.stat.members')) ?></span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= e((string) max($eventsCount, 0)) ?></span>
                <span class="stat-label"><?= e(t('home.stat.events')) ?></span>
            </div>
            <div class="stat">
                <span class="stat-value">0 %</span>
                <span class="stat-label"><?= e(t('home.stat.easy')) ?></span>
            </div>
        </aside>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('home.upcoming.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('home.upcoming.title')) ?></h2>
        </div>

        <?php if (empty($upcoming)): ?>
            <div class="empty-state surface glass">
                <p><?= e(t('home.upcoming.empty')) ?></p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($upcoming as $event): ?>
                    <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                <?php endforeach; ?>
            </div>
            <p class="section-more"><a class="btn btn-ghost" href="<?= e(url('/events')) ?>"><?= e(t('home.upcoming.more')) ?></a></p>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('home.features.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('home.features.title')) ?></h2>
        </div>
        <div class="grid grid-3">
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('home.feature.events.title')) ?></h3>
                <p><?= e(t('home.feature.events.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('home.feature.cafeteria.title')) ?></h3>
                <p><?= e(t('home.feature.cafeteria.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('home.feature.community.title')) ?></h3>
                <p><?= e(t('home.feature.community.desc')) ?></p>
            </article>
        </div>
    </div>
</section>
