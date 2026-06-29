<?php

declare(strict_types=1);

/**
 * Édition du profil élève.
 *
 * @var array<string,mixed> $user
 */
?>
<header class="dash-head">
    <span class="eyebrow">Compte</span>
    <h1 class="page-title">Mon profil</h1>
</header>

<div class="grid grid-2">
    <form class="card surface glass" method="post" action="<?= e(url('/eleve/profile')) ?>">
        <?= csrf_field() ?>
        <h2 class="card-title">Informations</h2>

        <div class="field-row">
            <div class="field">
                <label for="prenom">Prénom</label>
                <input type="text" id="prenom" name="prenom" value="<?= e($user['prenom'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="nom">Nom</label>
                <input type="text" id="nom" name="nom" value="<?= e($user['nom'] ?? '') ?>" required>
            </div>
        </div>

        <div class="field">
            <label for="email">Adresse e-mail</label>
            <input type="email" id="email" name="email" value="<?= e($user['email'] ?? '') ?>" required>
        </div>

        <button type="submit" class="btn btn-primary">Enregistrer</button>
    </form>

    <form class="card surface glass" method="post" action="<?= e(url('/eleve/profile')) ?>">
        <?= csrf_field() ?>
        <h2 class="card-title">Changer de mot de passe</h2>

        <div class="field">
            <label for="old_password">Mot de passe actuel</label>
            <input type="password" id="old_password" name="old_password" autocomplete="current-password">
        </div>
        <div class="field">
            <label for="new_password">Nouveau mot de passe</label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8">
            <small class="field-hint">Laissez vide pour ne pas changer. 8 car. min, une lettre et un chiffre.</small>
        </div>
        <div class="field">
            <label for="new_password_confirmation">Confirmer le nouveau mot de passe</label>
            <input type="password" id="new_password_confirmation" name="new_password_confirmation" autocomplete="new-password" minlength="8">
        </div>

        <p class="field-hint">Le changement de mot de passe est optionnel : les champs informations (prénom, nom, e-mail) sont mis à jour séparément si vous laissez ces champs vides.</p>

        <button type="submit" class="btn btn-outline">Mettre à jour</button>
    </form>
</div>
