<?php

declare(strict_types=1);

use App\Core\Compta\CashLedger;

/**
 * Kiosque ADMIN — dépôt à la banque : version téléphone de la carte
 * « Dépôt à la banque » de la page Caisses (Système → Caisses). Mêmes
 * écritures serveur (caisseDepotSave) : mouvement DEPOT (sortie de
 * liquide) + dépense automatique de frais bancaires. L'identité
 * (pastille profil du layout kiosk) est exigée à l'enregistrement.
 *
 * @var string $token
 */
?>
<style>
    .kiosk-depot-form { max-width: 480px; }
    .kiosk-depot-form .field { margin-bottom: 1rem; }
    .kiosk-depot-form input[type="text"] {
        width: 100%; padding: 0.8rem 0.9rem;
        border: 1px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 12px; background: rgba(255, 255, 255, 0.04);
        color: var(--foreground, inherit); font-size: 1.15rem; font-weight: 700;
    }
</style>

<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité — accès admin</p>
        <h1 class="page-title">Dépôt à la banque</h1>
        <p class="muted">Sortie de liquide de la caisse vers le compte de l'association.</p>
    </div>
</div>

<section class="card surface glass">
    <h2 class="card-title">Dépôt à la banque</h2>
    <p class="card-meta">
        Frais bancaires fixes : <strong>−<?= e(formatPrice(CashLedger::DEPOSIT_FEE)) ?> par dépôt</strong>
        (net crédité en banque ≈ montant − <?= e(formatPrice(CashLedger::DEPOSIT_FEE)) ?>).
        Ils sont ajoutés automatiquement aux dépenses, catégorie « Frais bancaires ».
    </p>
    <form method="post" action="<?= e(url('/kiosque/admin/caisse/depot/' . rawurlencode($token))) ?>" class="kiosk-depot-form">
        <div class="field">
            <label for="depot-amount">Montant déposé (€)</label>
            <input type="text" id="depot-amount" name="amount" inputmode="decimal" placeholder="ex: 100" required>
        </div>
        <?php datetime_selects_field('date', 'depot-date'); ?>
        <div class="field">
            <label for="depot-label">Note <span class="muted">(n° de bordereau, banque…)</span></label>
            <input type="text" id="depot-label" name="label" placeholder="ex: bordereau 4512 — La Banque Postale">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Enregistrer le dépôt</button>
        </div>
    </form>
</section>

<p class="shop-footnote" style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/kiosque/admin/' . rawurlencode($token))) ?>">← Retour au kiosque admin</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/kiosque/comptage/caisse/' . rawurlencode($token))) ?>">Comptage caisse</a>
</p>
