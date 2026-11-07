<?php

declare(strict_types=1);

/**
 * Kiosque — comptage de caisse (à l'aveugle : le théorique n'est pas
 * affiché). Enregistre via la même mécanique que la page admin.
 *
 * @var string $token
 */
?>
<style>
    .kiosk-count-form { max-width: 480px; }
    .kiosk-count-form .field { margin-bottom: 1rem; }
    .kiosk-count-form input[type="text"] {
        width: 100%; padding: 0.8rem 0.9rem;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 12px; background: rgba(255, 255, 255, 0.04);
        color: var(--foreground, inherit); font-size: 1.15rem; font-weight: 700;
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Comptage caisse</h1>
        <p class="muted">Comptage « à l'aveugle » : le montant théorique n'est volontairement pas affiché. L'écart est révélé à l'enregistrement.</p>
    </div>
</div>

<section class="card surface glass">
    <form method="post" action="<?= e(url('/kiosque/comptage/caisse/' . rawurlencode($token))) ?>" class="kiosk-count-form">
        <?= csrf_field() ?>
        <div class="field">
            <label for="counted"><strong>Montant compté (€)</strong></label>
            <input type="text" id="counted" name="counted" inputmode="decimal" placeholder="ex : 152,30" required>
        </div>
        <div class="field">
            <label for="count-label">Note <span class="muted">(optionnel)</span></label>
            <input type="text" id="count-label" name="label" placeholder="ex : comptage après soirée">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Vérifier la caisse</button>
        </div>
    </form>
</section>

<p class="shop-footnote" style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
    <a class="btn btn-ghost btn-sm" href="<?= e(($hubUrl ?? '') !== '' ? $hubUrl : url('/kiosque/comptage/' . rawurlencode($token))) ?>">← Retour au comptage</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/kiosque/comptage/inventaire/' . rawurlencode($token))) ?>">📦 Passer au comptage inventaire</a>
</p>
