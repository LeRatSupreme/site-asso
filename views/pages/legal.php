<?php

declare(strict_types=1);

/**
 * Mentions légales (placeholder + contenu CMS si présent).
 *
 * @var array<string,mixed>|null $page
 */
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Informations légales</span>
        <h1 class="page-title">Mentions légales</h1>
    </div>
</header>

<section class="section">
    <div class="container narrow">
        <?php if (!empty($page['content'])): ?>
            <div class="prose surface glass"><?= $page['content'] ?></div>
        <?php else: ?>
            <div class="empty-state surface glass">
                <p>Contenu à venir. Les mentions légales seront publiées ici prochainement.</p>
            </div>
            <div class="prose surface glass">
                <h2>Éditeur du site</h2>
                <p>AEIC — Association Étudiante Informatique de Calais.</p>
                <h2>Responsable de publication</h2>
                <p>Le bureau de l'AEIC.</p>
                <h2>Hébergement</h2>
                <p>Site hébergé sur un serveur dédié (VPS).</p>
            </div>
        <?php endif; ?>
    </div>
</section>
