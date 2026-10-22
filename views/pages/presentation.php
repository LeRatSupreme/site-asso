<?php

declare(strict_types=1);

/**
 * Page « L'association » (/presentation).
 *
 * @var array<string,mixed>|null $page
 */
?>
<!-- ===================== HERO ===================== -->
<header class="page-hero about-hero">
    <div class="about-aurora" aria-hidden="true"></div>
    <div class="about-hero-dots dot-grid" aria-hidden="true"></div>
    <div class="container">
        <div class="about-hero-grid">
            <div class="about-hero-copy">
                <span class="about-pill">
                    <span class="about-pill-dot" aria-hidden="true"></span>
                    <?= e(t('about.eyebrow')) ?>
                </span>
                <h1 class="page-title about-title-grad"><?= e(t('about.title')) ?></h1>
                <p class="page-lead"><?= e(t('about.lead')) ?></p>
                <div class="about-hero-actions">
                    <a class="btn btn-primary btn-lg" href="<?= e(url('/register')) ?>"><?= e(t('home.cta.join')) ?></a>
                    <a class="btn btn-outline btn-lg" href="<?= e(url('/events')) ?>"><?= e(t('home.cta.events')) ?></a>
                </div>
            </div>

            <!-- Carte « étudiant » décorative et interactive -->
            <div class="ae-idcard" data-tilt data-tilt-base="rotate(1.6deg)" aria-hidden="true">
                <div class="ae-idcard-band">
                    <span class="ae-idcard-logo">AE</span>
                    <span class="ae-idcard-id">
                        <span class="ae-idcard-label">CARTE / STUDENT</span>
                        <span class="ae-idcard-name">AEIC</span>
                    </span>
                    <span class="ae-idcard-badge">100 %</span>
                </div>
                <div class="ae-idcard-body">
                    <div class="ae-idcard-row">
                        <span><?= e(t('home.stat.student')) ?></span>
                    </div>
                    <div class="ae-idcard-row">
                        <span><?= e(t('home.feature.cafeteria.title')) ?></span>
                    </div>
                    <div class="ae-idcard-row">
                        <span><?= e(t('about.value.proximity')) ?></span>
                    </div>
                </div>
                <div class="ae-idcard-code">|||| || &#124;&#124; ||| &#124; |||| &#124;&#124; ||||| &#124; ||</div>
            </div>
        </div>
    </div>
</header>

<!-- ===================== MISSION ===================== -->
<section class="section">
    <div class="container">
        <div class="about-mission about-reveal">
            <span class="about-mission-code" aria-hidden="true">&lt;mission /&gt;</span>
            <div class="about-mission-body">
                <h2 class="section-title about-accent-title"><?= e(t('about.mission')) ?></h2>
                <p class="about-mission-text"><?= e(t('about.mission.desc')) ?></p>
            </div>
        </div>
    </div>
</section>

<!-- ===================== VISION ===================== -->
<section class="section">
    <div class="container">
        <div class="about-vision about-reveal">
            <span class="about-vision-mark" aria-hidden="true">«</span>
            <div class="about-vision-body">
                <span class="eyebrow"><?= e(t('about.vision.title')) ?></span>
                <p class="about-vision-text"><?= e(t('about.vision.desc')) ?></p>
            </div>
        </div>
    </div>
</section>

