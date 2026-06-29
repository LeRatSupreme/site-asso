<?php

declare(strict_types=1);

/**
 * Page d'accueil AEIC.
 *
 * @var string $siteName
 * @var string $description
 * @var list<array<string,mixed>> $events
 */
?>
<section class="hero">
    <div class="halo halo-teal" aria-hidden="true"></div>
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container hero-grid">
        <div class="hero-content">
            <span class="eyebrow">Association étudiante · Informatique · Calais</span>
            <h1 class="hero-title">
                Plus qu'une asso.
                <span class="accent">Ton campus, en mieux.</span>
            </h1>
            <p class="hero-lead">
                <?= e($description ?: 'L\'AEIC réunit les étudiants en informatique du campus de Calais : événements, cafétéria, vie étudiante. Fait par les étudiants, pour les étudiants.') ?>
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="<?= e(url('/presentation')) ?>">Rejoindre l'AEIC</a>
                <a class="btn btn-outline btn-lg" href="<?= e(url('/events')) ?>">Voir les événements</a>
            </div>
        </div>

        <aside class="hero-stats surface glass" aria-label="L'AEIC en chiffres">
            <div class="stat">
                <span class="stat-value">100 %</span>
                <span class="stat-label">Étudiant</span>
            </div>
            <div class="stat">
                <span class="stat-value">0 %</span>
                <span class="stat-label">Prise de tête</span>
            </div>
            <div class="stat">
                <span class="stat-value"><?= e((string) count($events)) ?></span>
                <span class="stat-label">Événements à venir</span>
            </div>
            <div class="stat">
                <span class="stat-value aeic-gradient-text">AEIC</span>
                <span class="stat-label">Depuis toujours</span>
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

        <?php if (empty($events)): ?>
            <div class="empty-state surface glass">
                <p>Aucun événement annoncé pour le moment. Revenez bientôt !</p>
            </div>
        <?php else: ?>
            <div class="grid grid-3">
                <?php foreach ($events as $event): ?>
                    <article class="card card-hover surface glass">
                        <?php if (!empty($event['is_featured'])): ?>
                            <span class="badge badge-gradient">À la une</span>
                        <?php endif; ?>
                        <p class="card-date"><?= e(formatDate($event['date'])) ?></p>
                        <h3 class="card-title"><?= e($event['title']) ?></h3>
                        <?php if (!empty($event['excerpt'])): ?>
                            <p class="card-excerpt"><?= e($event['excerpt']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($event['location'])): ?>
                            <p class="card-meta"><?= e($event['location']) ?></p>
                        <?php endif; ?>
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/events/' . $event['slug'])) ?>">Détails</a>
                    </article>
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
