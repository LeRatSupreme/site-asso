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
                <span class="about-vision-author">— <?= e(t('about.vision.author')) ?></span>
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
                <h3 class="about-space-text"><?= e(t('about.spaces.free')) ?></h3>
            </article>
            <article class="about-space about-reveal">
                <h3 class="about-space-text"><?= e(t('about.spaces.local')) ?></h3>
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
            <article class="about-event about-event-1 about-event-featured about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.nuitinfo.title')) ?></h3>
                    <p><?= e(t('about.events.nuitinfo.desc')) ?></p>
                </div>
                <span class="about-event-tag" aria-hidden="true">#code</span>
                <pre class="about-event-code" aria-hidden="true"><span class="code-cursor"></span></pre>
            </article>
            <article class="about-event about-event-2 about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.afterworks.title')) ?></h3>
                    <p><?= e(t('about.events.afterworks.desc')) ?></p>
                </div>
            </article>
            <article class="about-event about-event-3 about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.bbq.title')) ?></h3>
                    <p><?= e(t('about.events.bbq.desc')) ?></p>
                </div>
            </article>
            <article class="about-event about-event-4 about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.bowling.title')) ?></h3>
                    <p><?= e(t('about.events.bowling.desc')) ?></p>
                </div>
            </article>
            <article class="about-event about-event-5 about-reveal">
                <div class="about-event-body">
                    <h3><?= e(t('about.events.bar.title')) ?></h3>
                    <p><?= e(t('about.events.bar.desc')) ?></p>
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

