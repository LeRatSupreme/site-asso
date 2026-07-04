<?php

declare(strict_types=1);

/**
 * Page menu des jeux AEIC.
 *
 * @var array<string,mixed>|null $user
 * @var array{fr:array, en:array}|null $stats
 */

use App\Core\Auth;

?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">🎮 Zone jeux</span>
        <h1 class="page-title">Zone jeux</h1>
        <p class="page-lead">Un mot par jour, une série à construire, un classement à gravir. Connecte-toi pour sauvegarder tes scores&nbsp;!</p>
    </div>
</header>

<section class="section">
    <div class="container">

        <?php if ($user === null): ?>
            <div class="surface card panel-brand" style="margin-bottom:1.5rem; align-items:flex-start;">
                <p style="margin:0;">🔒 Tu joues en <strong>mode démo</strong>. <a href="<?= e(url('/login?callbackUrl=' . rawurlencode('/jeux'))) ?>">Connecte-toi</a> pour sauvegarder tes parties, suivre ta série de victoires et apparaître dans le classement.</p>
            </div>
        <?php endif; ?>

        <?php if ($user !== null && $stats !== null): ?>
            <h2 class="section-title" style="font-size:1.3rem; margin-bottom:1rem;">Tes statistiques</h2>
            <div class="grid grid-4" style="margin-bottom:2rem;">
                <?php foreach (['fr' => '🇫🇷 FR', 'en' => '🇬🇧 EN'] as $code => $label):
                    $s = $stats[$code]; ?>
                    <div class="surface card card-hover">
                        <span class="badge badge-gradient"><?= $label ?></span>
                        <div class="stat-card">
                            <span class="stat-value"><?= (int) $s['played'] ?></span>
                            <span class="stat-label">Parties</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?= (int) $s['won'] ?></span>
                            <span class="stat-label">Victoires</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value">🔥 <?= (int) $s['currentStreak'] ?></span>
                            <span class="stat-label">Série en cours</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value">🏆 <?= (int) $s['maxStreak'] ?></span>
                            <span class="stat-label">Record</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2 class="section-title" style="font-size:1.3rem; margin-bottom:1rem;">Jeux disponibles</h2>
        <div class="grid grid-3">
            <a class="surface card card-hover" href="<?= e(url('/jeux/wordle')) ?>" style="text-decoration:none; color:inherit;">
                <span class="badge badge-secondary">3 difficultés</span>
                <h3 class="card-title">🔤 Wordle</h3>
                <p class="card-excerpt">Devine le mot en 6 essais. 3 niveaux (5, 6 ou 7 lettres), mode quotidien commun ou libre illimité, en français ou en anglais&nbsp;!</p>
                <span class="btn btn-primary btn-sm" style="align-self:flex-start;">Jouer →</span>
            </a>

            <a class="surface card card-hover" href="<?= e(url('/jeux/enigme')) ?>" style="text-decoration:none; color:inherit;">
                <span class="badge badge-secondary">Quotidien</span>
                <h3 class="card-title">🧩 Énigme du jour</h3>
                <p class="card-excerpt">Une devinette par jour, identique pour tous les joueurs. Saurez-vous la résoudre&nbsp;? Change chaque jour à minuit&nbsp;!</p>
                <span class="btn btn-primary btn-sm" style="align-self:flex-start;">Réfléchir →</span>
            </a>

            <div class="surface card" style="opacity:0.6;">
                <span class="badge badge-muted">Bientôt</span>
                <h3 class="card-title">🧠 Memory</h3>
                <p class="card-excerpt">Le memory cafétéria reviendra bientôt dans la zone jeux.</p>
            </div>
        </div>

        <div class="section-more">
            <a class="btn btn-outline" href="<?= e(url('/jeux/leaderboard')) ?>">🏆 Voir le classement</a>
        </div>
    </div>
</section>
