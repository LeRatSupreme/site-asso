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
    <div class="ae-aurora" aria-hidden="true"></div>
    <div class="ae-dots dot-grid" aria-hidden="true"></div>
    <div class="container hero-grid">
        <div class="hero-content">
            <span class="ae-pill">
                <span class="ae-pill-dot" aria-hidden="true"></span>
                <?= e(t('home.eyebrow')) ?>
            </span>
            <h1 class="hero-title ae-title-grad">
                <?= e(t('home.title.line1')) ?>
                <?= e(t('home.title.line2')) ?>
            </h1>
            <p class="hero-lead">
                <?= e(tc($description ?: t('home.description'))) ?>
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="<?= e(url('/presentation')) ?>"><?= e(t('home.cta.join')) ?></a>
                <a class="btn btn-outline btn-lg" href="<?= e(url('/events')) ?>"><?= e(t('home.cta.events')) ?></a>
            </div>
        </div>

        <aside class="hero-stats surface glass hm-stats" data-tilt data-tilt-base="rotate(1.2deg)" aria-hidden="true">
            <div class="hero-code js-type-code" aria-hidden="true" data-lines="$ whoami|etudiant@iut-info --but=calais|$ aeic join --bonne-humeur|> evenements: nuit-info, bbq, bowling, bar|// cafe.charge() => 100%"><span class="code-cursor"></span></div>
        </aside>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head ae-reveal">
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

