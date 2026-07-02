<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>|null $page
 */
$isFr = current_lang() === 'fr';
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
        <?php if ($isFr && !empty($page['content'])): ?>
            <div class="prose surface glass"><?= $page['content'] ?></div>
        <?php else: ?>
            <div class="prose surface glass">
                <?php if ($isFr): ?>
                    <p><?= e(t('page.legal.placeholder')) ?></p>
                <?php endif; ?>
                <h2><?= e(t('legal.editor.title')) ?></h2>
                <p>
                    <strong><?= e(t('legal.editor.name')) ?></strong><br>
                    <?= e(t('legal.editor.form')) ?><br>
                    <?= e(t('legal.editor.address')) ?><br>
                    <?= e(t('legal.editor.director')) ?> : <?= e(t('legal.publisher.desc')) ?><br>
                    <?= e(t('legal.editor.email')) ?> :
                    <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>
                </p>
                <h2><?= e(t('legal.publisher.title')) ?></h2>
                <p><?= e(t('legal.publisher.desc')) ?></p>
                <h2><?= e(t('legal.host.title')) ?></h2>
                <p>
                    <strong><?= e(t('legal.host.name')) ?></strong><br>
                    <?= e(t('legal.host.address')) ?>
                </p>
                <h2><?= e(t('legal.ip.title')) ?></h2>
                <p><?= e(t('legal.ip.desc')) ?></p>
                <h2><?= e(t('legal.links.title')) ?></h2>
                <p><?= e(t('legal.links.desc')) ?></p>
                <h2><?= e(t('legal.liability.title')) ?></h2>
                <p><?= e(t('legal.liability.desc')) ?></p>
                <h2><?= e(t('legal.data.title')) ?></h2>
                <p><?= e(t('legal.data.desc')) ?></p>
                <h2><?= e(t('legal.cookies.title')) ?></h2>
                <p><?= e(t('legal.cookies.desc')) ?></p>
                <h2><?= e(t('legal.law.title')) ?></h2>
                <p><?= e(t('legal.law.desc')) ?></p>
                <h2><?= e(t('legal.credits.title')) ?></h2>
                <p>
                    <?= e(t('legal.credits.dev')) ?><br>
                    <?= e(t('legal.credits.host')) ?><br>
                    <?= e(t('legal.credits.email')) ?><br>
                    <?= e(t('legal.credits.payment')) ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.lang-notice {
    display: flex; align-items: center; gap: 0.6rem;
    background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.2);
    border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1.25rem;
    font-size: 0.85rem; color: var(--muted);
}
.lang-notice span { font-size: 1.2rem; }
</style>
