<?php

declare(strict_types=1);

/**
 * Agenda des événements — groupés par catégorie.
 *
 * @var list<array<string,mixed>> $upcoming
 * @var list<array<string,mixed>> $past
 * @var int $countUpcoming
 * @var int $countPast
 */

// Icônes par catégorie (fallback générique).
$catIcons = [
    'soirée'        => '🎉',
    'afterwork'     => '🍻',
    'barbecue'      => '🥩',
    'tournoi / lan' => '🎮',
    'tournoi'       => '🎮',
    'conférence'    => '🎤',
    'sortie'        => '🚌',
    'atelier'       => '🔧',
    'nuit de l\'info' => '💻',
    'nuit de l\'info' => '💻',
    'autre'         => '📅',
];

// Groupe les événements à venir par catégorie.
$grouped = [];
$uncategorized = [];
foreach ($upcoming as $e) {
    $cat = trim((string) ($e['category'] ?? ''));
    if ($cat === '') {
        $uncategorized[] = $e;
    } else {
        $grouped[$cat][] = $e;
    }
}
ksort($grouped);

// Pareil pour les archives.
$groupedPast = [];
$uncategorizedPast = [];
foreach ($past as $e) {
    $cat = trim((string) ($e['category'] ?? ''));
    if ($cat === '') {
        $uncategorizedPast[] = $e;
    } else {
        $groupedPast[$cat][] = $e;
    }
}
ksort($groupedPast);

/** Renvoie l'icône d'une catégorie. */
function catIcon(string $cat): string {
    global $catIcons;
    $key = strtolower(trim($cat));
    foreach ($catIcons as $k => $v) {
        if (str_contains($key, $k) || str_contains($k, $key)) {
            return $v;
        }
    }
    return '📅';
}
?>
<header class="page-hero">
    <div class="ae-aurora" aria-hidden="true"></div>
    <div class="ae-dots dot-grid" aria-hidden="true"></div>
    <div class="container">
        <div class="ae-hero-grid">
            <div class="ev-hero-copy">
                <span class="ae-pill">
                    <span class="ae-pill-dot" aria-hidden="true"></span>
                    <?= e(t('events.eyebrow')) ?>
                </span>
                <h1 class="page-title ae-title-grad"><?= e(t('events.title')) ?></h1>
                <p class="page-lead">
                    <?= e(tt('events.lead', ['{a}' => max($countUpcoming, 0), '{b}' => max($countPast, 0)])) ?>
                </p>
            </div>

            <!-- PASS événements décoratif et interactif -->
            <div class="ae-idcard" data-tilt data-tilt-base="rotate(-1.4deg)" aria-hidden="true">
                <div class="ae-idcard-band">
                    <span class="ae-idcard-logo">🎟</span>
                    <span class="ae-idcard-id">
                        <span class="ae-idcard-label">PASS / EVENTS</span>
                        <span class="ae-idcard-name">AEIC</span>
                    </span>
                    <span class="ae-idcard-badge"><?= e((string) max($countUpcoming, 0)) ?></span>
                </div>
                <div class="ae-idcard-body">
                    <div class="ae-idcard-row">
                        <span class="ae-idcard-ico">🎉</span>
                        <span><?= e(t_category('soirée')) ?></span>
                    </div>
                    <div class="ae-idcard-row">
                        <span class="ae-idcard-ico">💻</span>
                        <span><?= e(t_category('nuit de l\'info')) ?></span>
                    </div>
                    <div class="ae-idcard-row">
                        <span class="ae-idcard-ico">🍻</span>
                        <span><?= e(t_category('afterwork')) ?></span>
                    </div>
                </div>
                <div class="ae-idcard-code">|||| ||| &#124; || |||| &#124;&#124;| &#124; |||| |||&#124;</div>
            </div>
        </div>
    </div>
</header>

<style>
/* ============ Événements — spécifique ============ */

/* En-têtes de catégorie : tuile dégradée + filet dégradé */
.event-cat-header {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding-bottom: 0.9rem;
    border-bottom: 1px solid var(--border);
    position: relative;
}
.event-cat-header::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: -1px;
    width: 180px;
    height: 2px;
    background: linear-gradient(90deg, #8b7ae0, rgba(58, 155, 184, 0));
}
.event-cat-icon {
    display: grid;
    place-items: center;
    width: 46px;
    height: 46px;
    border-radius: 14px;
    font-size: 1.4rem;
    line-height: 1;
    background: linear-gradient(135deg, rgba(74, 61, 143, 0.75), rgba(58, 155, 184, 0.6));
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25), 0 10px 22px rgba(0, 0, 0, 0.28);
    flex-shrink: 0;
}
.event-cat-title {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 0;
    letter-spacing: -0.02em;
}
.event-cat-count {
    padding: 0.15rem 0.65rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
    color: var(--primary);
    background: rgba(72, 189, 211, 0.12);
    border: 1px solid rgba(72, 189, 211, 0.28);
}
.event-cat-past .event-cat-icon {
    background: linear-gradient(135deg, rgba(74, 61, 143, 0.35), rgba(58, 155, 184, 0.25));
    box-shadow: none;
    filter: saturate(0.7);
    opacity: 0.85;
}
.event-cat-section + .event-cat-section { margin-top: 3rem; }
</style>

<section class="section">
    <div class="container">
        <?php if (empty($upcoming)): ?>
            <div class="empty-state surface glass">
                <p><?= e(t('events.empty')) ?></p>
            </div>
        <?php else: ?>

            <?php foreach ($grouped as $catName => $events): ?>
                <div class="event-cat-section ae-reveal">
                    <div class="event-cat-header">
                        <h2 class="event-cat-title">
                            <span class="event-cat-icon"><?= catIcon($catName) ?></span>
                            <?= e(t_category($catName)) ?>
                            <span class="event-cat-count"><?= count($events) ?></span>
                        </h2>
                    </div>
                    <div class="grid grid-3">
                        <?php foreach ($events as $event): ?>
                            <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($uncategorized)): ?>
                <div class="event-cat-section ae-reveal">
                    <div class="event-cat-header">
                        <h2 class="event-cat-title">
                            <span class="event-cat-icon">📅</span>
                            <?= e(t('events.others')) ?>
                            <span class="event-cat-count"><?= count($uncategorized) ?></span>
                        </h2>
                    </div>
                    <div class="grid grid-3">
                        <?php foreach ($uncategorized as $event): ?>
                            <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</section>

<?php if (!empty($past)): ?>
<section class="section section-alt">
    <div class="container">
        <div class="section-head ae-reveal">
            <h2 class="section-title"><?= e(t('events.archives')) ?></h2>
        </div>

        <?php foreach ($groupedPast as $catName => $events): ?>
            <div class="event-cat-section event-cat-past ae-reveal">
                    <div class="event-cat-header">
                        <h3 class="event-cat-title">
                            <span class="event-cat-icon"><?= catIcon($catName) ?></span>
                            <?= e(t_category($catName)) ?>
                        <span class="event-cat-count"><?= count($events) ?></span>
                    </h3>
                </div>
                <div class="grid grid-3">
                    <?php foreach ($events as $event): ?>
                        <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($uncategorizedPast)): ?>
            <div class="grid grid-3" style="margin-top:1rem">
                <?php foreach ($uncategorizedPast as $event): ?>
                    <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
