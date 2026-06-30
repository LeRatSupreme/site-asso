<?php

declare(strict_types=1);

/**
 * Page « L'équipe ».
 *
 * @var list<array<string,mixed>> $highlighted
 * @var list<array<string,mixed>> $members
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('team.eyebrow')) ?></span>
        <h1 class="page-title"><?= e(t('team.title')) ?></h1>
        <p class="page-lead"><?= e(t('team.lead')) ?></p>
    </div>
</header>

<section class="section">
    <div class="container">
        <?php if (empty($highlighted) && empty($members)): ?>
            <div class="empty-state surface glass">
                <p><?= e(t('team.empty')) ?></p>
            </div>
        <?php else: ?>
            <?php if (!empty($highlighted)): ?>
                <div class="section-head">
                    <span class="eyebrow"><?= e(t('team.board.eyebrow')) ?></span>
                    <h2 class="section-title"><?= e(t('team.board.title')) ?></h2>
                </div>
                <div class="grid team-grid team-grid-featured">
                    <?php foreach ($highlighted as $member): ?>
                        <?php require AEIC_VIEWS . '/partials/_team_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($members)): ?>
                <div class="section-head team-section-others">
                    <span class="eyebrow"><?= e(t('team.all.eyebrow')) ?></span>
                    <h2 class="section-title"><?= e(t('team.all.title')) ?></h2>
                </div>
                <div class="grid team-grid">
                    <?php foreach ($members as $member): ?>
                        <?php require AEIC_VIEWS . '/partials/_team_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
