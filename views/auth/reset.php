<?php

declare(strict_types=1);

/**
 * @var string $token
 */
?>
<section class="container narrow auth-card">
    <span class="eyebrow"><?= e(t('profile.eyebrow')) ?></span>
    <h1 class="hero-title"><?= e(t('password.new.title')) ?></h1>
    <p class="hero-lead"><?= e(t('password.reset.lead')) ?></p>

    <?php if ($token === ''): ?>
        <div class="card surface glass form-card">
            <p class="card-meta"><?= e(t('password.reset.invalid')) ?></p>
            <a class="btn btn-primary btn-block" href="<?= e(url('/forgot-password')) ?>"><?= e(t('password.reset.request_link')) ?></a>
        </div>
    <?php else: ?>
    <form class="card surface glass form-card" method="post" action="<?= e(url('/reset-password')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field">
            <label for="password"><?= e(t('password.new.title')) ?></label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
        </div>
        <div class="field">
            <label for="password_confirmation"><?= e(t('password.confirm.label')) ?></label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('password.reset.submit')) ?></button>
    </form>
    <?php endif; ?>
</section>