<!-- ===================== ESPACES ===================== -->
<section class="section section-alt">
    <div class="container">
        <div class="section-head about-reveal">
            <span class="eyebrow"><?= e(t('about.spaces.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('about.spaces.title')) ?></h2>
            <p class="lead"><?= e(t('about.spaces.desc')) ?></p>
        </div>
        <div class="about-spaces">
            <article class="about-space about-reveal">
                <pre class="about-space-code js-type-code" aria-hidden="true" data-lines="$ open --acces-libre|> travail_en_groupe, detente, discussions|// ouvert pendant nos horaires d ouverture"><span class="code-cursor"></span></pre>
            </article>
            <article class="about-space about-reveal">
                <pre class="about-space-code js-type-code" aria-hidden="true" data-lines="$ cd local-aeic &amp;&amp; ls|> cafeteria --prix-etudiant, jeux_de_societe, entraide|// des membres prets a donner un coup de main"><span class="code-cursor"></span></pre>
            </article>
        </div>
    </div>
</section>

<!-- ===================== ÉVÉNEMENTS PHARES ===================== -->
<section class="section">
    <div class="container">
        <div class="section-head about-reveal">
            <span class="eyebrow"><?= e(t('about.events.eyebrow')) ?></span>
            <h2 class="section-title"><?= e(t('about.events.title')) ?></h2>
            <p class="lead"><?= e(t('about.events.intro')) ?></p>
        </div>
        <div class="about-events">
            <article class="about-event about-event-2 about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.afterworks.title')) ?></h3>
                    <pre class="about-event-code-flow js-type-code" aria-hidden="true" data-lines="$ afterwork start --18h00|> verre, detente, nouvelles_rencontres|// la pression redescend apres les cours"><span class="code-cursor"></span></pre>
                </div>
            </article>
            <article class="about-event about-event-3 about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.bbq.title')) ?></h3>
                    <pre class="about-event-code-flow js-type-code" aria-hidden="true" data-lines="$ bbq start --grille|> grillades, grandes_tables, bonne_humeur|// on vient pour le repas, on reste pour l ambiance"><span class="code-cursor"></span></pre>
                </div>
            </article>
            <article class="about-event about-event-1 about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.nuitinfo.title')) ?></h3>
                    <p><?= e(t('about.events.nuitinfo.desc')) ?></p>
                    <pre class="about-event-code-flow js-type-code" aria-hidden="true" data-lines="$ git clone nuit-de-linfo|> coder, innover, relever_des_defis --en-equipe|// jusqu au petit matin, cafe compris"><span class="code-cursor"></span></pre>
                </div>
            </article>
            <article class="about-event about-event-4 about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.bowling_bar.title')) ?></h3>
                    <pre class="about-event-code-flow js-type-code" aria-hidden="true" data-lines="$ bowling play --equipes|> strikes, fous_rires, revanches|$ bar sortie --fin-de-journee|> ambiance: detendue --prise-de-tete 0"><span class="code-cursor"></span></pre>
                </div>
            </article>
            <article class="about-event about-event-more about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.closing')) ?></h3>
                </div>
            </article>
        </div>
    </div>
</section>

<!-- ===================== CARTE ===================== -->
<?php require __DIR__ . '/../partials/map.php'; ?>

<!-- ===================== CTA ===================== -->
<section class="section about-cta">
    <div class="container">
        <div class="about-cta-panel">
            <div class="about-cta-dots" aria-hidden="true"></div>
            <div class="about-cta-inner">
                <h2 class="section-title"><?= e(t('about.cta.title')) ?></h2>
                <div class="about-cta-actions">
                    <a class="btn btn-lg about-btn-light" href="<?= e(url('/register')) ?>"><?= e(t('home.cta.join')) ?></a>
                    <a class="btn btn-lg about-btn-glass" href="<?= e(url('/events')) ?>"><?= e(t('home.cta.events')) ?></a>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
/* ============ Utilitaires ============ */
.about-accent-title { color: var(--primary); }

/* ============ Reveal au scroll (progressif : visible sans JS) ============ */
.about-reveal-pending { opacity: 0; transform: translateY(20px); }
.about-reveal-pending.about-reveal-in {
    opacity: 1; transform: translateY(0);
    transition: opacity 0.6s ease, transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
}

/* ============ Hero ============ */
.about-hero { padding: 5.5rem 0 5rem; }
/* Ancien dégradé « aurora » : voile uni très léger, sans animation
   (sobriété, lot 6F). */
.about-aurora {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: rgba(72, 189, 211, 0.04);
}
.about-hero-dots {
    position: absolute;
    inset: 0;
    pointer-events: none;
    -webkit-mask-image: radial-gradient(60% 70% at 78% 30%, #000 0%, transparent 100%);
    mask-image: radial-gradient(60% 70% at 78% 30%, #000 0%, transparent 100%);
}
.about-hero-grid {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 3.5rem;
    align-items: center;
}
.about-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.45rem 1rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--primary);
    background: rgba(72, 189, 211, 0.08);
    border: 1px solid rgba(72, 189, 211, 0.3);
    margin-bottom: 1.4rem;
}
.about-pill-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--primary);
}
/* Ancien titre en dégradé : couleur pleine (sobriété, lot 6F). */
.about-title-grad { color: #fff; }
[data-theme="light"] .about-title-grad { color: #1e293b; }
.about-hero-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 2rem; }

/* ============ Mission ============ */
.about-mission {
    position: relative;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 2.25rem;
    align-items: center;
    padding: 2.75rem;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border);
}
.about-mission > * { position: relative; z-index: 1; }
.about-mission-code {
    position: absolute;
    top: 1.1rem;
    right: 1.5rem;
    font-family: ui-monospace, 'SF Mono', Consolas, monospace;
    font-size: 0.85rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.10);
    letter-spacing: 0.04em;
    user-select: none;
    z-index: 0 !important;
}
[data-theme="light"] .about-mission-code { color: rgba(15, 23, 42, 0.10); }
.about-mission .eyebrow { margin-bottom: 0.5rem; }
.about-mission-text {
    font-size: 1.08rem;
    line-height: 1.75;
    color: var(--foreground);
    margin: 0.9rem 0 0;
    max-width: 70ch;
}

