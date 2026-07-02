<?php

declare(strict_types=1);

/** @var array<string,mixed>|null $page */
$isFr = current_lang() === 'fr';
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
        <?php if ($isFr && !empty($page['content'])): ?>
            <div class="prose surface glass"><?= $page['content'] ?></div>
        <?php else: ?>
            <div class="prose surface glass">
                <?php if ($isFr): ?>
                    <p><?= e(t('page.privacy.placeholder')) ?></p>
                <?php endif; ?>
                <p><?= e(t('privacy.intro')) ?></p>
                <h2><?= e(t('privacy.data.collected')) ?></h2>
                <p>
                    <?= e(t('privacy.data.email')) ?><br>
                    <?= e(t('privacy.data.password')) ?><br>
                    <?= e(t('privacy.data.name')) ?><br>
                    <?= e(t('privacy.data.purpose')) ?><br>
                    <?= e(t('privacy.data.retention')) ?>
                </p>
                <h2><?= e(t('privacy.rights.title')) ?></h2>
                <p>
                    <?= e(t('privacy.rights.access')) ?><br>
                    <?= e(t('privacy.rights.rectify')) ?><br>
                    <?= e(t('privacy.rights.erase')) ?><br>
                    <?= e(t('privacy.rights.export')) ?><br>
                    <?= e(t('privacy.rights.object')) ?>
                </p>
                <h2><?= e(t('privacy.security.title')) ?></h2>
                <p><?= e(t('privacy.security.desc')) ?></p>
                <p><?= e(t('privacy.no_card')) ?></p>
                <h2><?= e(t('privacy.contact')) ?></h2>
                <p>
                    <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a><br>
                    <?= e(t('privacy.cnil')) ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</section>
