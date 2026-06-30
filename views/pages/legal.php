<?php

declare(strict_types=1);

/**
 * Mentions légales (placeholder + contenu CMS si présent).
 *
 * @var array<string,mixed>|null $page
 */
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('page.legal.eyebrow')) ?></span>
        <h1 class="page-title"><?= e(t('page.legal.title')) ?></h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <?php if (!empty($page['content'])): ?>
            <div class="prose surface glass"><?= $page['content'] ?></div>
        <?php else: ?>
            <div class="empty-state surface glass">
                <p><?= e(t('page.legal.placeholder')) ?></p>
            </div>
            <div class="prose surface glass">
                <h2><?= e(t('page.legal.publisher')) ?></h2>
                <p><?= e(t('page.legal.publisher.desc')) ?></p>
                <h2><?= e(t('page.legal.publisher_responsible')) ?></h2>
                <p><?= e(t('page.legal.publisher_responsible.desc')) ?></p>
                <h2><?= e(t('page.legal.hosting')) ?></h2>
                <p><?= e(t('page.legal.hosting.desc')) ?></p>
            </div>
        <?php endif; ?>
    </div>
</section>
