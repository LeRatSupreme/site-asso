<?php

declare(strict_types=1);

/**
 * Kiosque — VUE PRÉSIDENT : une page, TROIS gros chiffres, zéro jargon,
 * zéro clic (lecteur qui « ne connaît rien à l'économie »). Ventes du
 * mois, bénéfice du mois, stock restant — RIEN d'autre : pas de marge %,
 * pas de détail des paiements, pas de top produits, pas de date.
 *
 * La valeur € du stock n'apparaît que si l'option « couts » du kiosque
 * est cochée (le contrôleur ne l'envoie même pas sinon) ; l'option
 * « benefice » est exigée par la garde de la page.
 *
 * @var string $token
 * @var array{ca:float,profit:float,label:string} $mois  CA/bénéfice du mois + nom français du mois
 * @var array{units:int,value:?float}             $stock Unités restantes ; value = € au dernier coût (null si option « couts » absente)
 */
?>
<style>
    .kp-wrap { max-width: 34rem; margin: 0 auto; }

    .kp-kpi {
        text-align: center;
        padding: 1.6rem 1.2rem 1.4rem; margin-bottom: 0.8rem;
        background: rgba(255, 255, 255, 0.035);
        border: 1px solid rgba(255, 255, 255, 0.07);
        border-radius: 22px;
    }
    .kp-kpi:first-of-type {
        background: linear-gradient(160deg, rgba(72, 189, 211, 0.16), rgba(97, 80, 170, 0.08));
        border-color: rgba(72, 189, 211, 0.3);
    }
    .kp-kpi-label {
        font-size: 0.85rem; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.08em; color: var(--muted, #8892a6); margin: 0 0 0.6rem;
    }
    .kp-kpi-value {
        font-size: clamp(2.6rem, 12vw, 3.8rem); font-weight: 900; line-height: 1;
        letter-spacing: -0.02em;
    }
    .kp-kpi:first-of-type .kp-kpi-value { color: var(--primary, #48bdd3); }
    .kp-kpi-value.is-profit { color: #4ade80; }
    .kp-kpi-value.is-loss { color: #f87171; }
    .kp-kpi-sub { margin: 0.7rem 0 0; font-size: 1rem; color: var(--muted, #8892a6); }
    .kp-kpi-sub strong { color: var(--foreground, inherit); font-size: 1.1rem; }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Kiosque</p>
        <h1 class="page-title">Vue Président</h1>
        <p class="muted">La cafétéria en trois chiffres, pour le mois de <?= e($mois['label']) ?>.</p>
    </div>
</div>

<div class="kp-wrap">
    <div class="kp-kpi" data-kpi="ca">
        <p class="kp-kpi-label">Ventes du mois</p>
        <div class="kp-kpi-value"><?= e(formatPrice((float) $mois['ca'])) ?></div>
    </div>

    <div class="kp-kpi" data-kpi="benefice">
        <p class="kp-kpi-label">Bénéfice du mois</p>
        <div class="kp-kpi-value <?= (float) $mois['profit'] >= 0 ? 'is-profit' : 'is-loss' ?>">
            <?= e(formatPrice((float) $mois['profit'])) ?>
        </div>
    </div>

    <div class="kp-kpi" data-kpi="stock">
        <p class="kp-kpi-label">Stock restant</p>
        <div class="kp-kpi-value"><?= (int) $stock['units'] ?></div>
        <?php if ($stock['value'] !== null): ?>
        <p class="kp-kpi-sub">soit environ <strong><?= e(formatPrice((float) $stock['value'])) ?></strong> de marchandises</p>
        <?php endif; ?>
    </div>
</div>
