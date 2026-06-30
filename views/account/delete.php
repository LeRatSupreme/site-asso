<?php

declare(strict_types=1);

/**
 * Confirmation de suppression de compte (RGPD).
 *
 * @var array<string,mixed> $user
 */
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <a class="btn btn-outline" href="<?= e(url('/account/privacy')) ?>" style="margin-bottom:1rem;text-decoration:none;">← Retour</a>
        <span class="eyebrow">Zone sensible</span>
        <h1 class="page-title">Supprimer mon compte</h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">

        <div class="card surface glass delete-confirm-card">
            <div class="delete-warn-icon">⚠️</div>
            <h2 class="delete-confirm-title">Cette action est irréversible</h2>

            <p class="delete-confirm-text">
                Vous êtes sur le point de supprimer définitivement le compte
                <strong><?= e($user['email'] ?? '') ?></strong>.
            </p>

            <div class="delete-impact">
                <div class="delete-impact-item">
                    <span class="delete-impact-icon ❌">❌</span>
                    <span>Votre profil (nom, prénom, email, avatar)</span>
                </div>
                <div class="delete-impact-item">
                    <span class="delete-impact-icon">❌</span>
                    <span>Vos inscriptions aux événements</span>
                </div>
                <div class="delete-impact-item">
                    <span class="delete-impact-icon">❌</span>
                    <span>Vos consentements RGPD</span>
                </div>
                <div class="delete-impact-item">
                    <span class="delete-impact-icon">✅</span>
                    <span>Les données comptables sont conservées mais <strong>anonymisées</strong></span>
                </div>
            </div>

            <form method="post" action="<?= e(url('/account/delete')) ?>">
                <?= csrf_field() ?>

                <div class="field">
                    <label for="confirm_text">Tapez <strong>SUPPRIMER</strong> pour confirmer</label>
                    <input type="text" id="confirm_text" name="confirm_text"
                           placeholder="SUPPRIMER"
                           autocomplete="off"
                           style="text-align:center;font-weight:700;text-transform:uppercase;"
                           oninput="var btn=document.getElementById('deleteBtn'); btn.disabled = this.value.toUpperCase() !== 'SUPPRIMER';">
                </div>

                <button type="submit" id="deleteBtn" class="btn btn-danger btn-lg btn-block" disabled>
                    🗑️ Supprimer définitivement mon compte
                </button>
            </form>

            <a class="btn btn-outline btn-block" href="<?= e(url('/account/privacy')) ?>" style="margin-top:0.75rem;text-decoration:none;">
                ← Garder mon compte
            </a>
        </div>

    </div>
</section>

<style>
.delete-confirm-card {
    padding: 2.5rem 2rem;
    text-align: center;
    border-left: 4px solid rgba(239, 68, 68, 0.6);
}
.delete-warn-icon {
    font-size: 3rem;
    margin-bottom: 0.5rem;
}
.delete-confirm-title {
    font-size: 1.3rem;
    font-weight: 800;
    color: var(--accent-danger, #ef4444);
    margin: 0 0 1rem;
}
.delete-confirm-text {
    font-size: 1rem;
    color: var(--foreground);
    margin: 0 0 1.5rem;
}
.delete-impact {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    text-align: left;
    background: rgba(239, 68, 68, 0.06);
    border: 1px solid rgba(239, 68, 68, 0.15);
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}
.delete-impact-item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.9rem;
}
.delete-impact-item:last-child {
    padding-top: 0.6rem;
    border-top: 1px solid rgba(255,255,255,0.06);
    color: var(--accent-success, #22c55e);
}
#deleteBtn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
</style>
