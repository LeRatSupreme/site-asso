<?php

declare(strict_types=1);

/** @var array<string,mixed>|null $page */
$isFr = current_lang() === 'fr';
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('page.cgu.eyebrow')) ?></span>
        <h1 class="page-title"><?= e(t('page.cgu.title')) ?></h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <?php if ($isFr && !empty($page['content'])): ?>
            <div class="prose surface glass"><?= $page['content'] ?></div>
        <?php else: ?>
            <div class="prose surface glass">
                <?php if ($isFr): ?>
                    <p><?= e(t('page.cgu.placeholder')) ?></p>
                <?php endif; ?>
                <p><?= e(t('cgu.intro')) ?></p>
                <h2><?= e(t('cgu.account.title')) ?></h2>
                <p><?= e(t('cgu.account.desc')) ?></p>
                <h2><?= e(t('cgu.usage.title')) ?></h2>
                <p><?= e(t('cgu.usage.desc')) ?></p>
                <h2><?= e(t('cgu.events.title')) ?></h2>
                <p><?= e(t('cgu.events.desc')) ?></p>
                <h2><?= e(t('cgu.ip.title')) ?></h2>
                <p><?= e(t('cgu.ip.desc')) ?></p>
                <h2><?= e(t('cgu.liability.title')) ?></h2>
                <p><?= e(t('cgu.liability.desc')) ?></p>
                <h2><?= e(t('cgu.data.title')) ?></h2>
                <p><?= e(t('cgu.data.desc')) ?></p>
                <h2><?= e(t('cgu.changes.title')) ?></h2>
                <p><?= e(t('cgu.changes.desc')) ?></p>
                <h2><?= e(t('cgu.law.title')) ?></h2>
                <p><?= e(t('cgu.law.desc')) ?></p>
            </div>
        <?php endif; ?>
    </div>
</section>
