<?php

declare(strict_types=1);

/**
 * Conditions Générales d'Utilisation (placeholder + contenu CMS si présent).
 *
 * @var array<string,mixed>|null $page
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Conditions d'utilisation</span>
        <h1 class="page-title">CGU</h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <?php if (!empty($page['content'])): ?>
            <div class="prose surface glass"><?= $page['content'] ?></div>
        <?php else: ?>
            <div class="empty-state surface glass">
                <p>Contenu à venir. Les conditions d'utilisation seront publiées ici prochainement.</p>
            </div>
            <div class="prose surface glass">
                <h2>Objet</h2>
                <p>Les présentes conditions régissent l'utilisation du site de l'AEIC et de l'espace membre.</p>
                <h2>Inscription</h2>
                <p>L'inscription est réservée aux étudiants. Les informations fournies doivent être exactes.</p>
                <h2>Responsabilité</h2>
                <p>L'AEIC s'efforce de maintenir le site accessible mais ne saurait être tenue responsable des interruptions de service.</p>
            </div>
        <?php endif; ?>
    </div>
</section>
