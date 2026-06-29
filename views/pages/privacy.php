<?php

declare(strict_types=1);

/**
 * Politique de confidentialité (placeholder + contenu CMS si présent).
 *
 * @var array<string,mixed>|null $page
 */
?>
<header class="page-hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">RGPD · Confidentialité</span>
        <h1 class="page-title">Politique de confidentialité</h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <?php if (!empty($page['content'])): ?>
            <div class="prose surface glass"><?= $page['content'] ?></div>
        <?php else: ?>
            <div class="empty-state surface glass">
                <p>Contenu à venir. La politique de confidentialité sera publiée ici prochainement.</p>
            </div>
            <div class="prose surface glass">
                <h2>Données collectées</h2>
                <p>Nom, prénom, adresse e-mail, inscriptions aux événements et commandes cafétéria.</p>
                <h2>Vos droits</h2>
                <p>Conformément au RGPD, vous disposez d'un droit d'accès, de rectification, d'effacement et d'opposition sur vos données.</p>
                <h2>Contact</h2>
                <p>Pour toute demande : <a href="mailto:calais.aeic@gmail.com">calais.aeic@gmail.com</a>.</p>
            </div>
        <?php endif; ?>
    </div>
</section>
