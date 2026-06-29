<?php

declare(strict_types=1);

/**
 * Erreur 403 — accès interdit.
 */
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Erreur 403</span>
        <h1 class="page-title">Accès interdit.</h1>
        <p class="page-lead">Vous n'avez pas la permission d'accéder à cette page.</p>
    </div>
</header>
<section class="section">
    <div class="container center">
        <a class="btn btn-primary btn-lg" href="<?= e(url('/')) ?>">Retour à l'accueil</a>
    </div>
</section>
