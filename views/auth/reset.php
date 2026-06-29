<?php

declare(strict_types=1);

/**
 * @var string $token
 */
?>
<section class="container narrow auth-card">
    <span class="eyebrow">Compte</span>
    <h1 class="hero-title">Nouveau mot de passe</h1>
    <p class="hero-lead">Choisissez un nouveau mot de passe (8 caractères min, une lettre et un chiffre).</p>

    <?php if ($token === ''): ?>
        <div class="card surface glass form-card">
            <p class="card-meta">Ce lien de réinitialisation est invalide.</p>
            <a class="btn btn-primary btn-block" href="<?= e(url('/forgot-password')) ?>">Demander un nouveau lien</a>
        </div>
    <?php else: ?>
    <form class="card surface glass form-card" method="post" action="<?= e(url('/reset-password')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field">
            <label for="password">Nouveau mot de passe</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
        </div>
        <div class="field">
            <label for="password_confirmation">Confirmer le mot de passe</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Réinitialiser</button>
    </form>
    <?php endif; ?>
</section>
