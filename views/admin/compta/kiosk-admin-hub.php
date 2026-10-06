<?php

declare(strict_types=1);

/**
 * Kiosque ADMIN — hub : menu de tuiles avec données financières
 * complètes, totalement détaché du hub membres (jeton dédié).
 *
 * @var string $token
 * @var array{ca:float,profit:float} $jour
 * @var array{ca:float,profit:float} $semaine
 * @var array{ca:float,profit:float} $mois
 */
?>
<style>
    .kx-wrap { max-width: 44rem; margin: 0 auto; }
    .kx-hero {
        text-align: center;
        padding: 1.7rem 1rem 1.5rem; margin-bottom: 0.7rem;
        background: linear-gradient(160deg, rgba(72, 189, 211, 0.16), rgba(97, 80, 170, 0.08));
        border: 1px solid rgba(72, 189, 211, 0.3);
        border-radius: 22px;
    }
    .kx-hero-label {
        font-size: 0.8rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.08em; color: var(--muted, #8892a6); margin: 0 0 0.5rem;
    }
    .kx-hero-value {
        font-size: clamp(2.3rem, 9vw, 3rem); font-weight: 900; line-height: 1;
        color: var(--primary, #48bdd3); letter-spacing: -0.02em;
    }
    .kx-hero-sub { margin: 0.75rem 0 0; font-size: 0.88rem; color: var(--muted, #8892a6); }
    .kx-hero-sub strong { color: var(--foreground, inherit); }
    .kx-hero-sub strong.is-loss { color: #f87171; }

    .kx-pair {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.7rem; margin-bottom: 0.7rem;
    }
    .kx-card {
        padding: 1.05rem 0.6rem; text-align: center;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 18px;
    }
    .kx-card-label {
        font-size: 0.75rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.07em; color: var(--muted, #8892a6); margin: 0 0 0.4rem;
    }
    .kx-card-value { font-size: 1.5rem; font-weight: 900; line-height: 1.1; color: var(--primary, #48bdd3); }
    .kx-card-sub { font-size: 0.75rem; font-weight: 800; color: #4ade80; }
    .kx-card-sub.is-loss { color: #f87171; }

    .kah-grid {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.7rem; margin-bottom: 0.7rem;
    }
    .kah-grid a {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.45rem;
        aspect-ratio: 1 / 1; padding: 0.9rem 0.6rem; text-align: center; text-decoration: none;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 20px;
        transition: border-color 0.15s ease, background 0.15s ease;
    }
    .kah-grid a:hover { border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.07); }
    .kah-grid .kiosk-emoji { font-size: 2.3rem; }
    .kah-grid .kiosk-tile-title { font-size: 0.98rem; font-weight: 900; }
    /* Tuile seule sur la 2e rangée : même taille, centrée. */
    .kah-grid a:last-child:nth-child(odd) {
        grid-column: 1 / -1;
        justify-self: center;
        width: calc(50% - 0.35rem);
    }

    .kah-foot {
        max-width: 44rem; margin: 1.1rem auto 0; text-align: center;
        font-size: 0.78rem; color: var(--muted, #8892a6);
    }
    .kah-foot a { color: var(--primary, #48bdd3); }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité — accès admin</p>
        <h1 class="page-title">Kiosque admin</h1>
        <p class="muted">Toutes les données financières du jour, de la semaine et du mois.</p>
    </div>
</div>

<div class="kx-wrap">
    <div class="kx-hero">
        <p class="kx-hero-label">Aujourd'hui</p>
        <div class="kx-hero-value"><?= e(formatPrice($jour['ca'])) ?></div>
        <p class="kx-hero-sub">Bénéfice : <strong class="<?= $jour['profit'] >= 0 ? '' : 'is-loss' ?>"><?= e(formatPrice($jour['profit'])) ?></strong></p>
    </div>

    <div class="kx-pair">
        <div class="kx-card">
            <p class="kx-card-label">7 derniers jours</p>
            <div class="kx-card-value"><?= e(formatPrice($semaine['ca'])) ?></div>
            <p class="kx-card-sub <?= $semaine['profit'] < 0 ? 'is-loss' : '' ?>">+<?= e(formatPrice($semaine['profit'])) ?> bénéfice</p>
        </div>
        <div class="kx-card">
            <p class="kx-card-label">Ce mois</p>
            <div class="kx-card-value"><?= e(formatPrice($mois['ca'])) ?></div>
            <p class="kx-card-sub <?= $mois['profit'] < 0 ? 'is-loss' : '' ?>">+<?= e(formatPrice($mois['profit'])) ?> bénéfice</p>
        </div>
    </div>

    <div class="kah-grid">
        <a href="<?= e(url('/kiosque/comptage/jour/' . rawurlencode($token))) ?>">
            <span class="kiosk-emoji">📊</span>
            <span class="kiosk-tile-title">Récap du jour</span>
        </a>
        <a href="<?= e(url('/kiosque/admin/semaine/' . rawurlencode($token))) ?>">
            <span class="kiosk-emoji">📅</span>
            <span class="kiosk-tile-title">7 jours</span>
        </a>
        <a href="<?= e(url('/kiosque/admin/mois/' . rawurlencode($token))) ?>">
            <span class="kiosk-emoji">🗓️</span>
            <span class="kiosk-tile-title">Mois</span>
        </a>
    </div>

    <p class="kah-foot">
        Analytics, journal des ventes et dashboard complet : depuis
        <a href="<?= e(url('/admin/compta')) ?>">l'espace admin</a> (connexion requise).
    </p>
</div>