/* ============ Vision — citation asymétrique ============ */
.about-vision {
    position: relative;
    display: grid;
    grid-template-columns: auto 1fr;
    align-items: center;
    gap: 2rem;
    padding: 2.75rem 3rem 2.75rem 2.5rem;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border);
    overflow: hidden;
}
.about-vision::after {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: var(--primary);
}
/* Ancien guillemet en dégradé : filigrane uni discret (sobriété, lot 6F). */
.about-vision-mark {
    font-size: 7.5rem;
    font-weight: 900;
    line-height: 0.6;
    color: rgba(255, 255, 255, 0.10);
    user-select: none;
    transform: translateY(14px);
}
[data-theme="light"] .about-vision-mark { color: rgba(15, 23, 42, 0.10); }
.about-vision-text {
    font-size: clamp(1.1rem, 2.2vw, 1.4rem);
    line-height: 1.8;
    color: var(--foreground);
    font-style: italic;
    margin: 0.9rem 0 0;
    max-width: 52rem;
}

/* ============ Espaces — lignes horizontales ============ */
.about-spaces {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}
.about-space {
    display: flex;
    align-items: flex-start;
    gap: 1.4rem;
    padding: 1.9rem;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--border);
    transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
}
.about-space:hover {
    transform: translateY(-5px);
    border-color: rgba(72, 189, 211, 0.4);
    box-shadow: 0 22px 50px rgba(0, 0, 0, 0.4);
}
.about-space-code {
    margin: 0;
    min-height: 3.6rem;
    font-family: ui-monospace, 'SF Mono', Consolas, monospace;
    font-size: 0.72rem;
    line-height: 1.65;
    white-space: pre-wrap;
    color: rgba(255, 255, 255, 0.38);
    pointer-events: none;
    user-select: none;
}
[data-theme="light"] .about-space-code { color: rgba(15, 23, 42, 0.4); }

/* ============ Événements — tickets ============ */
.about-events {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.1rem;
}
.about-event {
    position: relative;
    display: flex;
    align-items: stretch;
    border-radius: 18px;
    border: 1px solid var(--border);
    background: rgba(255, 255, 255, 0.03);
    overflow: hidden;
    transition: transform 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
}
.about-event:hover {
    transform: translateY(-4px);
    border-color: rgba(72, 189, 211, 0.35);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.35);
}
.about-event-body { padding: 1.15rem 1.3rem; min-width: 0; }
.about-event h3 {
    font-size: 1rem;
    font-weight: 800;
    color: var(--primary);
    margin: 0 0 0.4rem;
    text-transform: none;
    letter-spacing: -0.01em;
}
.about-event p {
    font-size: 0.87rem;
    color: var(--muted);
    margin: 0;
    line-height: 1.6;
}
.about-event-more {
    grid-column: 1 / -1;
    border-style: dashed;
    border-color: rgba(72, 189, 211, 0.35);
    background: rgba(72, 189, 211, 0.04);
}
.about-event-more h3 { margin: 0; color: var(--foreground); font-size: 0.95rem; font-weight: 600; }

