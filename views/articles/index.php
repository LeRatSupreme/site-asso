<?php

declare(strict_types=1);

/**
 * Liste publique des articles de blog.
 *
 * @var list<array<string,mixed>> $articles
 * @var int $count
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Blog & actualités</span>
        <h1 class="page-title">Les coulisses de l'AEIC.</h1>
        <p class="page-lead"><?= e((string) $count) ?> article<?= $count > 1 ? 's' : '' ?></p>
    </div>
</header>

<section class="section">
    <div class="container">
        <?php if (empty($articles)): ?>
            <div class="empty-state surface glass">
                <p>Aucun article pour le moment. Revenez bientôt !</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($articles as $article): ?>
                    <?php require AEIC_VIEWS . '/partials/article_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
