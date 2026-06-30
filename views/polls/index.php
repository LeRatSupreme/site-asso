<?php

declare(strict_types=1);

use App\Models\Poll;

/**
 * Liste des sondages.
 *
 * @var list<array<string,mixed>> $polls
 * @var int $count
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Sondages AEIC</span>
        <h1 class="page-title">Votre avis compte.</h1>
        <p class="page-lead">
            <?= e((string) $count) ?> sondage<?= $count > 1 ? 's' : '' ?> · résultats en temps réel
        </p>
    </div>
</header>

<section class="section">
    <div class="container">
        <?php if (empty($polls)): ?>
            <div class="empty-state surface glass">
                <p>Aucun sondage ouvert pour le moment. Revenez vite !</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($polls as $poll): ?>
                    <?php
                    $cardPoll = $poll;
                    require AEIC_VIEWS . '/partials/poll_card.php';
                    ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
