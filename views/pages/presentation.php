<?php

declare(strict_types=1);

/** @var array<string,mixed>|null $page */
/** @var int $usersCount */
/** @var int $eventsCount */
?>
<!-- ===================== HERO ===================== -->
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="halo halo-teal" aria-hidden="true" style="top:auto;bottom:-200px;left:-200px;right:auto;"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('about.eyebrow')) ?></span>
        <h1 class="page-title"><?= e(t('about.title')) ?></h1>
        <p class="page-lead"><?= e(t('about.lead')) ?></p>
    </div>
</header>

<!-- ===================== MISSION ===================== -->
<section class="section">
    <div class="container">
        <div class="about-hero-card surface glass">
            <div class="about-hero-icon">🎯</div>
            <div>
                <h2 class="section-title" style="color:var(--primary)"><?= e(t('about.mission')) ?></h2>
                <p class="about-hero-text"><?= e(t('about.mission.desc')) ?></p>
            </div>
        </div>
    </div>
</section>

<!-- ===================== VALEURS ===================== -->
<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('about.values.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('about.values.title')) ?></h2>
        </div>
        <div class="grid grid-3">
            <article class="about-value-card">
                <span class="about-value-icon">🤝</span>
                <h3><?= e(t('about.value.proximity')) ?></h3>
                <p><?= e(t('about.value.proximity.desc')) ?></p>
            </article>
            <article class="about-value-card">
                <span class="about-value-icon">🔥</span>
                <h3><?= e(t('about.value.passion')) ?></h3>
                <p><?= e(t('about.value.passion.desc')) ?></p>
            </article>
            <article class="about-value-card">
                <span class="about-value-icon">♻️</span>
                <h3><?= e(t('about.value.sharing')) ?></h3>
                <p><?= e(t('about.value.sharing.desc')) ?></p>
            </article>
        </div>
    </div>
</section>

<!-- ===================== CHIFFRES ===================== -->
<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('home.stats.aria')) ?></span>
            <h2 class="section-title"><?= e(t('about.stats.title')) ?></h2>
        </div>
        <div class="grid grid-4 about-stats">
            <div class="about-stat">
                <span class="about-stat-num"><?= e((string) max($usersCount, 0)) ?></span>
                <span class="about-stat-label"><?= e(t('home.stat.members')) ?></span>
            </div>
            <div class="about-stat">
                <span class="about-stat-num"><?= e((string) max($eventsCount, 0)) ?></span>
                <span class="about-stat-label"><?= e(t('home.stat.events')) ?></span>
            </div>
            <div class="about-stat">
                <span class="about-stat-num">100 %</span>
                <span class="about-stat-label"><?= e(t('home.stat.student')) ?></span>
            </div>
            <div class="about-stat">
                <span class="about-stat-num">0</span>
                <span class="about-stat-label"><?= e(t('home.stat.easy')) ?></span>
            </div>
        </div>
    </div>
</section>

<!-- ===================== VISION ===================== -->
<section class="section section-alt">
    <div class="container">
        <div class="about-vision surface glass">
            <span class="about-vision-quote">❝</span>
            <h2 class="section-title" style="color:var(--primary)"><?= e(t('about.vision.title')) ?></h2>
            <p class="about-vision-text"><?= e(t('about.vision.desc')) ?></p>
        </div>
    </div>
</section>

