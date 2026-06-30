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

$slug     = (string) ($cardPoll['slug'] ?? '');
$title    = (string) ($cardPoll['title'] ?? '');
$desc     = (string) ($cardPoll['description'] ?? '');
$pollId   = (string) ($cardPoll['id'] ?? '');
$isClosed = Poll::isClosed($cardPoll);
$isMulti  = !empty($cardPoll['is_multiple']);
$voters   = Poll::totalVoters($pollId);
$options  = Poll::options($pollId);
$optCount = count($options);

$excerpt = '';
if ($desc !== '') {
    $excerpt = mb_substr(trim(strip_tags($desc)), 0, 120);
    if (mb_strlen(trim(strip_tags($desc))) > 120) {
        $excerpt .= '…';
    }
}

// Top option (leader) si des votes existent.
$leaderLabel = '';
$leaderPct = 0;
if ($voters > 0) {
    $results = Poll::results($pollId);
    foreach ($results as $r) {
        if ((int) ($r['percent'] ?? 0) > $leaderPct) {
            $leaderPct = (int) $r['percent'];
            $leaderLabel = (string) ($r['label'] ?? '');
        }
    }
}
?>
<a class="poll-card-link" href="<?= e(url('/sondages/' . $slug)) ?>">
    <article class="poll-card-v2 <?= $isClosed ? 'is-closed' : 'is-open' ?>">
        <div class="poll-card-v2-strip"></div>

        <div class="poll-card-v2-body">
            <div class="poll-card-v2-meta">
                <?php if ($isClosed): ?>
                    <span class="badge badge-muted">🔒 Fermé</span>
                <?php else: ?>
                    <span class="badge badge-success">🟢 Ouvert</span>
                <?php endif; ?>
                <span class="poll-card-v2-type"><?= $isMulti ? '☑️ Multi' : '🔘 Unique' ?></span>
            </div>

            <h3 class="poll-card-v2-title"><?= e($title) ?></h3>

            <?php if ($excerpt !== ''): ?>
                <p class="poll-card-v2-desc"><?= e($excerpt) ?></p>
            <?php endif; ?>

            <?php if ($voters > 0 && $leaderLabel !== ''): ?>
                <div class="poll-card-v2-leader">
                    <span class="poll-card-v2-leader-label">🏆 <?= e($leaderLabel) ?></span>
                    <span class="poll-card-v2-leader-pct"><?= e((string) $leaderPct) ?>%</span>
                </div>
            <?php endif; ?>

            <div class="poll-card-v2-foot">
                <span class="poll-card-v2-stats">
                    <span class="poll-stat">🗳️ <?= e((string) $voters) ?></span>
                    <span class="poll-stat"><?= e((string) $optCount) ?> options</span>
                </span>
                <span class="poll-card-v2-cta">
                    <?= $isClosed ? 'Voir →' : 'Participer →' ?>
                </span>
            </div>
        </div>
    </article>
</a>
