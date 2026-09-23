<?php

declare(strict_types=1);

/**
 * Galerie photos publique.
 *
 * @var list<array{event_id:string,event_title:string,event_slug:string,event_date:string,photos:list<array{url:string,caption:string,photo_id:string}>}> $groups
 */

$groups = $groups ?? [];
?>

<header class="page-hero">
    <div class="ae-aurora" aria-hidden="true"></div>
    <div class="ae-dots dot-grid" aria-hidden="true"></div>
    <div class="ae-chips" aria-hidden="true">
        <span class="ae-chip ae-chip-1">📷</span>
        <span class="ae-chip ae-chip-2">🖼️</span>
        <span class="ae-chip ae-chip-3">✨</span>
    </div>
    <div class="container">
        <span class="ae-pill">
            <span class="ae-pill-dot" aria-hidden="true"></span>
            <?= e(t('gallery.eyebrow')) ?>
        </span>
        <h1 class="page-title ae-title-grad"><?= e(t('gallery.title')) ?></h1>
        <p class="page-lead"><?= e(t('gallery.lead')) ?></p>
    </div>
</header>

<section class="section">
    <div class="container">
        <?php if ($groups === []): ?>
            <div class="empty-state">
                <p><?= e(t('gallery.empty')) ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <div class="gallery-group ae-reveal">
                    <div class="gallery-group-head">
                        <?php if ($group['event_slug'] !== ''): ?>
                            <a class="gallery-group-title" href="<?= e(url('/events/' . rawurlencode($group['event_slug']))) ?>">
                                <?= e($group['event_title']) ?>
                            </a>
                        <?php else: ?>
                            <h2 class="gallery-group-title"><?= e($group['event_title']) ?></h2>
                        <?php endif; ?>
                        <?php if ($group['event_date'] !== ''): ?>
                            <span class="gallery-group-date">📅 <?= e(formatDate($group['event_date'])) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-3 gallery-grid">
                        <?php foreach ($group['photos'] as $photo): ?>
                            <?php
                            $photoUrl = is_absolute_url($photo['url'])
                                ? $photo['url']
                                : asset(ltrim($photo['url'], '/'));
                            $caption = $photo['caption'];
                            ?>
                            <figure class="gallery-item surface">
                                <a href="<?= e($photoUrl) ?>" class="gallery-zoom"
                                   data-caption="<?= e($caption) ?>"
                                   title="<?= e($caption !== '' ? $caption : t('gallery.zoom')) ?>">
                                    <img src="<?= e($photoUrl) ?>"
                                         alt="<?= e($caption !== '' ? $caption : $group['event_title']) ?>"
                                         loading="lazy">
                                </a>
                                <?php if ($caption !== ''): ?>
                                    <figcaption><?= e($caption) ?></figcaption>
                                <?php endif; ?>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<!-- Lightbox (CSS + JS minimal) -->
<div class="lightbox" id="lightbox" hidden>
    <button type="button" class="lightbox-close" id="lightbox-close" aria-label="<?= e(t('gallery.close')) ?>">✕</button>
    <img src="" alt="" id="lightbox-img">
    <p class="lightbox-caption" id="lightbox-caption"></p>
</div>

<style>
/* ============ Galerie — spécifique ============ */
.gallery-group-head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem 1rem;
    padding-bottom: 0.9rem;
    border-bottom: 1px solid var(--border);
    position: relative;
}
.gallery-group-head::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: -1px;
    width: 180px;
    height: 2px;
    background: linear-gradient(90deg, #8b7ae0, rgba(58, 155, 184, 0));
}
.gallery-group-title { font-size: 1.4rem; letter-spacing: -0.02em; }
.gallery-group-date {
    padding: 0.25rem 0.8rem;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--primary);
    background: rgba(72, 189, 211, 0.1);
    border: 1px solid rgba(72, 189, 211, 0.25);
}
.gallery-item {
    border-radius: 16px;
    transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}
.gallery-item:hover {
    transform: translateY(-4px);
    border-color: rgba(72, 189, 211, 0.4);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.35), 0 0 26px rgba(58, 155, 184, 0.12);
}
</style>

<script>
    (function () {
        var box = document.getElementById('lightbox');
        var img = document.getElementById('lightbox-img');
        var caption = document.getElementById('lightbox-caption');
        var closeBtn = document.getElementById('lightbox-close');
        if (!box || !img) { return; }

        function open(src, text) {
            img.src = src;
            caption.textContent = text || '';
            box.hidden = false;
        }
        function closeBox() { box.hidden = true; img.src = ''; }

        document.querySelectorAll('.gallery-zoom').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var targetImg = a.querySelector('img');
                open(targetImg ? targetImg.src : a.href, a.getAttribute('data-caption') || '');
            });
        });

        closeBtn.addEventListener('click', closeBox);
        box.addEventListener('click', function (e) { if (e.target === box) { closeBox(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeBox(); } });
    })();
</script>
