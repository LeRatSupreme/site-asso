<?php

declare(strict_types=1);

/**
 * Kiosque « Comptage » : hub avec les deux saisies (caisse / inventaire),
 * accessibles sans connexion via le lien secret.
 *
 * @var string $token
 */
?>
<style>
    .kiosk-hub { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.8rem; }
    .kiosk-hub a {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.5rem;
        aspect-ratio: 4 / 3; padding: 1.2rem; text-align: center; text-decoration: none;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 18px;
        transition: border-color 0.15s ease, background 0.15s ease;
    }
    .kiosk-hub a:hover { border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.07); }
    .kiosk-hub .kiosk-emoji { font-size: 2.4rem; }
    .kiosk-hub .kiosk-tile-title { font-size: 1.15rem; font-weight: 900; }
    .kiosk-hub .kiosk-tile-sub { font-size: 0.8rem; color: var(--muted, #8892a6); }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Comptage</h1>
        <p class="muted">Choisis ce que tu fais — l'enregistrement est immédiat.</p>
    </div>
</div>

<div class="kiosk-hub">
    <a href="<?= e(url('/kiosque/comptage/caisse/' . rawurlencode($token))) ?>">
        <span class="kiosk-emoji">💵</span>
        <span class="kiosk-tile-title">Comptage caisse</span>
        <span class="kiosk-tile-sub">liquide présent dans la caisse</span>
    </a>
    <a href="<?= e(url('/kiosque/comptage/inventaire/' . rawurlencode($token))) ?>">
        <span class="kiosk-emoji">📦</span>
        <span class="kiosk-tile-title">Comptage inventaire</span>
        <span class="kiosk-tile-sub">produits en stock, à l'aveugle</span>
    </a>
    <a href="<?= e(url('/kiosque/liste/' . rawurlencode($token))) ?>">
        <span class="kiosk-emoji">🛒</span>
        <span class="kiosk-tile-title">Liste de courses</span>
        <span class="kiosk-tile-sub">ce qu'il faut racheter</span>
    </a>
</div>
