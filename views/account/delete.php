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
        <span class="eyebrow">Zone sensible</span>
        <h1 class="page-title">Supprimer mon compte</h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <form class="auth-form surface glass" method="post" action="<?= e(url('/account/delete')) ?>">
            <?= csrf_field() ?>

            <div class="alert alert-danger">
                Cette action est <strong>irréversible</strong>. Votre profil, votre avatar
                et vos inscriptions seront effacés. Votre compte sera désactivé.
            </div>

            <p class="card-excerpt">
                Confirmez la suppression de votre compte
                <strong><?= e($user['email'] ?? '') ?></strong>.
            </p>

            <button type="submit" class="btn btn-destructive btn-block">Oui, supprimer définitivement</button>
            <a class="btn btn-ghost btn-block" href="<?= e(url('/account/privacy')) ?>">Annuler</a>
        </form>
    </div>
</section>
