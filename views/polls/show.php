<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Models\Poll;

/**
 * Détail d'un sondage : formulaire de vote ou résultats.
 *
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
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <p class="back-link">
            <a href="<?= e(url('/sondages')) ?>">← Retour aux sondages</a>
        </p>
        <span class="eyebrow">Sondage</span>
        <h1 class="page-title"><?= e($title) ?></h1>
        <?php if ($isClosed): ?>
            <span class="badge badge-muted">Fermé</span>
        <?php elseif ($showForm): ?>
            <span class="badge badge-success">Ouvert</span>
        <?php else: ?>
            <span class="badge badge-secondary">En cours</span>
        <?php endif; ?>
    </div>
</header>

<section class="section">
    <div class="container poll-detail">
        <?php if ($description !== ''): ?>
            <div class="prose surface glass"><?= nl2br(e($description)) ?></div>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <div class="card surface glass poll-vote">
                <span class="eyebrow"><?= $isMultiple ? 'Choix multiple' : 'Choix unique' ?></span>
                <h2 class="card-title">Votre réponse</h2>

                <form method="post" action="<?= e(url('/sondages/' . rawurlencode($slug) . '/vote')) ?>">
                    <?= csrf_field() ?>

                    <div class="poll-options">
                        <?php foreach ($options as $option): ?>
                            <label class="poll-option">
                                <input type="<?= $isMultiple ? 'checkbox' : 'radio' ?>"
                                       name="<?= $isMultiple ? 'option_id[]' : 'option_id' ?>"
                                       value="<?= e((string) $option['id']) ?>"
                                       <?= !$isMultiple ? 'required' : '' ?>>
                                <span class="poll-option-label"><?= e($option['label'] ?? '') ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="admin-actions">
                        <button type="submit" class="btn btn-primary btn-lg">Voter</button>
                    </div>
                </form>
            </div>
        <?php elseif ($showResults): ?>
            <div class="card surface glass poll-results">
                <div class="poll-results-head">
                    <span class="eyebrow">Résultats</span>
                    <h2 class="card-title">
                        <?= $hasVoted ? 'Merci d\'avoir voté !' : ($isClosed ? 'Sondage terminé' : 'Résultats') ?>
                    </h2>
                    <p class="card-meta">
                        <?= e((string) $totalVoters) ?> votant<?= $totalVoters > 1 ? 's' : '' ?> ·
                        <?= e((string) $totalVotes) ?> vote<?= $totalVotes > 1 ? 's' : '' ?>
                        <?php if (!$isClosed && $closesAt !== ''): ?>
                            · clôture le <?= e(formatDateTime($closesAt)) ?>
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($results === []): ?>
                    <div class="empty-state">
                        <p>Aucun vote pour le moment.</p>
                    </div>
                <?php else: ?>
                    <ul class="poll-bars">
                        <?php foreach ($results as $row): ?>
                            <li class="poll-bar<?= in_array((string) $row['id'], $userVotes, true) ? ' is-mine' : '' ?>">
                                <div class="poll-bar-head">
                                    <span class="poll-bar-label"><?= e($row['label'] ?? '') ?></span>
                                    <span class="poll-bar-count">
                                        <?= e((string) $row['percent']) ?>% ·
                                        <?= e((string) $row['votes']) ?> vote<?= $row['votes'] > 1 ? 's' : '' ?>
                                    </span>
                                </div>
                                <div class="poll-bar-track">
                                    <div class="poll-bar-fill" style="width: <?= e((string) $row['percent']) ?>%"></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card surface glass">
                <p class="card-excerpt">Connectez-vous pour participer à ce sondage et voir les résultats.</p>
                <div class="admin-actions">
                    <a class="btn btn-primary" href="<?= e(url('/login?callbackUrl=' . rawurlencode('/sondages/' . $slug))) ?>">Se connecter</a>
                    <a class="btn btn-outline" href="<?= e(url('/register')) ?>">Créer un compte</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
