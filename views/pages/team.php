<?php

declare(strict_types=1);

/**
 * Page « L'équipe ».
 *
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
        <?php if (empty($members)): ?>
            <div class="empty-state surface glass">
                <p>Contenu à venir. Le bureau sera bientôt présenté ici.</p>
            </div>
        <?php else: ?>
            <div class="grid team-grid">
                <?php foreach ($members as $member): ?>
                    <article class="team-card card surface glass card-hover">
                        <div class="team-avatar">
                            <?php if (!empty($member['photo'])): ?>
                                <img src="<?= e(is_absolute_url($member['photo']) ? $member['photo'] : asset('img/' . ltrim($member['photo'], '/'))) ?>"
                                     alt="<?= e(($member['prenom'] ?? '') . ' ' . ($member['nom'] ?? '')) ?>"
                                     loading="lazy" width="96" height="96">
                            <?php else: ?>
                                <span class="team-initial aeic-gradient" aria-hidden="true">
                                    <?= e(initial(($member['prenom'] ?? '') . ' ' . ($member['nom'] ?? ''))) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <h3 class="card-title"><?= e(($member['prenom'] ?? '') . ' ' . ($member['nom'] ?? '')) ?></h3>
                        <?php if (!empty($member['role'])): ?>
                            <p class="team-role"><?= e($member['role']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($member['pole'])): ?>
                            <p class="team-pole"><span class="badge badge-secondary"><?= e($member['pole']) ?></span></p>
                        <?php endif; ?>
                        <?php if (!empty($member['bio'])): ?>
                            <p class="team-bio"><?= e($member['bio']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
