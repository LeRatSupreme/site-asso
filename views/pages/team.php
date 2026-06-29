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
        <span class="eyebrow">Le bureau</span>
        <h1 class="page-title">Ceux qui font vivre l'AEIC.</h1>
        <p class="page-lead">Les étudiants du bureau, pôle par pôle.</p>
    </div>
</header>

<section class="section">
    <div class="container">
        <?php if (empty($highlighted) && empty($members)): ?>
            <div class="empty-state surface glass">
                <p>Contenu à venir. Le bureau sera bientôt présenté ici.</p>
            </div>
        <?php else: ?>
            <?php if (!empty($highlighted)): ?>
                <div class="section-head">
                    <span class="eyebrow">Bureau restreint</span>
                    <h2 class="section-title">Le bureau</h2>
                </div>
                <div class="grid team-grid team-grid-featured">
                    <?php foreach ($highlighted as $member): ?>
                        <?php require AEIC_VIEWS . '/partials/_team_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($members)): ?>
                <div class="section-head team-section-others">
                    <span class="eyebrow">Toute l'équipe</span>
                    <h2 class="section-title">Les membres</h2>
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
