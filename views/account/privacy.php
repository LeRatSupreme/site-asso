<?php

declare(strict_types=1);

/**
 * Page « Mes données » (RGPD) : mot de passe, portabilité, effacement.
 *
 * @var array<string,mixed> $user
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow"><?= e(t('account.eyebrow.rgpd')) ?></span>
        <h1 class="page-title"><?= e(t('account.privacy.title')) ?></h1>
        <p class="page-lead"><?= e(t('account.privacy.lead')) ?></p>
    </div>
</header>

<section class="section">
    <div class="container narrow">

        <!-- ====== Mot de passe ====== -->
        <div class="card surface glass rgpd-card">
            <div class="rgpd-card-head">
                <span class="rgpd-icon">🔐</span>
                <div>
                    <h2 class="card-title"><?= e(t('account.password.title')) ?></h2>
                    <p class="rgpd-sub"><?= e(t('account.password.sub')) ?></p>
                </div>
            </div>
            <form method="post" action="<?= e(url('/account/password')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="old_password"><?= e(t('account.password.current')) ?></label>
                    <input type="password" id="old_password" name="old_password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label for="new_password"><?= e(t('account.password.new')) ?></label>
                    <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                    <p class="field-help"><?= e(t('account.password.help')) ?></p>
                </div>
                <div class="field">
                    <label for="new_password_confirmation"><?= e(t('account.password.confirm')) ?></label>
                    <input type="password" id="new_password_confirmation" name="new_password_confirmation" minlength="8" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary"><?= e(t('account.password.submit')) ?></button>
            </form>
        </div>

        <!-- ====== Export ====== -->
        <div class="card surface glass rgpd-card">
            <div class="rgpd-card-head">
                <span class="rgpd-icon">📦</span>
                <div>
                    <h2 class="card-title"><?= e(t('account.export.title')) ?></h2>
                    <p class="rgpd-sub"><?= e(t('account.export.sub')) ?></p>
                </div>
            </div>
            <p class="rgpd-desc"><?= e(t('account.export.desc')) ?></p>
            <a class="btn btn-outline" href="<?= e(url('/account/export')) ?>"><?= e(t('account.export.btn')) ?></a>
        </div>

        <!-- ====== Suppression ====== -->
        <div class="card surface glass rgpd-card rgpd-card-danger">
            <div class="rgpd-card-head">
                <span class="rgpd-icon rgpd-icon-danger">🗑️</span>
                <div>
                    <h2 class="card-title"><?= e(t('account.delete.title')) ?></h2>
                    <p class="rgpd-sub"><?= e(t('account.delete.sub')) ?></p>
                </div>
            </div>
            <p class="rgpd-desc">
                <?= e(t('account.delete.desc')) ?>
            </p>
            <a class="btn btn-danger" href="<?= e(url('/account/delete')) ?>"><?= e(t('account.delete.btn')) ?></a>
        </div>

    </div>
</section>

<style>
.rgpd-card {
    padding: 1.75rem;
    margin-bottom: 1.25rem;
    transition: border-color 0.2s;
}
.rgpd-card:hover {
    border-color: rgba(255,255,255,0.15);
}
.rgpd-card-head {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.rgpd-icon {
    font-size: 1.8rem;
    line-height: 1;
    flex-shrink: 0;
}
.rgpd-icon-danger { color: var(--accent-danger, #ef4444); }
.rgpd-card-head .card-title { margin: 0 0 0.2rem; font-size: 1.1rem; }
.rgpd-sub { font-size: 0.82rem; color: var(--muted); margin: 0; }
.rgpd-desc { font-size: 0.9rem; color: var(--muted); line-height: 1.6; margin: 0 0 1rem; }
.rgpd-card-danger { border-left: 3px solid rgba(239, 68, 68, 0.5); }
.rgpd-card .field { margin-bottom: 0.85rem; }
.rgpd-card .btn { margin-top: 0.5rem; transition: transform 0.15s, filter 0.15s; }
.rgpd-card .btn:hover { transform: translateY(-2px); filter: brightness(1.15); }
.rgpd-card .btn:active { transform: translateY(0); }

.btn-danger {
    background: rgba(239, 68, 68, 0.14);
    border: 1px solid rgba(239, 68, 68, 0.4);
    color: var(--accent-danger, #ef4444);
}
.btn-danger:hover {
    background: rgba(239, 68, 68, 0.25);
    border-color: rgba(239, 68, 68, 0.6);
    color: #fff;
    transform: translateY(-2px);
    filter: brightness(1.1);
}
</style>
