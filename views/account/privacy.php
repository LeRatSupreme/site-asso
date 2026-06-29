<?php

declare(strict_types=1);

/**
 * Page « Mes données » (RGPD) : actions portabilité / effacement.
 *
 * @var array<string,mixed> $user
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">RGPD · Vos droits</span>
        <h1 class="page-title">Mes données</h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <div class="card surface glass">
            <h2 class="card-title">Exporter mes données</h2>
            <p class="card-excerpt">
                Conformément au droit à la portabilité, téléchargez l'ensemble de vos données
                personnelles au format JSON (profil, consentements, inscriptions, commandes).
            </p>
            <a class="btn btn-outline" href="<?= e(url('/account/export')) ?>">Télécharger (JSON)</a>
        </div>

        <div class="card surface glass card-danger">
            <h2 class="card-title">Supprimer mon compte</h2>
            <p class="card-excerpt">
                Vos données personnelles seront anonymisées et votre compte désactivé
                (droit à l'effacement). Les enregistrements comptables obligatoires sont
                conservés mais déliés de votre identité.
            </p>
            <a class="btn btn-destructive" href="<?= e(url('/account/delete')) ?>">Supprimer mon compte</a>
        </div>
    </div>
</section>
