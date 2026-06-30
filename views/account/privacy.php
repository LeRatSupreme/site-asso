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
        <span class="eyebrow">RGPD · Vos droits</span>
        <h1 class="page-title">Mes données</h1>
        <p class="page-lead">Gérez votre mot de passe, exportez ou supprimez vos données.</p>
    </div>
</header>

<section class="section">
    <div class="container narrow">

        <!-- ====== Mot de passe ====== -->
        <div class="card surface glass rgpd-card">
            <div class="rgpd-card-head">
                <span class="rgpd-icon">🔐</span>
                <div>
                    <h2 class="card-title">Changer mon mot de passe</h2>
                    <p class="rgpd-sub">Un email de confirmation vous sera envoyé.</p>
                </div>
            </div>
            <form method="post" action="<?= e(url('/account/password')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="old_password">Mot de passe actuel</label>
                    <input type="password" id="old_password" name="old_password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label for="new_password">Nouveau mot de passe</label>
                    <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                    <p class="field-help">8 caractères min · 1 lettre + 1 chiffre</p>
                </div>
                <div class="field">
                    <label for="new_password_confirmation">Confirmer</label>
                    <input type="password" id="new_password_confirmation" name="new_password_confirmation" minlength="8" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary">Modifier</button>
            </form>
        </div>

        <!-- ====== Export ====== -->
        <div class="card surface glass rgpd-card">
            <div class="rgpd-card-head">
                <span class="rgpd-icon">📦</span>
                <div>
                    <h2 class="card-title">Exporter mes données</h2>
                    <p class="rgpd-sub">Droit à la portabilité — format JSON.</p>
                </div>
            </div>
            <p class="rgpd-desc">Téléchargez l'ensemble de vos données personnelles : profil, consentements, inscriptions aux événements.</p>
            <a class="btn btn-outline" href="<?= e(url('/account/export')) ?>">📥 Télécharger (JSON)</a>
        </div>

        <!-- ====== Suppression ====== -->
        <div class="card surface glass rgpd-card rgpd-card-danger">
            <div class="rgpd-card-head">
                <span class="rgpd-icon rgpd-icon-danger">🗑️</span>
                <div>
                    <h2 class="card-title">Supprimer mon compte</h2>
                    <p class="rgpd-sub">Droit à l'effacement (RGPD).</p>
                </div>
            </div>
            <p class="rgpd-desc">
                Vos données personnelles seront <strong>anonymisées</strong> et votre compte désactivé.
                Les enregistrements comptables obligatoires sont conservés mais déliés de votre identité.
            </p>
            <a class="btn btn-danger" href="<?= e(url('/account/delete')) ?>">Supprimer mon compte</a>
        </div>

    </div>
</section>

<style>
.rgpd-card {
    padding: 1.75rem;
    margin-bottom: 1.25rem;
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
.rgpd-card .btn { margin-top: 0.5rem; }
</style>
