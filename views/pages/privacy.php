<?php

declare(strict_types=1);

/**
 * Politique de confidentialité (placeholder + contenu CMS si présent).
 *
 * @var array<string,mixed>|null $page
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('page.privacy.eyebrow')) ?></span>
        <h1 class="page-title"><?= e(t('page.privacy.title')) ?></h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <?php if (!empty($page['content'])): ?>
            <div class="prose surface glass"><?= $page['content'] ?></div>
        <?php else: ?>
            <div class="empty-state surface glass">
                <p><?= e(t('page.privacy.placeholder')) ?></p>
            </div>
            <div class="prose surface glass">
                <h2><?= e(t('page.privacy.collected')) ?></h2>
                <p><?= e(t('page.privacy.collected.desc')) ?></p>
                <h2><?= e(t('page.privacy.rights')) ?></h2>
                <p><?= e(t('page.privacy.rights.desc')) ?></p>
                <h2><?= e(t('page.privacy.contact')) ?></h2>
                <p><?= e(t('page.privacy.contact.desc')) ?> <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>.</p>
            </div>
        <?php endif; ?>
    </div>
</section>
