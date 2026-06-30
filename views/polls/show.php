<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\Poll;

/**
 * @var array<string,mixed>  $poll
 * @var list<array<string,mixed>> $options
 * @var list<array<string,mixed>> $results
 * @var list<string> $userVotes
 * @var int $totalVotes
 * @var int $totalVoters
 * @var bool $isClosed
 * @var bool $hasVoted
 * @var bool $showForm
 * @var bool $showResults
 */

$poll        = $poll ?? [];
$options     = $options ?? [];
$results     = $results ?? [];
$userVotes   = $userVotes ?? [];
$totalVotes  = $totalVotes ?? 0;
$totalVoters = $totalVoters ?? 0;
$isClosed    = $isClosed ?? false;
$hasVoted    = $hasVoted ?? false;
$showForm    = $showForm ?? false;
$showResults = $showResults ?? false;

$title       = (string) ($poll['title'] ?? '');
$description = (string) ($poll['description'] ?? '');
$slug        = (string) ($poll['slug'] ?? '');
$isMultiple  = !empty($poll['is_multiple']);
$closesAt    = (string) ($poll['closes_at'] ?? '');

// Trouver l'option gagnante.
$winnerPct = 0;
foreach ($results as $r) {
    $winnerPct = max($winnerPct, (int) ($r['percent'] ?? 0));
}
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <div class="poll-header-top">
            <a class="btn btn-outline poll-back" href="<?= e(url('/sondages')) ?>"><?= e(t('poll.back')) ?></a>
            <span class="poll-eyebrow-lg"><?= e(t('poll.eyebrow.lg')) ?></span>
        </div>
        <h1 class="page-title"><?= e($title) ?></h1>
        <div class="poll-badges">
            <?php if ($isClosed): ?>
                <span class="badge badge-muted"><?= e(t('poll.closed')) ?></span>
            <?php elseif ($showForm): ?>
                <span class="badge badge-success"><?= e(t('poll.open')) ?></span>
            <?php else: ?>
                <span class="badge badge-secondary"><?= e(t('poll.in_progress')) ?></span>
            <?php endif; ?>
            <span class="badge badge-muted"><?= e($isMultiple ? t('poll.multiple') : t('poll.single')) ?></span>
            <?php if ($totalVoters > 0): ?>
                <span class="badge badge-info"><?= e(tt('poll.voters', ['{n}' => $totalVoters])) ?></span>
            <?php endif; ?>
        </div>
    </div>
</header>

