<?php

declare(strict_types=1);

/**
 * Carte d'un article de blog (partial réutilisable).
 *
 * @var array<string,mixed>|null $article
 */

/** @var array<string,mixed>|null $article */
$article = $article ?? null;
if (!is_array($article)) {
    return;
}

$slug    = (string) ($article['slug'] ?? '');
$title   = (string) ($article['title'] ?? '');
$excerpt = (string) ($article['excerpt'] ?? '');
$image   = (string) ($article['image'] ?? '');
$dateRaw = (string) ($article['published_at'] ?? ($article['created_at'] ?? ''));

$category = (string) ($article['category'] ?? '');

$imageUrl = '';
if ($image !== '') {
    $imageUrl = is_absolute_url($image) ? $image : asset(ltrim($image, '/'));
}
?>
<article class="event-card card card-hover surface glass">
    <a class="event-card-media" href="<?= e(url('/blog/' . rawurlencode($slug))) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($imageUrl !== ''): ?>
            <img src="<?= e($imageUrl) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="event-card-placeholder aeic-gradient" aria-hidden="true">📰</span>
        <?php endif; ?>
    </a>

    <div class="event-card-body">
        <div class="event-card-meta-top">
            <p class="card-date"><?= e(formatDate($dateRaw)) ?></p>
            <?php if ($category !== ''): ?>
                <span class="badge badge-info"><?= e($category) ?></span>
            <?php endif; ?>
        </div>
        <h3 class="card-title">
            <a href="<?= e(url('/blog/' . rawurlencode($slug))) ?>"><?= e($title) ?></a>
        </h3>
        <?php if ($excerpt !== ''): ?>
            <p class="card-excerpt"><?= e($excerpt) ?></p>
        <?php endif; ?>
    </div>

    <div class="event-card-actions">
        <a class="btn btn-outline btn-sm" href="<?= e(url('/blog/' . rawurlencode($slug))) ?>">Lire l'article</a>
    </div>
</article>