<!-- ===================== COORDONNÉES ===================== -->
<section class="section section-alt">
    <div class="container">
        <div class="about-contact about-reveal">
            <div class="about-contact-body">
                <h2 class="section-title about-contact-title"><?= e(t('about.contact.title')) ?></h2>
                <p class="lead about-contact-text"><?= e(t('about.contact.label')) ?> — <?= e(t('about.contact.address')) ?></p>
            </div>
            <div class="about-contact-actions">
                <a class="btn btn-primary" href="#ou-nous-trouver"><?= e(t('map.title')) ?></a>
            </div>
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
            <span class="about-cta-halo about-cta-halo-1" aria-hidden="true"></span>
            <span class="about-cta-halo about-cta-halo-2" aria-hidden="true"></span>
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
.about-aurora {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(52% 62% at 82% 12%, rgba(58, 155, 184, 0.20), transparent 70%),
        radial-gradient(46% 58% at 8% 88%, rgba(74, 61, 143, 0.22), transparent 70%),
        radial-gradient(38% 46% at 50% 0%, rgba(139, 122, 224, 0.10), transparent 70%);
    animation: about-aurora 12s ease-in-out infinite alternate;
}
@keyframes about-aurora {
    from { opacity: 0.75; transform: scale(1); }
    to   { opacity: 1; transform: scale(1.06); }
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
    animation: about-pulse 2.2s ease-in-out infinite;
}
@keyframes about-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(72, 189, 211, 0.5); }
    50%      { box-shadow: 0 0 0 6px rgba(72, 189, 211, 0); }
}
.about-title-grad {
    background: linear-gradient(105deg, #fff 30%, #7fd0e4 62%, #b3a1ea 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    color: transparent;
}
[data-theme="light"] .about-title-grad {
    background: linear-gradient(105deg, #1e293b 30%, #2d7a94 62%, #4a3d8f 100%);
    -webkit-background-clip: text;
    background-clip: text;
}
.about-hero-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 2rem; }

/* ============ Médaillon dégradé (mission / contact) ============ */
.about-medal {
    width: 92px;
    height: 92px;
    display: grid;
    place-items: center;
    border-radius: 26px;
    font-size: 2.6rem;
    line-height: 1;
    background: linear-gradient(135deg, #4a3d8f, #3a9bb8);
    box-shadow: 0 16px 38px rgba(58, 155, 184, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.35);
    flex-shrink: 0;
}
.about-medal-sm { width: 72px; height: 72px; border-radius: 22px; font-size: 2.1rem; }

/* ============ Mission ============ */
.about-mission {
    position: relative;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 2.25rem;
    align-items: center;
    padding: 2.75rem;
    border-radius: 24px;
    background:
        radial-gradient(90% 150% at 100% 0%, rgba(58, 155, 184, 0.12), transparent 55%),
        radial-gradient(80% 150% at 0% 100%, rgba(74, 61, 143, 0.16), transparent 55%),
        rgba(255, 255, 255, 0.03);
}
.about-mission::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    padding: 1px;
    background: linear-gradient(135deg, rgba(74, 61, 143, 0.7), rgba(58, 155, 184, 0.6) 50%, rgba(255, 255, 255, 0.06));
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    mask-composite: exclude;
    pointer-events: none;
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
    background: linear-gradient(180deg, #8b7ae0, #3a9bb8);
}
.about-vision-mark {
    font-size: 7.5rem;
    font-weight: 900;
    line-height: 0.6;
    background: linear-gradient(135deg, #8b7ae0, #3a9bb8);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    user-select: none;
    transform: translateY(14px);
}
.about-vision-text {
    font-size: clamp(1.1rem, 2.2vw, 1.4rem);
    line-height: 1.8;
    color: var(--foreground);
    font-style: italic;
    margin: 0.9rem 0 0;
    max-width: 52rem;
}
.about-vision-author {
    display: block;
    margin-top: 1.1rem;
    font-style: normal;
    font-weight: 700;
    font-size: 0.95rem;
    color: var(--primary);
    letter-spacing: 0.02em;
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
.about-space-tile {
    display: grid;
    place-items: center;
    width: 78px;
    height: 78px;
    border-radius: 22px;
    font-size: 2.3rem;
    line-height: 1;
    background: linear-gradient(135deg, rgba(74, 61, 143, 0.75), rgba(58, 155, 184, 0.6));
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25), 0 12px 26px rgba(0, 0, 0, 0.3);
    flex-shrink: 0;
    transition: transform 0.3s ease;
}
.about-space:hover .about-space-tile { transform: scale(1.08) rotate(-4deg); }
.about-space-text {
    font-size: 0.98rem;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
    line-height: 1.7;
    color: var(--foreground);
    margin: 0;
}

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
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.35), 0 0 26px var(--cg, rgba(58, 155, 184, 0.15));
}
.about-event-stub {
    flex: 0 0 84px;
    display: grid;
    place-items: center;
    font-size: 2.1rem;
    line-height: 1;
    background: var(--stub, linear-gradient(160deg, rgba(58, 155, 184, 0.35), rgba(58, 155, 184, 0.1)));
    border-right: 2px dashed rgba(255, 255, 255, 0.14);
    transition: font-size 0.25s ease;
}
[data-theme="light"] .about-event-stub { border-right-color: rgba(15, 23, 42, 0.14); }
.about-event:hover .about-event-stub { font-size: 2.5rem; }
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
.about-event-1 { --c: #3a9bb8; --cg: rgba(58, 155, 184, 0.18); --stub: linear-gradient(160deg, rgba(58, 155, 184, 0.38), rgba(58, 155, 184, 0.1)); }
.about-event-2 { --c: #8b7ae0; --cg: rgba(139, 122, 224, 0.16); --stub: linear-gradient(160deg, rgba(139, 122, 224, 0.38), rgba(139, 122, 224, 0.1)); }
.about-event-3 { --c: #d4941a; --cg: rgba(212, 148, 26, 0.16); --stub: linear-gradient(160deg, rgba(212, 148, 26, 0.38), rgba(212, 148, 26, 0.1)); }
.about-event-4 { --c: #d4568c; --cg: rgba(212, 86, 140, 0.16); --stub: linear-gradient(160deg, rgba(212, 86, 140, 0.38), rgba(212, 86, 140, 0.1)); }
.about-event-5 { --c: #2d9a5f; --cg: rgba(45, 154, 95, 0.16); --stub: linear-gradient(160deg, rgba(45, 154, 95, 0.38), rgba(45, 154, 95, 0.1)); }
.about-event-featured { grid-column: 1 / -1; }
.about-event-featured .about-event-stub { flex-basis: 116px; font-size: 3.2rem; }
.about-event-featured .about-event-body { padding: 1.5rem 1.6rem; }
.about-event-featured h3 { font-size: 1.2rem; }
.about-event-featured p { font-size: 0.95rem; max-width: 70ch; }
.about-event-tag {
    position: absolute;
    top: 0.9rem;
    right: 1rem;
    padding: 0.2rem 0.65rem;
    border-radius: 999px;
    font-family: ui-monospace, 'SF Mono', Consolas, monospace;
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--c, #3a9bb8);
    background: rgba(58, 155, 184, 0.1);
    border: 1px solid rgba(58, 155, 184, 0.35);
}
.about-event-more {
    grid-column: 1 / -1;
    border-style: dashed;
    border-color: rgba(72, 189, 211, 0.35);
    background: rgba(72, 189, 211, 0.04);
}
.about-event-more .about-event-stub {
    background: transparent;
    border-right-color: rgba(72, 189, 211, 0.25);
    font-size: 1.8rem;
}
.about-event-more h3 { margin: 0; color: var(--foreground); font-size: 0.95rem; font-weight: 600; }

/* ============ Coordonnées ============ */
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

/* ============ CTA — panneau dégradé ============ */
.about-cta { padding-top: 1rem; }
.about-cta-panel {
    position: relative;
    overflow: hidden;
    border-radius: 28px;
    padding: 4rem 2rem;
    text-align: center;
    background: linear-gradient(120deg, #43386f 0%, #2c6f88 100%);
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
.about-cta-halo {
    position: absolute;
    width: 380px;
    height: 380px;
    border-radius: 50%;
    pointer-events: none;
}
.about-cta-halo-1 { top: -180px; right: -120px; background: radial-gradient(circle, rgba(127, 208, 228, 0.35), transparent 65%); }
.about-cta-halo-2 { bottom: -200px; left: -140px; background: radial-gradient(circle, rgba(179, 161, 234, 0.3), transparent 65%); }
.about-cta-inner { position: relative; z-index: 1; display: flex; flex-direction: column; align-items: center; gap: 1.75rem; }
.about-cta .section-title { color: #fff; }
.about-cta-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.75rem; }
.about-btn-light { background: #fff; color: #1e293b; }
.about-btn-light:hover { background: #e8eef5; color: #1e293b; }
.about-btn-glass { background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.45); color: #fff; }
.about-btn-glass:hover { background: rgba(255, 255, 255, 0.2); color: #fff; border-color: #fff; }

/* ============ Décor code (carte Nuit de l'Info) ============ */
.about-event-code {
    position: absolute;
    right: 1.4rem;
    bottom: 1rem;
    margin: 0;
    max-width: min(44%, 420px);
    font-family: ui-monospace, 'SF Mono', Consolas, monospace;
    font-size: 0.72rem;
    line-height: 1.55;
    text-align: left;
    white-space: pre-wrap;
    color: rgba(255, 255, 255, 0.4);
    pointer-events: none;
    user-select: none;
}
.about-event-code .code-cursor {
    display: inline-block;
    width: 7px;
    height: 0.95em;
    margin-left: 2px;
    vertical-align: -0.15em;
    background: var(--primary, #48bdd3);
    animation: about-code-blink 1.05s steps(2, start) infinite;
}
.about-event-code .l-cmd { color: rgba(127, 208, 228, 0.85); }
.about-event-code .l-code { color: rgba(179, 161, 234, 0.8); }
.about-event-code .l-rem { color: rgba(255, 255, 255, 0.3); font-style: italic; }
[data-theme="light"] .about-event-code { color: rgba(15, 23, 42, 0.4); }
[data-theme="light"] .about-event-code .l-cmd { color: #0e7490; }
[data-theme="light"] .about-event-code .l-code { color: #6150aa; }
[data-theme="light"] .about-event-code .l-rem { color: rgba(15, 23, 42, 0.35); }
@keyframes about-code-blink { 50% { opacity: 0; } }

/* ============ Responsive ============ */
@media (max-width: 980px) {
    .about-hero-grid { grid-template-columns: 1fr; gap: 2.75rem; }
    .about-vision { grid-template-columns: 1fr; gap: 1.25rem; padding: 2.5rem 1.75rem; }
    .about-vision-mark { font-size: 5rem; transform: none; line-height: 1; }
    .about-spaces { grid-template-columns: 1fr; }
    .about-events { grid-template-columns: 1fr; }
    .about-contact { flex-direction: column; text-align: center; padding: 2rem 1.5rem; gap: 1.25rem; }
    .about-contact-actions { width: 100%; }
    .about-contact-actions .btn { width: 100%; }
}
@media (max-width: 640px) {
    .about-hero { padding: 4rem 0 3.5rem; }
    .about-mission { grid-template-columns: 1fr; text-align: center; padding: 2.25rem 1.5rem; gap: 1.5rem; }
    .about-mission .about-medal { margin: 0 auto; }
    .about-mission-code { display: none; }
    .about-medal { width: 76px; height: 76px; font-size: 2.2rem; border-radius: 22px; }
    .about-vision { padding: 2.25rem 1.5rem; }
    .about-space { flex-direction: column; padding: 1.75rem 1.5rem; gap: 1.1rem; }
    .about-event-stub { flex-basis: 64px; font-size: 1.8rem; }
    .about-event-featured .about-event-stub { flex-basis: 72px; font-size: 2.4rem; }
    .about-event-tag { display: none; }
    .about-cta-panel { padding: 3rem 1.5rem; }
}
@media (max-width: 1150px) {
    .about-event-code { display: none; }
}

/* ============ Motion réduit ============ */
@media (prefers-reduced-motion: reduce) {
    .about-aurora,
    .about-pill-dot { animation: none; }
    .about-event-code { display: none; }
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

// Machine à écrire « Nuit de l'Info » dans la carte mise en avant.
(function () {
    'use strict';

    var box = document.querySelector('.about-event-code');
    if (!box) return;

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduced) { box.style.display = 'none'; return; }

    var cursor = box.querySelector('.code-cursor');
    if (!cursor) return;

    var lines = [
        { cls: 'l-cmd',  text: '$ npm install cafe@24h --save' },
        { cls: 'l-code', text: 'while (nuit) { coder(); innover(); }' },
        { cls: 'l-cmd',  text: '$ git commit -m "une equipe, un projet"' },
        { cls: 'l-rem',  text: '// defis releves avant le petit matin : 42' }
    ];

    function typeLine(span, text, done) {
        var i = 0;
        var timer = setInterval(function () {
            span.textContent += text.charAt(i);
            i += 1;
            if (i >= text.length) {
                clearInterval(timer);
                setTimeout(done, 380);
            }
        }, 34);
    }

    function clearLines() {
        var spans = box.querySelectorAll('.l-cmd, .l-code, .l-rem');
        Array.prototype.forEach.call(spans, function (s) {
            s.parentNode.removeChild(s);
        });
    }

    function play(index) {
        if (index >= lines.length) {
            setTimeout(function () {
                clearLines();
                play(0);
            }, 3400);
            return;
        }
        var span = document.createElement('span');
        span.className = lines[index].cls;
        box.insertBefore(span, cursor);
        typeLine(span, lines[index].text + '\n', function () {
            play(index + 1);
        });
    }

    setTimeout(function () { play(0); }, 600);
})();
</script>
