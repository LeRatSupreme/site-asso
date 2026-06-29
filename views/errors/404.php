<?php

declare(strict_types=1);

/**
 * Erreur 404 — page introuvable.
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Erreur 404</span>
        <h1 class="page-title">Page introuvable.</h1>
        <p class="page-lead">La page que vous cherchez n'existe pas ou a été déplacée.</p>
    </div>
</header>
<section class="section">
    <div class="container center">
        <a class="btn btn-primary btn-lg" href="<?= e(url('/')) ?>">Retour à l'accueil</a>
    </div>
</section>
