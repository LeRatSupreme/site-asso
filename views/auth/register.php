<?php

declare(strict_types=1);

/**
 * Formulaire d'inscription.
 */
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('auth.register.eyebrow')) ?></span>
        <h1 class="page-title"><?= e(t('auth.register.title')) ?></h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <form class="auth-form surface glass" method="post" action="<?= e(url('/register')) ?>">
            <?= csrf_field() ?>

            <div class="field-row">
                <div class="field">
                    <label for="prenom"><?= e(t('auth.register.firstname')) ?></label>
                    <input type="text" id="prenom" name="prenom" autocomplete="given-name"
                           value="<?= e(old('prenom')) ?>" required>
                </div>
                <div class="field">
                    <label for="nom"><?= e(t('auth.register.lastname')) ?></label>
                    <input type="text" id="nom" name="nom" autocomplete="family-name"
                           value="<?= e(old('nom')) ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="email"><?= e(t('auth.login.email')) ?></label>
                <input type="email" id="email" name="email" autocomplete="email"
                       value="<?= e(old('email')) ?>" placeholder="vous@exemple.fr" required>
                <small class="field-hint"><?= e(t('auth.register.email.hint')) ?></small>
            </div>

            <div class="field field-checkbox">
                <?php
                $cguUrl = e(url('/cgu'));
                $privacyUrl = e(url('/privacy'));
                $consent = tt('auth.register.consent', [
                    '{cgu}'     => '<a href="' . $cguUrl . '" target="_blank" rel="noopener">' . e(t('auth.register.cgu')) . '</a>',
                    '{privacy}' => '<a href="' . $privacyUrl . '" target="_blank" rel="noopener">' . e(t('auth.register.consent.privacy')) . '</a>',
                ]);
                ?>
                <label>
                    <input type="checkbox" name="consent" value="1" required>
                    <span><?= $consent ?></span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-block"><?= e(t('auth.register.submit')) ?></button>

            <p class="auth-alt">
                <?= e(t('auth.register.alt')) ?>
                <a href="<?= e(url('/login')) ?>"><?= e(t('auth.login.title')) ?></a>
            </p>
        </form>
    </div>
</section>
