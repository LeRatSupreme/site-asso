<?php

declare(strict_types=1);

use App\Models\Poll;

/**
 * Carte d'un sondage (partial réutilisable).
 *
 * @var array<string,mixed>|null $cardPoll
 */

/** @var array<string,mixed>|null $cardPoll */
$cardPoll = $cardPoll ?? null;
if (!is_array($cardPoll)) {
    return;
}

$slug   = (string) ($cardPoll['slug'] ?? '');
$title  = (string) ($cardPoll['title'] ?? '');
$desc   = (string) ($cardPoll['description'] ?? '');
$pollId = (string) ($cardPoll['id'] ?? '');
$isClosed = Poll::isClosed($cardPoll);

$voters = Poll::totalVoters($pollId);

if ($desc !== '') {
    $excerpt = mb_substr(trim(strip_tags($desc)), 0, 140);
    if (mb_strlen(trim(strip_tags($desc))) > 140) {
        $excerpt .= '…';
    }
} else {
    $excerpt = '';
}

$badge = $isClosed
    ? '<span class="badge badge-muted">Fermé</span>'
    : '<span class="badge badge-success">Ouvert</span>';
?>
<article class="poll-card card card-hover surface glass">
    <div class="poll-card-head">
        <?= $badge ?>
        <span class="card-meta">🗳️ <?= e((string) $voters) ?> votant<?= $voters > 1 ? 's' : '' ?></span>
    </div>

    <div class="event-card-body">
        <h3 class="card-title">
            <a href="<?= e(url('/sondages/' . $slug)) ?>"><?= e($title) ?></a>
        </h3>
        <?php if ($excerpt !== ''): ?>
            <p class="card-excerpt"><?= e($excerpt) ?></p>
        <?php endif; ?>
    </div>

    <div class="event-card-actions">
        <a class="btn btn-outline btn-sm" href="<?= e(url('/sondages/' . $slug)) ?>">
            <?= $isClosed ? 'Voir les résultats' : 'Participer' ?>
        </a>
    </div>
</article>
