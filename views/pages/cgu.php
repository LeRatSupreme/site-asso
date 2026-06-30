<?php

declare(strict_types=1);

/**
 * Conditions Générales d'Utilisation (placeholder + contenu CMS si présent).
 *
 * @var array<string,mixed>|null $page
 */
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
        <?php if (!empty($page['content'])): ?>
            <div class="prose surface glass"><?= $page['content'] ?></div>
        <?php else: ?>
            <div class="empty-state surface glass">
                <p><?= e(t('page.cgu.placeholder')) ?></p>
            </div>
            <div class="prose surface glass">
                <h2><?= e(t('page.cgu.object')) ?></h2>
                <p><?= e(t('page.cgu.object.desc')) ?></p>
                <h2><?= e(t('page.cgu.registration')) ?></h2>
                <p><?= e(t('page.cgu.registration.desc')) ?></p>
                <h2><?= e(t('page.cgu.responsibility')) ?></h2>
                <p><?= e(t('page.cgu.responsibility.desc')) ?></p>
            </div>
        <?php endif; ?>
    </div>
</section>
