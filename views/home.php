<?php

declare(strict_types=1);

/**
 * Page d'accueil AEIC.
 *
 * @var string $siteName
 * @var string $description
 * @var list<array<string,mixed>> $upcoming
 * @var int $eventsCount
 * @var int $usersCount
 */
?>
<section class="hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container hero-grid">
        <div class="hero-content">
            <span class="eyebrow"><?= e(t('Association étudiante · Informatique · Calais', 'Student association · Computer Science · Calais')) ?></span>
            <h1 class="hero-title">
                <?= e(t('Plus qu\'une asso.', 'More than an association.')) ?>
                <span class="accent"><?= e(t('Ton campus, en mieux.', 'Your campus, but better.')) ?></span>
            </h1>
            <p class="hero-lead">
                <?= e($description ?: t('L\'AEIC réunit les étudiants en informatique du campus de Calais : événements, cafétéria, vie étudiante. Fait par les étudiants, pour les étudiants.', 'The AEIC brings together computer science students on the Calais campus: events, cafeteria, student life. Made by students, for students.')) ?>
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="<?= e(url('/presentation')) ?>"><?= e(t("Rejoindre l'AEIC", 'Join AEIC')) ?></a>
                <a class="btn btn-outline btn-lg" href="<?= e(url('/events')) ?>"><?= e(t('Voir les événements', 'See events')) ?></a>
            </div>
        </div>

        <aside class="hero-stats surface glass" aria-label="L'AEIC en chiffres">
            <div class="stat">
                <span class="stat-value">100 %</span>
                <span class="stat-label">Étudiant</span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= e((string) max($usersCount, 0)) ?></span>
                <span class="stat-label">Membres</span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= e((string) max($eventsCount, 0)) ?></span>
                <span class="stat-label">Événements</span>
            </div>
            <div class="stat">
                <span class="stat-value">0 %</span>
                <span class="stat-label">Prise de tête</span>
            </div>
        </aside>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">Agenda</span>
            <h2 class="section-title">Prochains événements</h2>
        </div>

        <?php if (empty($upcoming)): ?>
            <div class="empty-state surface glass">
                <p>Aucun événement annoncé pour le moment. Revenez bientôt !</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($upcoming as $event): ?>
                    <?php require AEIC_VIEWS . '/partials/event_card.php'; ?>
                <?php endforeach; ?>
            </div>
            <p class="section-more"><a class="btn btn-ghost" href="<?= e(url('/events')) ?>">Tout voir →</a></p>
        <?php endif; ?>
    </div>
</section>

<section class="section section-alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">Pourquoi l'AEIC</span>
            <h2 class="section-title">La vie étudiante, sans friction</h2>
        </div>
        <div class="grid grid-3">
            <article class="card surface glass card-hover">
                <h3 class="card-title">Événements</h3>
                <p>Soirées d'intégration, LAN, conférences : un agenda pensé pour les étudiants en info.</p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title">Cafétéria</h3>
                <p>Des prix étudiant, commandable en ligne, prêt à récupérer entre deux cours.</p>
            </article>
            <article class="card surface glass card-hover">
                <h3 class="card-title">Communauté</h3>
                <p>Un réseau d'entraide, des projets, et des gens qui font avancer le campus.</p>
            </article>
        </div>
    </div>
</section>
