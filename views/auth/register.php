<?php

declare(strict_types=1);

/**
 * Formulaire d'inscription.
 */
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Rejoins l'AEIC</span>
        <h1 class="page-title">Créer un compte</h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <form class="auth-form surface glass" method="post" action="<?= e(url('/register')) ?>">
            <?= csrf_field() ?>

            <div class="field-row">
                <div class="field">
                    <label for="prenom">Prénom</label>
                    <input type="text" id="prenom" name="prenom" autocomplete="given-name"
                           value="<?= e(old('prenom')) ?>" required>
                </div>
                <div class="field">
                    <label for="nom">Nom</label>
                    <input type="text" id="nom" name="nom" autocomplete="family-name"
                           value="<?= e(old('nom')) ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="email">Adresse e-mail</label>
                <input type="email" id="email" name="email" autocomplete="email"
                       value="<?= e(old('email')) ?>" placeholder="vous@exemple.fr" required>
            </div>

            <div class="field">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" autocomplete="new-password"
                       minlength="8" required>
                <small class="field-hint">Au moins 8 caractères, une lettre et un chiffre.</small>
            </div>

            <div class="field">
                <label for="password_confirmation">Confirmer le mot de passe</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       autocomplete="new-password" minlength="8" required>
            </div>

            <div class="field field-checkbox">
                <label>
                    <input type="checkbox" name="consent" value="1" required>
                    <span>
                        J'accepte les
                        <a href="<?= e(url('/cgu')) ?>" target="_blank" rel="noopener">conditions d'utilisation</a>
                        et la <a href="<?= e(url('/privacy')) ?>" target="_blank" rel="noopener">politique de confidentialité</a>.
                    </span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Créer mon compte</button>

            <p class="auth-alt">
                Déjà inscrit ?
                <a href="<?= e(url('/login')) ?>">Se connecter</a>
            </p>
        </form>
    </div>
</section>