<section class="section section-alt" id="promos">
    <div class="container">
        <div class="section-head ae-reveal">
            <span class="eyebrow"><?= e(t('home.promos.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('home.promos.title')) ?></h2>
        </div>

        <?php if ($allPromoEmpty): ?>
            <div class="promo-empty surface glass">
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
                    <article class="promo-card surface glass ae-reveal">
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

<section class="section" id="menu">
    <div class="container">
        <div class="section-head ae-reveal">
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
            // Onglets : un par catégorie (le premier est actif par défaut,
            // l'ordre vient de la colonne `order` des catégories).
            $catTabs = [];
            foreach ($menuCategories as $cat) {
                $catTabs[] = [
                    'key'   => (string) $cat['id'],
                    'name'  => tc((string) $cat['name']),
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
                        <?= e($tab['name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="menu-grid">
                <?php foreach ($menuCategories as $cat): ?>
                    <?php foreach ($cat['products'] as $product): ?>
                    <?php
                    $name  = tc((string) ($product['name'] ?? ''));
                    $img   = trim((string) ($product['image'] ?? ''));
                    $imgUrl = $img !== '' ? (is_absolute_url($img) ? $img : asset(ltrim($img, '/'))) : '';
                    // Stock issu de l'inventaire compta (cache 5 min) apparié au nom ;
                    // null = aucune clé d'inventaire correspondante → rien d'affiché.
                    $menuStock = isset($product['menu_stock']) && is_int($product['menu_stock'])
                        ? max(0, $product['menu_stock'])
                        : null;
                    $isOut = $menuStock !== null && $menuStock <= 0;
                    $catName = trim((string) ($product['category_name'] ?? ''));
                    ?>
                        <article class="menu-item surface glass<?= $isOut ? ' is-out' : '' ?>" data-cat="<?= e((string) $cat['id']) ?>">
                            <?php if ($catName !== ''): ?>
                                <span class="menu-item-cat"><?= e(tc($catName)) ?></span>
                            <?php endif; ?>
                            <?php if ($imgUrl !== ''): ?>
                                <img src="<?= e($imgUrl) ?>" alt="" class="menu-item-img" loading="lazy" onerror="this.style.display='none';">
                            <?php endif; ?>
                            <span class="menu-item-name"><?= e($name) ?></span>
                            <span class="menu-item-price"><?= e(formatPrice($product['price'] ?? 0)) ?></span>
                            <?php if ($isOut): ?>
                                <span class="badge badge-danger">Épuisé</span>
                            <?php elseif ($menuStock === 1): ?>
                                <span class="menu-item-stock">1 unité</span>
                            <?php elseif ($menuStock !== null): ?>
                                <span class="menu-item-stock"><?= e((string) $menuStock) ?> en stock</span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>

            <p class="menu-empty-results muted" hidden><?= e(t('home.menu.empty')) ?></p>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head ae-reveal">
            <span class="eyebrow"><?= e(t('home.features.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('home.features.title')) ?></h2>
        </div>
        <div class="grid grid-3">
            <article class="hm-feature ae-panel ae-reveal">
                <div class="hm-feature-body">
                    <pre class="hm-feature-code js-type-code" aria-hidden="true" data-lines="$ aeic agenda --a-venir|> soirees, lan, conferences|// un agenda pense pour les etudiants en info"><span class="code-cursor"></span></pre>
                </div>
            </article>
            <article class="hm-feature ae-panel ae-reveal">
                <div class="hm-feature-body">
                    <pre class="hm-feature-code js-type-code" aria-hidden="true" data-lines="$ cafeteria commander &quot;cafe&quot;|> prix: etudiant --solde ok|// pret a recuperer entre deux cours"><span class="code-cursor"></span></pre>
                </div>
            </article>
            <article class="hm-feature ae-panel ae-reveal">
                <div class="hm-feature-body">
                    <pre class="hm-feature-code js-type-code" aria-hidden="true" data-lines="$ git clone entraide|> projets, coups de main, campus|// un reseau qui fait avancer"><span class="code-cursor"></span></pre>
                </div>
            </article>
        </div>
    </div>
</section>

<!-- ===================== CTA ===================== -->
<section class="section hm-cta">
    <div class="container">
        <div class="ae-cta-panel ae-reveal">
            <div class="ae-cta-dots" aria-hidden="true"></div>
            <span class="ae-cta-halo ae-cta-halo-1" aria-hidden="true"></span>
            <span class="ae-cta-halo ae-cta-halo-2" aria-hidden="true"></span>
            <div class="ae-cta-inner">
                <h2 class="section-title"><?= e(t('about.cta.title')) ?></h2>
                <div class="ae-cta-actions">
                    <a class="btn btn-lg ae-btn-light" href="<?= e(url('/register')) ?>"><?= e(t('home.cta.join')) ?></a>
                    <a class="btn btn-lg ae-btn-glass" href="<?= e(url('/events')) ?>"><?= e(t('home.cta.events')) ?></a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ===================== COORDONNÉES ===================== -->
<section class="section section-alt">
    <div class="container">
        <div class="about-contact ae-reveal">
            <div class="about-contact-body">
                <h2 class="section-title about-contact-title"><?= e(t('about.contact.title')) ?></h2>
                <p class="lead about-contact-text"><?= e(t('about.contact.label')) ?> — <?= e(t('about.contact.address')) ?></p>
            </div>
            <div class="about-contact-actions">
                <a class="btn btn-primary" href="<?= e(url('/presentation')) ?>#ou-nous-trouver"><?= e(t('map.title')) ?></a>
            </div>
        </div>
    </div>
</section>

<style>
/* ============ Accueil — spécifique ============ */

/* Hero : carte stats façon « carte étudiant » */
.hm-stats {
    padding: 2rem 1.9rem;
    border-radius: 24px;
    transform: rotate(1.2deg);
    transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1), box-shadow 0.35s ease;
}
.hm-stats:hover {
    transform: rotate(0deg) translateY(-4px);
    box-shadow: 0 30px 70px rgba(0, 0, 0, 0.45), 0 0 40px rgba(58, 155, 184, 0.14);
}
.hm-stats .stat-value {
    font-size: 2.1rem;
    background: linear-gradient(135deg, #8b7ae0, #3a9bb8 60%, #7fd0e4);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
}
.hm-stats .stat:nth-child(even) { border-left: 1px solid var(--border); padding-left: 1.25rem; }
.hm-stats .stat:nth-child(n+3) { border-top: 1px solid var(--border); padding-top: 1.1rem; }

/* Cartes « atouts » avec médaille */
.hm-feature {
    display: flex;
    align-items: flex-start;
    gap: 1.25rem;
    padding: 1.75rem 1.6rem;
    height: 100%;
    box-sizing: border-box;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.hm-feature:hover {
    transform: translateY(-5px);
    box-shadow: 0 18px 44px rgba(0, 0, 0, 0.35);
}
.hm-feature-body { min-width: 0; }
.hm-feature .card-title {
    color: var(--primary);
    font-size: 1.05rem;
    text-transform: none;
    letter-spacing: -0.01em;
    margin-bottom: 0.45rem;
}
.hm-feature p {
    color: var(--muted);
    font-size: 0.92rem;
    line-height: 1.65;
    margin: 0;
}
.hm-feature-code {
    margin: 0;
    font-family: ui-monospace, 'SF Mono', Consolas, monospace;
    font-size: 0.7rem;
    line-height: 1.6;
    white-space: pre-wrap;
    color: rgba(255, 255, 255, 0.38);
    pointer-events: none;
    user-select: none;
}
[data-theme="light"] .hm-feature-code { color: rgba(15, 23, 42, 0.4); }

/* Onglets menu : état actif dégradé */
#menu .menu-tab {
    border-radius: 999px;
    padding: 0.55rem 1.1rem;
    font-weight: 700;
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
}
#menu .menu-tab:hover { border-color: rgba(72, 189, 211, 0.4); color: var(--foreground); }
#menu .menu-tab.is-active {
    background: linear-gradient(120deg, rgba(74, 61, 143, 0.5), rgba(58, 155, 184, 0.4));
    border-color: rgba(72, 189, 211, 0.5);
    color: #fff;
}
[data-theme="light"] #menu .menu-tab.is-active {
    background: linear-gradient(120deg, rgba(74, 61, 143, 0.85), rgba(45, 122, 148, 0.8));
}

/* CTA : resserre l'espace au-dessus du panneau */
.hm-cta { padding-top: 1rem; }

@media (max-width: 980px) {
    .hm-stats { transform: none; }
}
@media (max-width: 640px) {
    .hm-feature { flex-direction: column; padding: 1.6rem 1.4rem; gap: 1rem; }
}

/* Coordonnées */
.about-contact {
    position: relative;
    display: flex;
    align-items: center;
    gap: 1.75rem;
    padding: 2.25rem 2.5rem;
    border-radius: 24px;
    background:
        radial-gradient(80% 140% at 0% 0%, rgba(58, 155, 184, 0.1), transparent 55%),
        rgba(255, 255, 255, 0.03);
}
.about-contact::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    padding: 1px;
    background: linear-gradient(135deg, rgba(74, 61, 143, 0.6), rgba(58, 155, 184, 0.5) 50%, rgba(255, 255, 255, 0.06));
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    mask-composite: exclude;
    pointer-events: none;
}
.about-contact > * { position: relative; z-index: 1; }
.about-contact-body { flex: 1; min-width: 0; }
.about-contact-title { font-size: clamp(1.4rem, 3vw, 2rem); }
.about-contact-text { margin: 0.5rem 0 0; font-size: 1rem; }
.about-contact-actions { flex-shrink: 0; }
@media (max-width: 980px) {
    .about-contact { flex-direction: column; text-align: center; padding: 2rem 1.5rem; gap: 1.25rem; }
    .about-contact-actions { width: 100%; }
    .about-contact-actions .btn { width: 100%; }
}
</style>

<script>
(function () {
    var section = document.getElementById('menu');
    if (!section) return;

    var tabs   = section.querySelectorAll('.menu-tab');
    var items  = section.querySelectorAll('.menu-item');
    var empty  = section.querySelector('.menu-empty-results');
    if (!tabs.length || !items.length) return;

    function activate(tab) {
        var cat = tab.getAttribute('data-cat');

        tabs.forEach(function (t) {
            var on = t === tab;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        var visible = 0;
        items.forEach(function (item) {
            var show = item.getAttribute('data-cat') === cat;
            item.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (empty) empty.hidden = visible > 0;
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            activate(tab);
        });
    });

    // État initial : la première catégorie est active et filtrée dès le
    // chargement (l'onglet « Tout » n'existe plus).
    if (tabs.length) activate(tabs[0]);
})();
</script>
