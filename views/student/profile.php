<?php

declare(strict_types=1);

/**
 * Édition du profil élève.
 *
 * @var array<string,mixed> $user
 */
?>
<header class="dash-head">
    <span class="eyebrow"><?= e(t('profile.eyebrow')) ?></span>
    <h1 class="page-title"><?= e(t('dash.my_profile')) ?></h1>
</header>

<div class="grid grid-2">
    <form class="card surface glass" method="post" action="<?= e(url('/eleve/profile')) ?>">
        <?= csrf_field() ?>
        <h2 class="card-title"><?= e(t('profile.info')) ?></h2>

        <div class="field-row">
            <div class="field">
                <label for="prenom"><?= e(t('profile.firstname')) ?></label>
                <input type="text" id="prenom" name="prenom" value="<?= e($user['prenom'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="nom"><?= e(t('profile.lastname')) ?></label>
                <input type="text" id="nom" name="nom" value="<?= e($user['nom'] ?? '') ?>" required>
            </div>
        </div>

        <div class="field">
            <label for="email"><?= e(t('common.email')) ?></label>
            <input type="email" id="email" name="email" value="<?= e($user['email'] ?? '') ?>" required>
        </div>

        <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>

    <form class="card surface glass" method="post" action="<?= e(url('/eleve/profile')) ?>">
        <?= csrf_field() ?>
        <h2 class="card-title"><?= e(t('profile.password.change')) ?></h2>

        <div class="field">
            <label for="old_password"><?= e(t('account.password.current')) ?></label>
            <input type="password" id="old_password" name="old_password" autocomplete="current-password">
        </div>
        <div class="field">
            <label for="new_password"><?= e(t('account.password.new')) ?></label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8">
            <small class="field-hint"><?= e(t('profile.password.hint')) ?></small>
        </div>
        <div class="field">
            <label for="new_password_confirmation"><?= e(t('profile.password.confirm_new')) ?></label>
            <input type="password" id="new_password_confirmation" name="new_password_confirmation" autocomplete="new-password" minlength="8">
        </div>

        <p class="field-hint"><?= e(t('profile.password.note')) ?></p>

        <button type="submit" class="btn btn-outline"><?= e(t('profile.update')) ?></button>
    </form>
</div>