/* ============ CTA — panneau ============ */
/* Ancien panneau en dégradé : fond uni (sobriété, lot 6F). */
.about-cta { padding-top: 1rem; }
.about-cta-panel {
    position: relative;
    overflow: hidden;
    border-radius: 28px;
    padding: 4rem 2rem;
    text-align: center;
    background: var(--secondary);
    box-shadow: 0 30px 70px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.18);
}
.about-cta-dots {
    position: absolute;
    inset: 0;
    background-image: radial-gradient(circle, rgba(255, 255, 255, 0.14) 1px, transparent 1px);
    background-size: 24px 24px;
    -webkit-mask-image: radial-gradient(70% 90% at 50% 0%, #000 0%, transparent 100%);
    mask-image: radial-gradient(70% 90% at 50% 0%, #000 0%, transparent 100%);
    pointer-events: none;
}
.about-cta-inner { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; gap: 1.75rem; }
.about-cta .section-title { color: #fff; }
.about-cta-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.75rem; }
.about-btn-light { background: #fff; color: #1e293b; }
.about-btn-light:hover { background: #e8eef5; color: #1e293b; }
.about-btn-glass { background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.45); color: #fff; }
.about-btn-glass:hover { background: rgba(255, 255, 255, 0.2); color: #fff; border-color: #fff; }

/* ============ Décor code (cartes événements) ============ */
.about-event-code-flow {
    margin: 0.35rem 0 0;
    min-height: 3.4rem;
    font-family: ui-monospace, 'SF Mono', Consolas, monospace;
    font-size: 0.7rem;
    line-height: 1.6;
    white-space: pre-wrap;
    color: rgba(255, 255, 255, 0.55);
    pointer-events: none;
    user-select: none;
}
[data-theme="light"] .about-event-code-flow { color: rgba(15, 23, 42, 0.55); }

/* ============ Responsive ============ */
@media (max-width: 980px) {
    .about-hero-grid { grid-template-columns: 1fr; gap: 2.75rem; }
    .about-vision { grid-template-columns: 1fr; gap: 1.25rem; padding: 2.5rem 1.75rem; }
    .about-vision-mark { font-size: 5rem; transform: none; line-height: 1; }
    .about-spaces { grid-template-columns: 1fr; }
    .about-events { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .about-hero { padding: 4rem 0 3.5rem; }
    .about-mission { grid-template-columns: 1fr; text-align: center; padding: 2.25rem 1.5rem; gap: 1.5rem; }
    .about-mission-code { display: none; }
    .about-vision { padding: 2.25rem 1.5rem; }
    .about-space { flex-direction: column; padding: 1.75rem 1.5rem; gap: 1.1rem; }
    .about-cta-panel { padding: 3rem 1.5rem; }
}

</style>

<script>
(function () {
    'use strict';

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced || !('IntersectionObserver' in window)) {
        return;
    }

    var reveals = document.querySelectorAll('.about-reveal');

    Array.prototype.forEach.call(reveals, function (el, i) {
        el.classList.add('about-reveal-pending');
        el.style.transitionDelay = ((i % 3) * 70) + 'ms';
    });

    var revealObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) {
                return;
            }
            var el = entry.target;
            el.classList.add('about-reveal-in');
            revealObserver.unobserve(el);
            el.addEventListener('transitionend', function handler() {
                el.removeEventListener('transitionend', handler);
                el.classList.remove('about-reveal-pending');
                el.classList.remove('about-reveal-in');
                el.style.transitionDelay = '';
            });
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

    Array.prototype.forEach.call(reveals, function (el) {
        revealObserver.observe(el);
    });
})();
</script>
