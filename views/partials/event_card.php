<?php

declare(strict_types=1);

/**
 * Carte d'un événement (partial réutilisable).
 *
 * @var array<string,mixed> $event
 */

/** @var array<string,mixed>|null $event */
$event = $event ?? null;
if (!is_array($event)) {
    return;
}

$slug       = (string) ($event['slug'] ?? '');
$title      = tc((string) ($event['title'] ?? ''));
$excerpt    = tc((string) ($event['excerpt'] ?? ''));
$location   = tc((string) ($event['location'] ?? ''));
$image      = (string) ($event['image'] ?? '');
$dateRaw    = (string) ($event['date'] ?? '');
$price      = $event['price'] ?? null;
$isFeatured = !empty($event['is_featured']);

$category   = (string) ($event['category'] ?? '');

$badge = '';
if ($isFeatured) {
    $badge = '<span class="badge badge-gradient">' . e(t('common.featured')) . '</span>';
} elseif ($price === null || (float) $price <= 0) {
    $badge = '<span class="badge badge-success">' . e(t('event.free')) . '</span>';
} else {
    $badge = '<span class="badge badge-secondary">' . e(formatPrice($price)) . '</span>';
}

$imageUrl = '';
if ($image !== '') {
    $imageUrl = is_absolute_url($image) ? $image : asset(ltrim($image, '/'));
}
?>
<article class="event-card card card-hover surface glass" <?= $category !== '' ? 'data-event-cat="' . e(strtolower($category)) . '"' : 'data-event-cat=""' ?>>
    <a class="event-card-media" href="<?= e(url('/events/' . $slug)) ?>" tabindex="-1" aria-hidden="true">
        <?php if ($imageUrl !== ''): ?>
            <img src="<?= e($imageUrl) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="event-card-placeholder aeic-gradient" aria-hidden="true">AE</span>
        <?php endif; ?>
        <span class="event-card-badge"><?= $badge ?></span>
    </a>

    <div class="event-card-body">
        <div class="event-card-meta-top">
            <p class="card-date"><?= e(formatDateTime($dateRaw) ?: formatDate($dateRaw)) ?></p>
            <?php if ($category !== ''): ?>
                <span class="badge badge-info event-cat-badge"><?= e(t_category($category)) ?></span>
            <?php endif; ?>
        </div>
        <h3 class="card-title">
            <a href="<?= e(url('/events/' . $slug)) ?>"><?= e($title) ?></a>
        </h3>
        <?php if ($excerpt !== ''): ?>
            <p class="card-excerpt"><?= e($excerpt) ?></p>
        <?php endif; ?>
        <?php if ($location !== ''): ?>
            <p class="card-meta">📍 <?= e($location) ?></p>
        <?php endif; ?>
    </div>

    <div class="event-card-actions">
        <a class="btn btn-outline btn-sm" href="<?= e(url('/events/' . $slug)) ?>"><?= e(t('common.details')) ?></a>
    </div>
</article>