<!-- ===================== ESPACES ===================== -->
<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('about.spaces.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('about.spaces.title')) ?></h2>
            <p class="lead"><?= e(t('about.spaces.desc')) ?></p>
        </div>
        <div class="grid grid-2 about-spaces">
            <article class="about-space-card">
                <span class="about-space-icon">📚</span>
                <h3><?= e(t('about.spaces.free')) ?></h3>
            </article>
            <article class="about-space-card">
                <span class="about-space-icon">☕</span>
                <h3><?= e(t('about.spaces.local')) ?></h3>
            </article>
        </div>
    </div>
</section>

<!-- ===================== ÉVÉNEMENTS PHARES ===================== -->
<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><?= e(t('about.events.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('about.events.title')) ?></h2>
            <p class="lead"><?= e(t('about.events.intro')) ?></p>
        </div>
        <div class="grid grid-3 about-events">
            <article class="about-event-card">
                <span class="about-event-emoji">💻</span>
                <h3><?= e(t('about.events.nuitinfo.title')) ?></h3>
                <p><?= e(t('about.events.nuitinfo.desc')) ?></p>
            </article>
            <article class="about-event-card">
                <span class="about-event-emoji">🍻</span>
                <h3><?= e(t('about.events.afterworks.title')) ?></h3>
                <p><?= e(t('about.events.afterworks.desc')) ?></p>
            </article>
            <article class="about-event-card">
                <span class="about-event-emoji">🥩</span>
                <h3><?= e(t('about.events.bbq.title')) ?></h3>
                <p><?= e(t('about.events.bbq.desc')) ?></p>
            </article>
            <article class="about-event-card">
                <span class="about-event-emoji">🎳</span>
                <h3><?= e(t('about.events.bowling.title')) ?></h3>
                <p><?= e(t('about.events.bowling.desc')) ?></p>
            </article>
            <article class="about-event-card">
                <span class="about-event-emoji">🥃</span>
                <h3><?= e(t('about.events.bar.title')) ?></h3>
                <p><?= e(t('about.events.bar.desc')) ?></p>
            </article>
            <article class="about-event-card about-event-more">
                <span class="about-event-emoji">✨</span>
                <h3><?= e(t('about.events.closing')) ?></h3>
            </article>
        </div>
    </div>
</section>

<!-- ===================== COORDONNÉES ===================== -->
<section class="section">
    <div class="container">
        <div class="about-contact surface glass">
            <span class="about-contact-icon">📧</span>
            <div>
                <h2 class="section-title" style="color:var(--primary)"><?= e(t('about.contact.title')) ?></h2>
                <p class="lead" style="margin:0"><?= e(t('about.contact.label')) ?> — <?= e(t('about.contact.address')) ?></p>
            </div>
        </div>
    </div>
</section>

<!-- ===================== CARTE ===================== -->
<?php require __DIR__ . '/../partials/map.php'; ?>

<!-- ===================== CTA ===================== -->
<section class="section cta">
    <div class="container cta-inner">
        <h2 class="section-title"><?= e(t('about.cta.title')) ?></h2>
        <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="<?= e(url('/register')) ?>"><?= e(t('home.cta.join')) ?></a>
            <a class="btn btn-outline btn-lg" href="<?= e(url('/events')) ?>"><?= e(t('home.cta.events')) ?></a>
        </div>
    </div>
</section>

<style>
/* Mission hero */
.about-hero-card {
    display: flex; gap: 1.5rem; align-items: flex-start;
    padding: 2rem; border-radius: 16px;
    border-left: 4px solid var(--primary);
}
.about-hero-icon { font-size: 2.5rem; flex-shrink: 0; line-height: 1; }
.about-hero-text { font-size: 1.05rem; line-height: 1.7; color: var(--foreground); margin: 0.5rem 0 0; }

/* Valeurs */
.about-value-card {
    text-align: center; padding: 2rem 1.5rem;
    background: rgba(255,255,255,0.02); border: 1px solid var(--border);
    border-radius: 16px; transition: all 0.2s ease;
}
.about-value-card:hover {
    transform: translateY(-4px); border-color: rgba(72,189,211,0.3);
    background: rgba(72,189,211,0.03);
}
.about-value-icon { font-size: 2.5rem; display: block; margin-bottom: 0.75rem; }
.about-value-card h3 {
    font-size: 1.1rem; font-weight: 800; color: var(--primary);
    margin: 0 0 0.5rem; text-transform: none;
}
.about-value-card p { font-size: 0.9rem; color: var(--muted); margin: 0; line-height: 1.6; }

/* Chiffres */
.about-stat {
    text-align: center; padding: 1.5rem 1rem;
    background: rgba(255,255,255,0.02); border: 1px solid var(--border);
    border-radius: 14px; transition: all 0.2s ease;
}
.about-stat:hover { border-color: rgba(72,189,211,0.3); }
.about-stat-num {
    display: block; font-size: 2.5rem; font-weight: 900;
    background: linear-gradient(135deg, var(--secondary), var(--primary));
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent;
    line-height: 1;
}
.about-stat-label {
    display: block; font-size: 0.82rem; color: var(--muted);
    margin-top: 0.4rem; font-weight: 600;
}

/* Vision */
.about-vision {
    padding: 2.5rem; border-radius: 16px; text-align: center;
    position: relative; overflow: hidden;
    border: 1px solid var(--border);
}
.about-vision-quote {
    position: absolute; top: -1rem; left: 1rem;
    font-size: 6rem; font-weight: 900;
    color: rgba(72,189,211,0.08); line-height: 1;
}
.about-vision-text {
    font-size: 1.1rem; line-height: 1.8; color: var(--foreground);
    margin: 0.75rem auto 0; max-width: 600px; font-style: italic;
}

/* Espaces */
.about-spaces { gap: 1.25rem; }
.about-space-card {
    display: flex; align-items: center; gap: 1rem;
    padding: 1.75rem; border-radius: 14px;
    background: rgba(255,255,255,0.02); border: 1px solid var(--border);
    transition: all 0.2s ease;
}
.about-space-card:hover {
    transform: translateX(4px); border-color: rgba(72,189,211,0.3);
}
.about-space-icon { font-size: 2.5rem; flex-shrink: 0; }
.about-space-card h3 { font-size: 1rem; font-weight: 700; color: var(--foreground); margin: 0; text-transform: none; line-height: 1.5; }

/* Événements */
.about-events { gap: 1.25rem; }
.about-event-card {
    padding: 1.75rem; border-radius: 14px;
    background: rgba(255,255,255,0.02); border: 1px solid var(--border);
    transition: all 0.2s ease;
}
.about-event-card:hover {
    transform: translateY(-3px); border-color: rgba(72,189,211,0.3);
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
}
.about-event-emoji { font-size: 2rem; display: block; margin-bottom: 0.5rem; }
.about-event-card h3 { font-size: 1rem; font-weight: 800; color: var(--primary); margin: 0 0 0.4rem; text-transform: none; }
.about-event-card p { font-size: 0.85rem; color: var(--muted); margin: 0; line-height: 1.5; }
.about-event-more {
    display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
    border-style: dashed; background: rgba(72,189,211,0.03);
}
.about-event-more h3 { color: var(--foreground); }

/* Contact */
.about-contact {
    display: flex; align-items: center; gap: 1.25rem;
    padding: 1.75rem 2rem; border-radius: 14px;
    border-left: 4px solid var(--primary);
}
.about-contact-icon { font-size: 2rem; flex-shrink: 0; }

@media (max-width: 640px) {
    .about-hero-card, .about-contact { flex-direction: column; text-align: center; }
    .about-hero-icon, .about-contact-icon { margin: 0 auto; }
    .about-vision { padding: 1.5rem; }
    .about-vision-quote { font-size: 4rem; }
}
</style>
