<?php

declare(strict_types=1);

/**
 * Kiosque ADMIN — hub : menu de tuiles avec données financières
 * complètes, totalement détaché du hub membres (jeton dédié).
 * AUCUN calcul ici : la marge de chaque période (« marge », en %)
 * arrive prête du contrôleur (adminHub → margePct).
 *
 * @var string $token
 * @var array{ca:float,profit:float,marge:float} $jour
 * @var array{ca:float,profit:float,marge:float} $semaine
 * @var array{ca:float,profit:float,marge:float} $mois
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

    .kx-cards {
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem; margin-bottom: 0.7rem;
    }
    @media (max-width: 560px) {
        .kx-cards { grid-template-columns: 1fr; }
    }
    .kx-card2 {
        display: flex; align-items: center; gap: 0.8rem;
        padding: 0.85rem 0.95rem; border-radius: 16px;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.08);
        text-decoration: none;
        transition: border-color 0.15s ease, background 0.15s ease;
    }
    .kx-card2:hover { border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.07); }
    .kx-emoji {
        flex-shrink: 0; width: 2.5rem; height: 2.5rem;
        display: flex; align-items: center; justify-content: center;
        border-radius: 12px; background: rgba(72, 189, 211, 0.12);
        font-size: 1.25rem; line-height: 1;
    }
    .kx-tile-label { font-size: 0.98rem; font-weight: 900; line-height: 1.2; }
    .kx-chevron {
        margin-left: auto; flex-shrink: 0; line-height: 1;
        font-size: 1.3rem; color: var(--muted, #8892a6);
    }
    /* Dernière carte seule sur sa rangée : pleine largeur. */
    .kx-cards .kx-card2:last-child:nth-child(odd) { grid-column: 1 / -1; width: 100%; }

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
        <p class="kx-hero-sub">Bénéfice : <strong class="<?= $jour['profit'] >= 0 ? '' : 'is-loss' ?>"><?= e(formatPrice($jour['profit'])) ?> (<?= e(number_format((float) $jour['marge'], 1, ',', ' ')) ?> %)</strong></p>
    </div>

    <div class="kx-pair">
        <div class="kx-card">
            <p class="kx-card-label">Semaine actuelle</p>
            <div class="kx-card-value"><?= e(formatPrice($semaine['ca'])) ?></div>
            <p class="kx-card-sub <?= $semaine['profit'] < 0 ? 'is-loss' : '' ?>">+<?= e(formatPrice($semaine['profit'])) ?> bénéfice · <?= e(number_format((float) $semaine['marge'], 1, ',', ' ')) ?> %</p>
        </div>
        <div class="kx-card">
            <p class="kx-card-label">Ce mois</p>
            <div class="kx-card-value"><?= e(formatPrice($mois['ca'])) ?></div>
            <p class="kx-card-sub <?= $mois['profit'] < 0 ? 'is-loss' : '' ?>">+<?= e(formatPrice($mois['profit'])) ?> bénéfice · <?= e(number_format((float) $mois['marge'], 1, ',', ' ')) ?> %</p>
        </div>
    </div>

<div class="kx-cards">
    <a class="kx-card2" href="<?= e(url('/kiosque/comptage/jour/' . rawurlencode($token))) ?>">
        <span class="kx-emoji" aria-hidden="true">📊</span>
        <span class="kx-tile-label">Récap du jour</span>
        <span class="kx-chevron" aria-hidden="true">›</span>
    </a>
    <a class="kx-card2" href="<?= e(url('/kiosque/admin/ledger/' . rawurlencode($token))) ?>">
        <span class="kx-emoji" aria-hidden="true">🧾</span>
        <span class="kx-tile-label">Livre comptable</span>
        <span class="kx-chevron" aria-hidden="true">›</span>
    </a>
    <a class="kx-card2" href="<?= e(url('/kiosque/admin/analytics/' . rawurlencode($token))) ?>">
        <span class="kx-emoji" aria-hidden="true">📈</span>
        <span class="kx-tile-label">Analytique</span>
        <span class="kx-chevron" aria-hidden="true">›</span>
    </a>
    <a class="kx-card2" href="<?= e(url('/kiosque/admin/reco/' . rawurlencode($token))) ?>">
        <span class="kx-emoji" aria-hidden="true">💡</span>
        <span class="kx-tile-label">Idées du moment</span>
        <span class="kx-chevron" aria-hidden="true">›</span>
    </a>
    <a class="kx-card2" href="<?= e(url('/kiosque/liste/' . rawurlencode($token))) ?>">
        <span class="kx-emoji" aria-hidden="true">🛒</span>
        <span class="kx-tile-label">Liste de courses</span>
        <span class="kx-chevron" aria-hidden="true">›</span>
    </a>
    <a class="kx-card2" href="<?= e(url('/kiosque/comptage/caisse/' . rawurlencode($token))) ?>">
        <span class="kx-emoji" aria-hidden="true">💵</span>
        <span class="kx-tile-label">Comptage caisse</span>
        <span class="kx-chevron" aria-hidden="true">›</span>
    </a>
    <a class="kx-card2" href="<?= e(url('/kiosque/admin/caisse/depot/' . rawurlencode($token))) ?>">
        <span class="kx-emoji" aria-hidden="true">🏦</span>
        <span class="kx-tile-label">Dépôt banque</span>
        <span class="kx-chevron" aria-hidden="true">›</span>
    </a>
    <a class="kx-card2" href="<?= e(url('/kiosque/comptage/inventaire/' . rawurlencode($token))) ?>">
        <span class="kx-emoji" aria-hidden="true">📦</span>
        <span class="kx-tile-label">Comptage inventaire</span>
        <span class="kx-chevron" aria-hidden="true">›</span>
    </a>
    <a class="kx-card2" href="<?= e(url('/kiosque/reappro/' . rawurlencode($token))) ?>">
        <span class="kx-emoji" aria-hidden="true">📈</span>
        <span class="kx-tile-label">Réappro</span>
        <span class="kx-chevron" aria-hidden="true">›</span>
    </a>
</div>

<p class="kah-foot">
    Outils en bas de grille : accès direct aux pages kiosque membres.<br>
    Journal des ventes et dashboard complet : depuis
    <a href="<?= e(url('/admin/compta')) ?>">l'espace admin</a> (connexion requise).
</p>
</div>
