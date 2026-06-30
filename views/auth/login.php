<?php

declare(strict_types=1);

/**
 * Formulaire de connexion.
 *
 * @var string $callbackUrl
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('auth.login.eyebrow')) ?></span>
        <h1 class="page-title"><?= e(t('auth.login.title')) ?></h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <form class="auth-form surface glass" method="post" action="<?= e(url('/login')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="callbackUrl" value="<?= e($callbackUrl ?? '') ?>">

            <div class="field">
                <label for="email"><?= e(t('auth.login.email')) ?></label>
                <input type="email" id="email" name="email" autocomplete="username"
                       placeholder="vous@exemple.fr" required>
            </div>

            <div class="field">
                <label for="password"><?= e(t('auth.login.password')) ?></label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>
            <p class="field-meta"><a href="<?= e(url('/forgot-password')) ?>"><?= e(t('auth.login.forgot')) ?></a></p>

            <button type="submit" class="btn btn-primary btn-block"><?= e(t('auth.login.submit')) ?></button>

            <p class="auth-alt">
                <?= e(t('auth.login.alt')) ?>
                <a href="<?= e(url('/register')) ?>"><?= e(t('auth.register.title')) ?></a>
            </p>
        </form>
    </div>
</section>