<section class="section">
    <div class="container poll-layout">

        <!-- Colonne gauche : sondage (vote ou résultats) -->
        <div class="poll-main">
            <?php if ($showForm): ?>
                <!-- ============ FORMULAIRE DE VOTE ============ -->
                <div class="card surface glass poll-vote-card">
                    <span class="eyebrow"><?= e(t('poll.vote.eyebrow')) ?></span>
                    <h2 class="card-title"><?= e(t('poll.vote.now')) ?></h2>
                    <?php if ($isMultiple): ?>
                        <p class="card-meta"><?= e(t('poll.vote.multiple.desc')) ?></p>
                    <?php else: ?>
                        <p class="card-meta"><?= e(t('poll.vote.single.desc')) ?></p>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('/sondages/' . rawurlencode($slug) . '/vote')) ?>">
                        <?= csrf_field() ?>
                        <div class="poll-vote-options">
                            <?php foreach ($options as $option): ?>
                                <label class="poll-vote-option">
                                    <input type="<?= $isMultiple ? 'checkbox' : 'radio' ?>"
                                           name="<?= $isMultiple ? 'option_id[]' : 'option_id' ?>"
                                           value="<?= e((string) $option['id']) ?>"
                                           id="opt_<?= e((string) $option['id']) ?>"
                                           <?= !$isMultiple ? 'required' : '' ?>>
                                    <span class="poll-vote-radio"></span>
                                    <span class="poll-vote-label"><?= e($option['label'] ?? '') ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg btn-block"><?= e(t('poll.vote.submit')) ?></button>
                    </form>
                </div>

            <?php elseif ($showResults): ?>
                <!-- ============ RÉSULTATS ============ -->
                <div class="card surface glass poll-results-card">
                    <div class="poll-results-head">
                        <span class="eyebrow"><?= e(t('poll.results')) ?></span>
                        <h2 class="card-title">
                            <?= e($hasVoted ? t('poll.thanks') : ($isClosed ? t('poll.closed.label') : t('poll.results.live'))) ?>
                        </h2>
                        <p class="card-meta">
                            <?= e(tt('poll.voters', ['{n}' => $totalVoters])) ?>
                            <?php if (!$isClosed && $closesAt !== ''): ?>
                                · <?= e(formatDateTime($closesAt)) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <?php if ($results === []): ?>
                        <div class="poll-empty">
                            <span class="poll-empty-icon">🗳️</span>
                            <p class="muted"><?= e(t('poll.no_votes')) ?></p>
                        </div>
                    <?php else: ?>
                        <div class="poll-results-list">
                            <?php foreach ($results as $row):
                                $pct = (int) ($row['percent'] ?? 0);
                                $isWinner = $pct === $winnerPct && $pct > 0;
                                $isMine = in_array((string) $row['id'], $userVotes, true);
                            ?>
                                <div class="poll-result-row <?= $isMine ? 'is-mine' : '' ?>">
                                    <div class="poll-result-head">
                                        <span class="poll-result-label">
                                            <?php if ($isWinner): ?><span class="poll-trophy">🏆</span><?php endif; ?>
                                            <?= e($row['label'] ?? '') ?>
                                            <?php if ($isMine): ?><span class="poll-your-vote"><?= e(t('poll.your_vote')) ?></span><?php endif; ?>
                                        </span>
                                        <span class="poll-result-pct"><?= e((string) $pct) ?>%</span>
                                    </div>
                                    <div class="poll-result-track">
                                        <div class="poll-result-fill <?= $isWinner ? 'is-winner' : '' ?> <?= $isMine ? 'is-mine' : '' ?>"
                                             style="width: <?= max(2, $pct) ?>%"></div>
                                    </div>
                                    <span class="poll-result-count"><?= e(tt('poll.vote.count', ['{n}' => ($row['votes'] ?? 0)])) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- ============ NON CONNECTÉ ============ -->
                <div class="card surface glass poll-login-prompt">
                    <span class="poll-login-icon">🗳️</span>
                    <h2 class="card-title"><?= e(t('poll.login.title')) ?></h2>
                    <p class="card-excerpt"><?= e(t('poll.login.desc')) ?></p>
                    <div class="hero-actions" style="justify-content:center;">
                        <a class="btn btn-primary btn-lg" href="<?= e(url('/login?callbackUrl=' . rawurlencode('/sondages/' . $slug))) ?>"><?= e(t('poll.login.button')) ?></a>
                        <a class="btn btn-outline btn-lg" href="<?= e(url('/register')) ?>"><?= e(t('poll.login.register')) ?></a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Colonne droite : description + infos -->
        <aside class="poll-side">
            <?php if ($description !== ''): ?>
                <div class="card surface glass poll-side-desc">
                    <span class="eyebrow"><?= e(t('poll.about')) ?></span>
                    <p><?= nl2br(e($description)) ?></p>
                </div>
            <?php endif; ?>

            <div class="card surface glass poll-side-info">
                <h3 class="card-title"><?= e(t('poll.info')) ?></h3>
                <ul class="poll-info-list">
                    <li>
                        <span class="muted"><?= e(t('poll.info.status')) ?></span>
                        <?php if ($isClosed): ?>
                            <span class="badge badge-muted"><?= e(t('poll.closed')) ?></span>
                        <?php else: ?>
                            <span class="badge badge-success"><?= e(t('poll.open')) ?></span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <span class="muted"><?= e(t('poll.info.type')) ?></span>
                        <span><?= e($isMultiple ? t('poll.multiple') : t('poll.single')) ?></span>
                    </li>
                    <li>
                        <span class="muted"><?= e(t('poll.info.voters')) ?></span>
                        <strong><?= e((string) $totalVoters) ?></strong>
                    </li>
                    <li>
                        <span class="muted"><?= e(t('poll.info.options')) ?></span>
                        <strong><?= e((string) count($options)) ?></strong>
                    </li>
                    <?php if ($closesAt !== ''): ?>
                        <li>
                            <span class="muted"><?= e(t('poll.info.closes')) ?></span>
                            <span><?= e(formatDateTime($closesAt)) ?></span>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </aside>

    </div>
</section>
