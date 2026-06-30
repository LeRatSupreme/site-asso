<?php

declare(strict_types=1);

/**
 * Page « L'association ».
 *
 * @var array<string,mixed>|null $page
 * @var int $usersCount
 * @var int $eventsCount
 */
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('about.eyebrow')) ?></span>
        <h1 class="page-title"><?= e(t('about.title')) ?></h1>
        <p class="page-lead"><?= e(t('about.lead')) ?></p>
    </div>
</header>

<section class="section">
    <div class="container">
        <div class="panel-brand panel">
            <h2 class="section-title"><?= e(t('about.mission')) ?></h2>
            <p class="lead">
                <?= e(t('about.mission.desc')) ?>
            </p>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('about.values.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('about.values.title')) ?></h2>
        </div>
        <div class="grid grid-3">
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('about.value.proximity')) ?></h3>
                <p><?= e(t('about.value.proximity.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('about.value.passion')) ?></h3>
                <p><?= e(t('about.value.passion.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('about.value.sharing')) ?></h3>
                <p><?= e(t('about.value.sharing.desc')) ?></p>
            </article>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('home.stats.aria')) ?></span>
            <h2 class="section-title"><?= e(t('about.stats.title')) ?></h2>
        </div>
        <div class="grid grid-4">
            <div class="stat-card surface glass"><span class="stat-value"><?= e((string) max($usersCount, 0)) ?></span><span class="stat-label"><?= e(t('home.stat.members')) ?></span></div>
            <div class="stat-card surface glass"><span class="stat-value"><?= e((string) max($eventsCount, 0)) ?></span><span class="stat-label"><?= e(t('home.stat.events')) ?></span></div>
            <div class="stat-card surface glass"><span class="stat-value">100 %</span><span class="stat-label"><?= e(t('home.stat.student')) ?></span></div>
            <div class="stat-card surface glass"><span class="stat-value">0</span><span class="stat-label"><?= e(t('home.stat.easy')) ?></span></div>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="panel-brand panel">
            <h2 class="section-title"><?= e(t('about.vision.title')) ?></h2>
            <p class="lead"><?= e(t('about.vision.desc')) ?></p>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('about.spaces.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('about.spaces.title')) ?></h2>
            <p class="lead"><?= e(t('about.spaces.desc')) ?></p>
        </div>
        <div class="grid grid-2">
            <article class="card surface glass card-hover">
                <p><?= e(t('about.spaces.free')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <p><?= e(t('about.spaces.local')) ?></p>
            </article>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('about.events.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('about.events.title')) ?></h2>
            <p class="lead"><?= e(t('about.events.intro')) ?></p>
        </div>
        <div class="grid grid-3">
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('about.events.nuitinfo.title')) ?></h3>
                <p><?= e(t('about.events.nuitinfo.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('about.events.afterworks.title')) ?></h3>
                <p><?= e(t('about.events.afterworks.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('about.events.bbq.title')) ?></h3>
                <p><?= e(t('about.events.bbq.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('about.events.bowling.title')) ?></h3>
                <p><?= e(t('about.events.bowling.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('about.events.bar.title')) ?></h3>
                <p><?= e(t('about.events.bar.desc')) ?></p>
            </article>
        </div>
        <p class="lead" style="text-align:center;margin-top:2rem"><?= e(t('about.events.closing')) ?></p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="panel surface glass">
            <h2 class="section-title"><?= e(t('about.contact.title')) ?></h2>
            <p class="lead"><?= e(t('about.contact.label')) ?> — <?= e(t('about.contact.address')) ?></p>
        </div>
    </div>
</section>

<?php require __DIR__ . '/../partials/map.php'; ?>

<section class="section cta">
    <div class="container cta-inner">
        <h2 class="section-title"><?= e(t('about.cta.title')) ?></h2>
        <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="<?= e(url('/events')) ?>"><?= e(t('home.cta.join')) ?></a>
            <a class="btn btn-outline btn-lg" href="<?= e(url('/events')) ?>"><?= e(t('home.cta.events')) ?></a>
        </div>
    </div>
</section>
