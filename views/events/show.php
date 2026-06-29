<?php

declare(strict_types=1);

use App\Core\Auth;

/**
 * Détail d'un événement.
 *
 * @var array<string,mixed> $event
 * @var list<array<string,mixed>> $variants
 * @var int $registrationsCount
 * @var list<array<string,mixed>> $participants
 * @var list<array<string,mixed>> $photos
 * @var bool $isPast
 * @var bool $isRegistered
 */

$event     = $event ?? [];
$variants  = $variants ?? [];
$participants = $participants ?? [];
$photos    = $photos ?? [];
$isPast    = $isPast ?? false;
$isRegistered = $isRegistered ?? false;

$title       = (string) ($event['title'] ?? '');
$excerpt     = (string) ($event['excerpt'] ?? '');
$description = (string) ($event['description'] ?? '');
$location    = (string) ($event['location'] ?? '');
$dateRaw     = (string) ($event['date'] ?? '');
$endDateRaw  = (string) ($event['end_date'] ?? '');
$image       = (string) ($event['image'] ?? '');
$price       = $event['price'] ?? null;
$sumupLink   = sumup_link((string) ($event['sumup_link'] ?? ''));
$maxCapacity = $event['max_capacity'] ?? null;

$imageUrl = '';
if ($image !== '') {
    $imageUrl = is_absolute_url($image) ? $image : asset(ltrim($image, '/'));
}

$priceLabel = ($price === null || (float) $price <= 0)
    ? 'Gratuit'
    : formatPrice($price);
?>
<header class="page-hero">
    <div class="halo halo-violet" aria-hidden="true"></div>
    <div class="container">
        <p class="back-link">
            <a href="<?= e(url('/events')) ?>">← Retour aux événements</a>
        </p>
        <span class="eyebrow">Événement</span>
        <h1 class="page-title"><?= e($title) ?></h1>
    </div>
</header>

<section class="section">
    <div class="container event-detail">
        <div class="event-detail-main">
            <div class="event-cover surface">
                <?php if ($imageUrl !== ''): ?>
                    <img src="<?= e($imageUrl) ?>" alt="<?= e($title) ?>" loading="lazy">
                <?php else: ?>
                    <div class="event-cover-placeholder aeic-gradient" aria-hidden="true">AE</div>
                <?php endif; ?>
                <?php if ($isPast): ?>
                    <span class="badge badge-muted event-cover-badge">Événement terminé</span>
                <?php endif; ?>
            </div>

            <article class="card surface glass">
                <span class="eyebrow">Détails</span>
                <h2 class="card-title"><?= e($title) ?></h2>
                <p class="card-meta">
                    📅 <?= e(formatDateTime($dateRaw)) ?>
                    <?php if ($endDateRaw !== ''): ?> → <?= e(formatDateTime($endDateRaw)) ?><?php endif; ?>
                    <?php if ($location !== ''): ?> · 📍 <?= e($location) ?><?php endif; ?>
                </p>
                <?php if ($excerpt !== ''): ?>
                    <p class="card-excerpt"><?= e($excerpt) ?></p>
                <?php endif; ?>
            </article>

            <?php if ($description !== ''): ?>
                <div class="prose surface glass"><?= $description ?></div>
            <?php endif; ?>

            <?php if (!empty($photos)): ?>
                <div class="event-gallery">
                    <span class="eyebrow">Galerie</span>
                    <h2 class="section-title">Photos</h2>
                    <div class="grid grid-3 gallery-grid">
                        <?php foreach ($photos as $photo): ?>
                            <figure class="gallery-item surface">
                                <img src="<?= e(is_absolute_url($photo['url']) ? $photo['url'] : asset(ltrim($photo['url'], '/'))) ?>"
                                     alt="<?= e($photo['caption'] ?? '') ?>"
                                     loading="lazy">
                                <?php if (!empty($photo['caption'])): ?>
                                    <figcaption><?= e($photo['caption']) ?></figcaption>
                                <?php endif; ?>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <aside class="event-detail-sidebar">
            <?php if (!$isPast): ?>
                <div class="card surface glass sidebar-card">
                    <h2 class="card-title">Participer</h2>
                    <p class="sidebar-price">
                        <span class="eyebrow">Prix</span>
                        <strong class="stat-value"><?= e($priceLabel) ?></strong>
                    </p>
                    <?php if ($maxCapacity !== null): ?>
                        <p class="card-meta">Capacité : <?= e((string) $maxCapacity) ?> places · <?= e((string) $registrationsCount) ?> inscrits</p>
                    <?php endif; ?>

                    <?php if (Auth::check()): ?>
                        <?php if ($isRegistered): ?>
                            <p class="badge badge-success">Vous êtes inscrit·e</p>
                            <form method="post" action="<?= e(url('/events/' . rawurlencode((string) $event['slug']) . '/unregister')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline btn-block">Se désinscrire</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('/events/' . rawurlencode((string) $event['slug']) . '/register')) ?>">
                                <?= csrf_field() ?>

                                <?php if (!empty($variants)): ?>
                                    <?php foreach ($variants as $variant): ?>
                                        <div class="field">
                                            <label for="variant-<?= e((string) $variant['id']) ?>">
                                                <?= e($variant['label'] ?? '') ?>
                                                <?php if (!empty($variant['required'])): ?><span class="badge badge-warning">Obligatoire</span><?php endif; ?>
                                            </label>
                                            <select id="variant-<?= e((string) $variant['id']) ?>"
                                                    name="variants[<?= e((string) $variant['id']) ?>]"
                                                    <?= !empty($variant['required']) ? 'required' : '' ?>>
                                                <option value="">— Choisir —</option>
                                                <?php foreach ($variant['choices'] ?? [] as $choice): ?>
                                                    <option value="<?= e((string) $choice['id']) ?>"><?= e($choice['label'] ?? '') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <button type="submit" class="btn btn-primary btn-lg btn-block">Je m'inscris</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="card-excerpt">Connectez-vous pour vous inscrire à cet événement.</p>
                        <a class="btn btn-primary btn-block" href="<?= e(url('/login?callbackUrl=') . rawurlencode('/events/' . ($event['slug'] ?? ''))) ?>">Se connecter</a>
                        <a class="btn btn-outline btn-block" href="<?= e(url('/register')) ?>">Créer un compte</a>
                    <?php endif; ?>

                    <?php if ($sumupLink !== null): ?>
                        <a class="btn btn-sumup btn-block" href="<?= e($sumupLink) ?>" rel="noopener noreferrer" target="_blank">Payer en ligne (SumUp)</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card surface glass sidebar-card">
                <h2 class="card-title">Participants</h2>
                <p class="sidebar-count">
                    <strong class="stat-value"><?= e((string) $registrationsCount) ?></strong>
                    <span class="stat-label">inscrits</span>
                </p>
                <?php if (!empty($participants)): ?>
                    <ul class="participant-list">
                        <?php foreach ($participants as $p): ?>
                            <li><?= e(trim(($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? ''))) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="card-meta">Soyez les premiers à vous inscrire !</p>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</section>
