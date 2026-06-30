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
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('events.eyebrow')) ?></span>
        <h1 class="page-title"><?= e(t('events.title')) ?></h1>
        <p class="page-lead">
            <?= e(tt('events.lead', ['{a}' => max($countUpcoming, 0), '{b}' => max($countPast, 0)])) ?>
        </p>
    </div>
</header>

<section class="section">
    <div class="container">
        <?php if (empty($upcoming)): ?>
            <div class="empty-state surface glass">
                <p><?= e(t('events.empty')) ?></p>
            </div>
        <?php else: ?>

            <?php foreach ($grouped as $catName => $events): ?>
                <div class="event-cat-section">
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
                <div class="event-cat-section">
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
        <div class="section-head">
            <h2 class="section-title"><?= e(t('events.archives')) ?></h2>
        </div>

        <?php foreach ($groupedPast as $catName => $events): ?>
            <div class="event-cat-section event-cat-past">
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
