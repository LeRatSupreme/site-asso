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
                <span class="badge badge-secondary">Nouveau</span>
                <h3 class="card-title">🔤 Wordle</h3>
                <p class="card-excerpt">Devine le mot de 5 lettres du jour. 6 essais, en français ou en anglais. Un nouveau mot chaque jour, identique pour tous&nbsp;!</p>
                <span class="btn btn-primary btn-sm" style="align-self:flex-start;">Jouer →</span>
            </a>

            <div class="surface card" style="opacity:0.6;">
                <span class="badge badge-muted">Bientôt</span>
                <h3 class="card-title">🧠 Memory</h3>
                <p class="card-excerpt">Le memory cafétéria reviendra bientôt dans la zone jeux.</p>
            </div>

            <div class="surface card" style="opacity:0.6;">
                <span class="badge badge-muted">Bientôt</span>
                <h3 class="card-title">🎯 À venir</h3>
                <p class="card-excerpt">D'autres jeux arrivent prochainement. Reste connecté&nbsp;!</p>
            </div>
        </div>

        <div class="section-more">
            <a class="btn btn-outline" href="<?= e(url('/jeux/leaderboard')) ?>">🏆 Voir le classement</a>
        </div>
    </div>
</section>
