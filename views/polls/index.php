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
    <div class="ae-aurora" aria-hidden="true"></div>
    <div class="ae-dots dot-grid" aria-hidden="true"></div>
    <div class="container">
        <span class="ae-pill">
            <span class="ae-pill-dot" aria-hidden="true"></span>
            <?= e(t('polls.eyebrow')) ?>
        </span>
        <h1 class="page-title ae-title-grad"><?= e(t('polls.title')) ?></h1>
        <p class="page-lead">
            <?= e(tt('polls.lead', ['{n}' => $count])) ?>
        </p>
    </div>
</header>

<section class="section">
    <div class="container">
        <?php if (empty($polls)): ?>
            <div class="empty-state surface glass">
                <p><?= e(t('polls.empty')) ?></p>
            </div>
        <?php else: ?>
            <div class="grid grid-3 ae-reveal">
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
