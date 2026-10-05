<?php

declare(strict_types=1);

/**
 * Kiosque — comptage inventaire « à l'aveugle » : les théoriques ne sont
 * volontairement pas affichés, les écarts sont calculés à l'enregistrement.
 * Les produits en pause sont listés sans saisie.
 *
 * @var string $token
 * @var list<array{key:string,paused:bool}> $rows
 */
?>
<style>
    .kiosk-inv-list { list-style: none; margin: 0; padding: 0; }
    .kiosk-inv-item {
        display: flex; align-items: center; justify-content: space-between; gap: 0.8rem;
        padding: 0.55rem 0.15rem; border-top: 1px solid rgba(255, 255, 255, 0.06);
    }
    .kiosk-inv-item:first-child { border-top: none; }
    .kiosk-inv-item.is-paused { opacity: 0.4; }
    .kiosk-inv-name { font-size: 1rem; font-weight: 700; word-break: break-word; }
    .kiosk-inv-input {
        width: 96px; flex-shrink: 0; padding: 0.6rem 0.7rem;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 10px; background: rgba(255, 255, 255, 0.04);
        color: var(--foreground, inherit); font-size: 1.1rem; font-weight: 700; text-align: center;
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Comptage inventaire</h1>
        <p class="muted">Comptage « à l'aveugle » : les stocks théoriques ne sont pas affichés. Seules les lignes renseignées sont comptées.</p>
    </div>
</div>

<section class="card surface glass">
    <form method="post" action="<?= e(url('/kiosque/comptage/inventaire/save/' . rawurlencode($token))) ?>">
        <?= csrf_field() ?>
        <ul class="kiosk-inv-list">
            <?php foreach ($rows as $r): ?>
            <li class="kiosk-inv-item<?= !empty($r['paused']) ? ' is-paused' : '' ?>">
                <span class="kiosk-inv-name"><?= e($r['key']) ?></span>
                <?php if (empty($r['paused'])): ?>
                    <input type="number" class="kiosk-inv-input" name="count[<?= e($r['key']) ?>]"
                           min="0" step="1" placeholder="—" inputmode="numeric"
                           aria-label="Quantité comptée de <?= e($r['key']) ?>">
                <?php else: ?>
                    <span class="muted" style="font-size: 0.8rem;">en pause</span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
            <li class="kiosk-inv-item"><span class="muted">Aucun produit enregistré pour le moment.</span></li>
            <?php endif; ?>
        </ul>
        <div class="form-actions" style="position: sticky; bottom: 0; padding: 0.8rem 0 0.2rem;">
            <button type="submit" class="btn btn-primary">Enregistrer le comptage</button>
        </div>
    </form>
</section>

<p class="shop-footnote">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/comptage/' . rawurlencode($token))) ?>">← Retour au comptage</a>
</p>
