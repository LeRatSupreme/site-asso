<?php

declare(strict_types=1);

/**
 * @var string $token
 */
?>
<section class="container narrow auth-card">
    <span class="eyebrow">Compte</span>
    <h1 class="hero-title">Mot de passe oublié</h1>
    <p class="hero-lead">Saisissez votre adresse e-mail : vous recevrez un lien pour réinitialiser votre mot de passe.</p>

    <form class="card surface glass form-card" method="post" action="<?= e(url('/forgot-password')) ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label for="email">Adresse e-mail</label>
            <input type="email" id="email" name="email" required autocomplete="email">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Envoyer le lien</button>
        <p class="form-foot"><a href="<?= e(url('/login')) ?>">← Retour à la connexion</a></p>
    </form>
</section>
