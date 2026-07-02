<?php

declare(strict_types=1);

/**
 * Page d'accueil AEIC.
 *
 * @var string $siteName
 * @var string $description
 * @var list<array<string,mixed>> $upcoming
 * @var list<array<string,mixed>> $menuCategories
 * @var list<array<string,mixed>> $promotions
 * @var int $eventsCount
 * @var int $usersCount
 */

$allMenuEmpty = empty($menuCategories);
$allPromoEmpty = empty($promotions);
?>
<section class="hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container hero-grid">
        <div class="hero-content">
            <span class="eyebrow"><?= e(t('home.eyebrow')) ?></span>
            <h1 class="hero-title">
                <?= e(t('home.title.line1')) ?>
                <span class="accent"><?= e(t('home.title.line2')) ?></span>
            </h1>
            <p class="hero-lead">
                <?= e(tc($description ?: t('home.description'))) ?>
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="<?= e(url('/presentation')) ?>"><?= e(t('home.cta.join')) ?></a>
                <a class="btn btn-outline btn-lg" href="<?= e(url('/events')) ?>"><?= e(t('home.cta.events')) ?></a>
            </div>
        </div>

        <aside class="hero-stats surface glass" aria-label="<?= e(t('home.stats.aria')) ?>">
            <div class="stat">
                <span class="stat-value">100 %</span>
                <span class="stat-label"><?= e(t('home.stat.student')) ?></span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= e((string) max($usersCount, 0)) ?></span>
                <span class="stat-label"><?= e(t('home.stat.members')) ?></span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= e((string) max($eventsCount, 0)) ?></span>
                <span class="stat-label"><?= e(t('home.stat.events')) ?></span>
            </div>
            <div class="stat">
                <span class="stat-value">0 %</span>
                <span class="stat-label"><?= e(t('home.stat.easy')) ?></span>
            </div>
        </aside>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('home.upcoming.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('home.upcoming.title')) ?></h2>
        </div>

        <?php if (empty($upcoming)): ?>
            <div class="empty-state surface glass">
                <p><?= e(t('home.upcoming.empty')) ?></p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($upcoming as $event): ?>
                    <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                <?php endforeach; ?>
            </div>
            <p class="section-more"><a class="btn btn-ghost" href="<?= e(url('/events')) ?>"><?= e(t('home.upcoming.more')) ?></a></p>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt" id="menu">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('home.menu.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('home.menu.title')) ?></h2>
            <p class="muted"><?= e(t('home.menu.subtitle')) ?></p>
        </div>

        <?php if ($allMenuEmpty): ?>
            <div class="empty-state surface glass">
                <p><?= e(t('home.menu.empty')) ?></p>
            </div>
        <?php else: ?>
            <?php
            // Onglets : un par catégorie + un "Tout".
            $catTabs = [['key' => 'all', 'name' => t('home.menu.tab.all'), 'emoji' => '🍴']];
            foreach ($menuCategories as $cat) {
                $catTabs[] = [
                    'key'   => (string) $cat['id'],
                    'name'  => tc((string) $cat['name']),
                    'emoji' => product_emoji((string) $cat['name']),
                ];
            }
            ?>
            <div class="menu-tabs" role="tablist" aria-label="<?= e(t('home.menu.eyebrow')) ?>">
                <?php foreach ($catTabs as $i => $tab): ?>
                    <button type="button"
                            class="menu-tab<?= $i === 0 ? ' is-active' : '' ?>"
                            data-cat="<?= e($tab['key']) ?>"
                            role="tab"
                            aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
                        <span aria-hidden="true"><?= e($tab['emoji']) ?></span> <?= e($tab['name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="menu-grid">
                <?php foreach ($menuCategories as $cat): ?>
                    <?php foreach ($cat['products'] as $product): ?>
                        <?php
                        $name  = tc((string) ($product['name'] ?? ''));
                        $emoji = product_emoji((string) ($product['name'] ?? ''));
                        $img   = trim((string) ($product['image'] ?? ''));
                        $imgUrl = $img !== '' ? (is_absolute_url($img) ? $img : asset(ltrim($img, '/'))) : '';
                        ?>
                        <article class="menu-item surface glass" data-cat="<?= e((string) $cat['id']) ?>">
                            <?php if ($imgUrl !== ''): ?>
                                <img src="<?= e($imgUrl) ?>" alt="" class="menu-item-img" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='';">
                                <span class="menu-item-emoji" aria-hidden="true" style="display:none;"><?= e($emoji) ?></span>
                            <?php else: ?>
                                <span class="menu-item-emoji" aria-hidden="true"><?= e($emoji) ?></span>
                            <?php endif; ?>
                            <span class="menu-item-name"><?= e($name) ?></span>
                            <span class="menu-item-price"><?= e(formatPrice($product['price'] ?? 0)) ?></span>
                        </article>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>

            <p class="menu-empty-results muted" hidden><?= e(t('home.menu.empty')) ?></p>
        <?php endif; ?>
    </div>
</section>

<section class="section" id="promos">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('home.promos.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('home.promos.title')) ?></h2>
        </div>

        <?php if ($allPromoEmpty): ?>
            <div class="promo-empty surface glass">
                <span class="promo-empty-icon" aria-hidden="true">🎯</span>
                <p><?= e(t('home.promos.empty')) ?></p>
            </div>
        <?php else: ?>
            <div class="grid grid-3 promo-grid">
                <?php foreach ($promotions as $promo): ?>
                    <?php
                    $badge   = trim((string) ($promo['badge'] ?? ''));
                    $newPrice = formatPrice($promo['new_price'] ?? 0);
                    $oldPrice = ($promo['old_price'] ?? '') !== '' && $promo['old_price'] !== null
                        ? formatPrice($promo['old_price'])
                        : '';
                    ?>
                    <article class="promo-card surface glass fade-in">
                        <?php if ($badge !== ''): ?>
                            <span class="promo-badge"><?= e($badge) ?></span>
                        <?php endif; ?>
                        <h3 class="promo-title"><?= e($promo['title'] ?? '') ?></h3>
                        <?php if (!empty($promo['description'])): ?>
                            <p class="promo-desc"><?= e($promo['description']) ?></p>
                        <?php endif; ?>
                        <div class="promo-prices">
                            <?php if ($oldPrice !== ''): ?>
                                <span class="promo-old"><?= e($oldPrice) ?></span>
                            <?php endif; ?>
                            <span class="promo-new"><?= e($newPrice) ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('home.features.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('home.features.title')) ?></h2>
        </div>
        <div class="grid grid-3">
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('home.feature.events.title')) ?></h3>
                <p><?= e(t('home.feature.events.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('home.feature.cafeteria.title')) ?></h3>
                <p><?= e(t('home.feature.cafeteria.desc')) ?></p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title"><?= e(t('home.feature.community.title')) ?></h3>
                <p><?= e(t('home.feature.community.desc')) ?></p>
            </article>
        </div>
    </div>
</section>

<script>
(function () {
    var section = document.getElementById('menu');
    if (!section) return;

    var tabs   = section.querySelectorAll('.menu-tab');
    var items  = section.querySelectorAll('.menu-item');
    var empty  = section.querySelector('.menu-empty-results');
    if (!tabs.length || !items.length) return;

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var cat = tab.getAttribute('data-cat');

            tabs.forEach(function (t) {
                var on = t === tab;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            var visible = 0;
            items.forEach(function (item) {
                var show = cat === 'all' || item.getAttribute('data-cat') === cat;
                item.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            if (empty) empty.hidden = visible > 0;
        });
    });
})();
</script>
