<?php

declare(strict_types=1);

/**
 * Page « L'association ».
 *
 * @var array<string,mixed>|null $page
 * @var int $usersCount
 * @var int $eventsCount
 */
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <span class="eyebrow">Qui sommes-nous ?</span>
        <h1 class="page-title">L'AEIC, c'est le campus qui prend vie.</h1>
        <p class="page-lead">Association Étudiante Informatique de Calais — fait par les étudiants, pour les étudiants.</p>
    </div>
</header>

<section class="section">
    <div class="container">
        <div class="panel-brand panel">
            <h2 class="section-title">Notre mission</h2>
            <p class="lead">
                Créer du lien entre les étudiants en informatique de Calais, rythmer la vie du campus
                et rendre concret ce qui ne l'était pas : événements, vie associative, entraide.
            </p>
        </div>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">Nos valeurs</span>
            <h2 class="section-title">Ce qui nous fait avancer</h2>
        </div>
        <div class="grid grid-3">
            <article class="card surface glass card-hover">
                <h3 class="card-title">Proximité</h3>
                <p>Des étudiants comme vous, à côté, qui écoutent et agissent.</p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title">Passion</h3>
                <p>L'informatique et la vie de campus : nos deux moteurs.</p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title">Partage</h3>
                <p>Transmettre, entraider, ouvrir des opportunités à tous.</p>
            </article>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">L'AEIC en chiffres</span>
            <h2 class="section-title">Des actions concrètes</h2>
        </div>
        <div class="grid grid-4">
            <div class="stat-card surface glass"><span class="stat-value"><?= e((string) max($usersCount, 0)) ?></span><span class="stat-label">Membres</span></div>
            <div class="stat-card surface glass"><span class="stat-value"><?= e((string) max($eventsCount, 0)) ?></span><span class="stat-label">Événements</span></div>
            <div class="stat-card surface glass"><span class="stat-value">100 %</span><span class="stat-label">Étudiant</span></div>
            <div class="stat-card surface glass"><span class="stat-value">0</span><span class="stat-label">Prise de tête</span></div>
        </div>
    </div>
</section>

<?php if (!empty($page['content'])): ?>
<section class="section section-alt">
    <div class="container">
        <div class="prose surface glass">
            <?= $page['content'] ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="section cta">
    <div class="container cta-inner">
        <h2 class="section-title">Envie de nous rejoindre ?</h2>
        <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="<?= e(url('/events')) ?>">Rejoindre l'AEIC</a>
            <a class="btn btn-outline btn-lg" href="<?= e(url('/events')) ?>">Voir les événements</a>
        </div>
    </div>
</section>
