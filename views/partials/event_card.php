<?php

declare(strict_types=1);

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

// Badge: prix ou gratuit ou À la une.
$badgeHtml = '';
if ($isFeatured) {
    $badgeHtml = '<span class="badge badge-gradient">⭐ ' . e(t('common.featured')) . '</span>';
} elseif ($price === null || (float) $price <= 0) {
    $badgeHtml = '<span class="badge badge-success">' . e(t('event.free')) . '</span>';
} else {
    $badgeHtml = '<span class="badge badge-secondary">' . e(formatPrice($price)) . '</span>';
}

$imageUrl = '';
if ($image !== '') {
    $imageUrl = is_absolute_url($image) ? $image : asset(ltrim($image, '/'));
}

// Formatage date court (ex: "11 SEP" + "18:00").
$dayNum   = $dateRaw !== '' ? date('j', strtotime($dateRaw)) : '';
$monthAbbr = $dateRaw !== '' ? strtoupper(date('M', strtotime($dateRaw))) : '';
$timeStr  = $dateRaw !== '' ? date('H:i', strtotime($dateRaw)) : '';
$yearStr  = $dateRaw !== '' ? date('Y', strtotime($dateRaw)) : '';
?>
<article class="event-card-v2" <?= $category !== '' ? 'data-event-cat="' . e(strtolower($category)) . '"' : 'data-event-cat=""' ?>>
    <a href="<?= e(url('/events/' . $slug)) ?>" class="event-v2-link">
        <!-- Bandeau image / placeholder -->
        <div class="event-v2-banner">
            <?php if ($imageUrl !== ''): ?>
                <img src="<?= e($imageUrl) ?>" alt="" loading="lazy">
            <?php else: ?>
                <div class="event-v2-noimg aeic-gradient">
                    <span>AE</span>
                </div>
            <?php endif; ?>
            <div class="event-v2-badge-row">
                <?= $badgeHtml ?>
                <?php if ($category !== ''): ?>
                    <span class="badge badge-info event-cat-badge"><?= e(t_category($category)) ?></span>
                <?php endif; ?>
            </div>
            <!-- Date en overlay -->
            <?php if ($dayNum !== ''): ?>
                <div class="event-v2-date-chip">
                    <span class="date-day"><?= e($dayNum) ?></span>
                    <span class="date-month"><?= e($monthAbbr) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Corps -->
        <div class="event-v2-body">
            <h3 class="event-v2-title"><?= e($title) ?></h3>
            <?php if ($excerpt !== ''): ?>
                <p class="event-v2-excerpt"><?= e($excerpt) ?></p>
            <?php endif; ?>

            <div class="event-v2-meta">
                <?php if ($timeStr !== ''): ?>
                    <span class="event-v2-time">🕐 <?= e($timeStr) ?></span>
                <?php endif; ?>
                <?php if ($location !== ''): ?>
                    <span class="event-v2-loc">📍 <?= e($location) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer carte -->
        <div class="event-v2-foot">
            <span class="event-v2-cta"><?= e(t('common.details')) ?> →</span>
        </div>
    </a>
</article>

<style>
.event-card-v2 {
    background: var(--card, #0f1e35);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
}
.event-card-v2:hover {
    transform: translateY(-4px);
    border-color: rgba(72, 189, 211, 0.4);
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.3);
}
.event-v2-link {
    text-decoration: none;
    color: inherit;
    display: flex;
    flex-direction: column;
    height: 100%;
}

/* Banner */
.event-v2-banner {
    position: relative;
    height: 160px;
    overflow: hidden;
}
.event-v2-banner img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}
.event-card-v2:hover .event-v2-banner img {
    transform: scale(1.05);
}
.event-v2-noimg {
    width: 100%;
    height: 100%;
    display: grid;
    place-items: center;
    font-size: 2.5rem;
    font-weight: 900;
    color: rgba(255,255,255,0.8);
    letter-spacing: -0.04em;
}
.event-v2-badge-row {
    position: absolute;
    top: 0.6rem;
    left: 0.6rem;
    right: 0.6rem;
    display: flex;
    justify-content: space-between;
    gap: 0.4rem;
    flex-wrap: wrap;
}
.event-v2-date-chip {
    position: absolute;
    bottom: 0.6rem;
    right: 0.6rem;
    background: var(--card, #0f1e35);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 0.35rem 0.65rem;
    text-align: center;
    line-height: 1;
}
.date-day {
    display: block;
    font-size: 1.25rem;
    font-weight: 900;
    color: var(--primary);
}
.date-month {
    display: block;
    font-size: 0.62rem;
    font-weight: 700;
    color: var(--muted);
    margin-top: 1px;
}

/* Body */
.event-v2-body {
    padding: 1rem 1.1rem 0.5rem;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}
.event-v2-title {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--foreground);
    line-height: 1.3;
    margin: 0;
}
.event-card-v2:hover .event-v2-title {
    color: var(--primary);
}
.event-v2-excerpt {
    font-size: 0.85rem;
    color: var(--muted);
    line-height: 1.5;
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.event-v2-meta {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    margin-top: auto;
    padding-top: 0.4rem;
}
.event-v2-time, .event-v2-loc {
    font-size: 0.78rem;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Footer */
.event-v2-foot {
    padding: 0.6rem 1.1rem 0.9rem;
}
.event-v2-cta {
    font-size: 0.85rem;
    font-weight: 700;
    color: var(--primary);
    transition: gap 0.2s;
}
.event-card-v2:hover .event-v2-cta {
    letter-spacing: 0.02em;
}
.event-cat-badge {
    font-size: 0.68rem;
    backdrop-filter: blur(8px);
    background: rgba(72, 189, 211, 0.2);
}

[data-theme="light"] .event-v2-noimg {
    background: linear-gradient(135deg, var(--secondary), var(--primary));
}
[data-theme="light"] .event-v2-date-chip {
    background: #ffffff;
}
</style>
