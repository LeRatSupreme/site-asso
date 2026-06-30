<?php

declare(strict_types=1);

/**
 * Agenda des événements.
 *
 * @var list<array<string,mixed>> $upcoming
 * @var list<array<string,mixed>> $past
 * @var int $countUpcoming
 * @var int $countPast
 */

// Récupère les catégories distinctes pour le filtre.
$categories = [];
foreach ($upcoming as $e) {
    $cat = trim((string) ($e['category'] ?? ''));
    if ($cat !== '') {
        $categories[$cat] = true;
    }
}
$categories = array_keys($categories);
sort($categories);
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Agenda AEIC</span>
        <h1 class="page-title">Les prochains rendez-vous.</h1>
        <p class="page-lead">
            <?= e((string) $countUpcoming) ?> à venir · <?= e((string) $countPast) ?> passés
        </p>
    </div>
</header>

<section class="section">
    <div class="container">
        <?php if (!empty($categories)): ?>
        <div class="events-filter">
            <button class="event-cat-btn is-active" data-cat="">Tous</button>
            <?php foreach ($categories as $cat): ?>
                <button class="event-cat-btn" data-cat="<?= e(strtolower($cat)) ?>"><?= e($cat) ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="section-head">
            <h2 class="section-title">À venir</h2>
        </div>
        <?php if (empty($upcoming)): ?>
            <div class="empty-state surface glass">
                <p>Aucun événement à venir pour le moment. Revenez vite !</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3 events-grid">
                <?php foreach ($upcoming as $event): ?>
                    <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <h2 class="section-title">Archives</h2>
        </div>
        <?php if (empty($past)): ?>
            <div class="empty-state surface glass">
                <p>Aucune archive pour le moment.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3 events-grid">
                <?php foreach ($past as $event): ?>
                    <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var btns = document.querySelectorAll('.event-cat-btn');
    if (btns.length === 0) return;
    var cards = document.querySelectorAll('.events-grid .event-card, .events-grid [data-event-cat]');

    btns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var cat = btn.getAttribute('data-cat');
            btns.forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            cards.forEach(function (card) {
                var cardCat = (card.getAttribute('data-event-cat') || '').toLowerCase();
                var visible = cat === '' || cardCat === cat;
                card.style.display = visible ? '' : 'none';
            });
        });
    });
})();
</script>
