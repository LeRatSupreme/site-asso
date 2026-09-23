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
    <div class="ae-aurora" aria-hidden="true"></div>
    <div class="ae-dots dot-grid" aria-hidden="true"></div>
    <div class="ae-chips ae-chips-left" data-parallax="14" aria-hidden="true">
        <span class="ae-chip ae-chip-1">👥</span>
        <span class="ae-chip ae-chip-2">🎓</span>
        <span class="ae-chip ae-chip-3">⭐</span>
        <span class="ae-chip ae-chip-4">💬</span>
        <span class="ae-chip ae-chip-5">🚀</span>
    </div>
    <div class="container">
        <div class="ae-hero-grid">
            <div>
                <span class="ae-pill">
                    <span class="ae-pill-dot" aria-hidden="true"></span>
                    <?= e(t('team.eyebrow')) ?>
                </span>
                <h1 class="page-title ae-title-grad"><?= e(t('team.title')) ?></h1>
                <p class="page-lead"><?= e(t('team.lead')) ?></p>
            </div>

            <!-- Badge de l'équipe décoratif et interactif -->
            <?php $teamTotal = count($highlighted) + count($members); ?>
            <div class="ae-idcard" data-tilt data-tilt-base="rotate(1.4deg)" aria-hidden="true">
                <div class="ae-idcard-band">
                    <span class="ae-idcard-logo">AE</span>
                    <span class="ae-idcard-id">
                        <span class="ae-idcard-label">BADGE / TEAM</span>
                        <span class="ae-idcard-name">AEIC</span>
                    </span>
                    <span class="ae-idcard-badge"><?= e((string) $teamTotal) ?></span>
                </div>
                <div class="ae-idcard-body">
                    <div class="ae-idcard-row">
                        <span class="ae-idcard-ico">⭐</span>
                        <span><?= e(t('team.board.title')) ?></span>
                    </div>
                    <div class="ae-idcard-row">
                        <span class="ae-idcard-ico">👥</span>
                        <span><?= e(t('team.all.title')) ?></span>
                    </div>
                    <div class="ae-idcard-row">
                        <span class="ae-idcard-ico">🎓</span>
                        <span><?= e(t('home.stat.student')) ?></span>
                    </div>
                </div>
                <div class="ae-idcard-code">|||&#124; || |||| &#124;&#124; |||&#124; ||||| &#124; ||</div>
            </div>
        </div>
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
                <div class="section-head ae-reveal">
                    <span class="eyebrow"><?= e(t('team.board.eyebrow')) ?></span>
                    <h2 class="section-title"><?= e(t('team.board.title')) ?></h2>
                </div>
                <div class="grid team-grid team-grid-featured ae-reveal">
                    <?php foreach ($highlighted as $member): ?>
                        <?php require AEIC_VIEWS . '/partials/_team_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($members)): ?>
                <div class="section-head team-section-others ae-reveal">
                    <span class="eyebrow"><?= e(t('team.all.eyebrow')) ?></span>
                    <h2 class="section-title"><?= e(t('team.all.title')) ?></h2>
                </div>
                <div class="grid team-grid ae-reveal">
                    <?php foreach ($members as $member): ?>
                        <?php require AEIC_VIEWS . '/partials/_team_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<style>
/* ============ Équipe — spécifique ============ */

/* Avatars cerclés d'un anneau dégradé (comme les médailles des valeurs) */
.team-grid .team-avatar {
    width: 104px;
    height: 104px;
    padding: 4px;
    background: linear-gradient(135deg, #8b7ae0, #3a9bb8);
    box-shadow: 0 14px 32px rgba(58, 155, 184, 0.3);
    transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.3s ease;
}
.team-grid .team-card:hover .team-avatar {
    transform: translateY(-4px) scale(1.05);
    box-shadow: 0 20px 44px rgba(58, 155, 184, 0.4), 0 0 30px rgba(139, 122, 224, 0.2);
}
.team-grid .team-card {
    border-radius: 20px;
    text-align: center;
    align-items: center;
}
.team-grid .team-role {
    font-size: 0.95rem;
}
.team-grid .team-card .card-title {
    text-transform: none;
    letter-spacing: -0.01em;
    font-size: 1.15rem;
}
.team-section-others { margin-top: 3.5rem; }
</style>
