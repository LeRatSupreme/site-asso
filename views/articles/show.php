<?php

declare(strict_types=1);

/**
 * Détail d'un article de blog.
 *
 * @var array<string,mixed> $article
 */

$article = $article ?? [];

$title    = (string) ($article['title'] ?? '');
$excerpt  = (string) ($article['excerpt'] ?? '');
$content  = (string) ($article['content'] ?? '');
$image    = (string) ($article['image'] ?? '');
$category = (string) ($article['category'] ?? '');
$dateRaw  = (string) ($article['published_at'] ?? ($article['created_at'] ?? ''));

$imageUrl = '';
if ($image !== '') {
    $imageUrl = is_absolute_url($image) ? $image : asset(ltrim($image, '/'));
}
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <p class="back-link">
            <a href="<?= e(url('/blog')) ?>">← Retour au blog</a>
        </p>
        <span class="eyebrow">Article</span>
        <h1 class="page-title"><?= e($title) ?></h1>
        <p class="card-meta">
            📅 <?= e(formatDate($dateRaw)) ?>
            <?php if ($category !== ''): ?> · <span class="badge badge-info"><?= e($category) ?></span><?php endif; ?>
        </p>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <?php if ($imageUrl !== ''): ?>
            <div class="event-cover surface" style="margin-bottom:1.5rem">
                <img src="<?= e($imageUrl) ?>" alt="<?= e($title) ?>" loading="lazy">
            </div>
        <?php endif; ?>

        <?php if ($excerpt !== ''): ?>
            <p class="card-excerpt lead" style="margin-bottom:1.5rem"><?= e($excerpt) ?></p>
        <?php endif; ?>

        <?php if ($content !== ''): ?>
            <div class="prose surface glass"><?= $content ?></div>
        <?php endif; ?>
    </div>
</section>
