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
    .kah-strip {
        display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.7rem; max-width: 44rem; margin: 0 auto 1.3rem;
    }
    .kah-strip-card {
        padding: 0.9rem 0.7rem; text-align: center;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 16px;
    }
    .kah-strip-card .kah-k {
        font-size: 0.68rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.06em; color: var(--muted, #8892a6); margin: 0 0 0.3rem;
    }
    .kah-strip-card .kah-ca { font-size: 1.25rem; font-weight: 900; color: var(--primary, #48bdd3); }
    .kah-strip-card .kah-pf { font-size: 0.85rem; font-weight: 800; margin-top: 0.15rem; color: #4ade80; }
    .kah-strip-card .kah-pf.is-loss { color: #f87171; }

    .kah-grid {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.8rem; max-width: 44rem; margin: 0 auto;
    }
    .kah-grid a {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.5rem;
        aspect-ratio: 4 / 3; padding: 1.2rem; text-align: center; text-decoration: none;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 18px;
        transition: border-color 0.15s ease, background 0.15s ease;
    }
    .kah-grid a:hover { border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.07); }
    .kah-grid .kiosk-emoji { font-size: 2.2rem; }
    .kah-grid .kiosk-tile-title { font-size: 1.05rem; font-weight: 900; }
    .kah-grid .kiosk-tile-sub { font-size: 0.76rem; color: var(--muted, #8892a6); }
    /* Tuile seule sur la 2e rangée : même taille, centrée. */
    .kah-grid a:last-child:nth-child(odd) {
        grid-column: 1 / -1;
        justify-self: center;
        width: calc(50% - 0.4rem);
    }
    .kah-foot {
        max-width: 44rem; margin: 1.1rem auto 0; text-align: center;
        font-size: 0.78rem; color: var(--muted, #8892a6);
    }
    @media (max-width: 480px) {
        .kah-strip { grid-template-columns: 1fr; }
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité — accès admin</p>
        <h1 class="page-title">Kiosque admin</h1>
        <p class="muted">Toutes les données financières du jour, de la semaine et du mois.</p>
    </div>
</div>

<div class="kah-strip">
    <div class="kah-strip-card">
        <p class="kah-k">Aujourd'hui</p>
        <p class="kah-ca"><?= e(formatPrice($jour['ca'])) ?></p>
        <p class="kah-pf <?= $jour['profit'] >= 0 ? '' : 'is-loss' ?>"><?= e(formatPrice($jour['profit'])) ?> bénéfice</p>
    </div>
    <div class="kah-strip-card">
        <p class="kah-k">7 derniers jours</p>
        <p class="kah-ca"><?= e(formatPrice($semaine['ca'])) ?></p>
        <p class="kah-pf <?= $semaine['profit'] >= 0 ? '' : 'is-loss' ?>"><?= e(formatPrice($semaine['profit'])) ?> bénéfice</p>
    </div>
    <div class="kah-strip-card">
        <p class="kah-k">Ce mois</p>
        <p class="kah-ca"><?= e(formatPrice($mois['ca'])) ?></p>
        <p class="kah-pf <?= $mois['profit'] >= 0 ? '' : 'is-loss' ?>"><?= e(formatPrice($mois['profit'])) ?> bénéfice</p>
    </div>
</div>

<div class="kah-grid">
    <a href="<?= e(url('/kiosque/comptage/jour/' . rawurlencode($token))) ?>">
        <span class="kiosk-emoji">📊</span>
        <span class="kiosk-tile-title">Récap du jour</span>
        <span class="kiosk-tile-sub">détail complet, auto-actualisé</span>
    </a>
    <a href="<?= e(url('/kiosque/admin/semaine/' . rawurlencode($token))) ?>">
        <span class="kiosk-emoji">📅</span>
        <span class="kiosk-tile-title">7 derniers jours</span>
        <span class="kiosk-tile-sub">jour par jour + top produits</span>
    </a>
    <a href="<?= e(url('/kiosque/admin/mois/' . rawurlencode($token))) ?>">
        <span class="kiosk-emoji">🗓️</span>
        <span class="kiosk-tile-title">Mois en cours</span>
        <span class="kiosk-tile-sub">vs mois précédent + top</span>
    </a>
</div>

<p class="kah-foot">
    Analytics, journal des ventes et dashboard complet : accessible depuis
    <a href="<?= e(url('/admin/compta')) ?>" style="color: var(--primary, #48bdd3);">l'espace admin</a>
    (connexion requise) — volontairement pas liés ici.
</p>

<p class="kah-foot">
    Gestion des liens et régénération : Système → Kiosques (connexion admin).
</p>
