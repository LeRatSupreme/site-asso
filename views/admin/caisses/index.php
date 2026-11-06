<?php

declare(strict_types=1);

use App\Core\Compta\CashLedger;
use App\Models\CashMovement;

/**
 * Caisses — traçabilité du liquide (groupe Système).
 *
 * @var float $balance    Solde théorique actuel.
 * @var float $salesTotal Total des ventes en liquide.
 * @var float $cardTotal  Total des ventes par carte (hors caisse).
 * @var float $cardFee    Frais SumUp estimés sur les ventes carte.
 * @var float $cardNet    Net estimé des ventes carte après frais.
 * @var string $feeRate   Taux de commission formaté (ex. « 1,75 »).
 * @var float $homeTotal  Liquide « pris chez soi » (prises − retours).
 * @var list<array<string,mixed>> $movements Mouvements manuels récents.
 * @var list<array<string,mixed>> $counts    Comptages récents.
 * @var list<array<string,mixed>> $ecarts    Comptages avec écart (30 j).
 */

$typeLabels = [
    CashMovement::TYPE_FOND       => 'Fond',
    CashMovement::TYPE_DEPOT      => 'Dépôt banque',
    CashMovement::TYPE_PRISE      => 'Prise chez soi',
    CashMovement::TYPE_AJUSTEMENT => 'Ajustement',
];
?>
<style>
    /* Bascule de sens de la carte « Prise chez soi » : deux options
       radios stylées en segments (aucun impact hors de cette carte). */
    .prise-sens { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; }
    .prise-sens-opt { display: block; cursor: pointer; }
    .prise-sens-opt input { position: absolute; opacity: 0; pointer-events: none; }
    .prise-sens-opt span {
        display: flex; align-items: center; justify-content: center;
        min-height: 44px; padding: 0.5rem 0.6rem;
        border: 2px solid var(--border, rgba(255,255,255,0.15));
        border-radius: 12px; background: rgba(255, 255, 255, 0.04);
        font-size: 0.95rem; font-weight: 800; text-align: center;
    }
    .prise-sens-opt:has(input:checked) span {
        border-color: var(--primary, #48bdd3); background: rgba(72, 189, 211, 0.12);
    }
    .prise-sens-opt input:focus-visible + span { outline: 2px solid var(--primary, #48bdd3); outline-offset: 2px; }
</style>

<div class="stat-cards grid grid-2">
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(formatPrice($balance)) ?></span>
        <span class="stat-label">Caisse théorique</span>
        <span class="card-meta">dont <?= e(formatPrice($salesTotal)) ?> de ventes en liquide</span>
        <span class="card-meta">dont <?= e(formatPrice($homeTotal)) ?> pris « chez soi » (déduits du tiroir, ramenés déduits — visibles dans le livre)</span>
        <span class="card-meta">+ <?= e(formatPrice($cardTotal)) ?> de ventes par carte (hors caisse)</span>
        <span class="card-meta">frais SumUp estimés ≈ −<?= e(formatPrice($cardFee)) ?> (<?= e($feeRate) ?> %) · net ≈ <?= e(formatPrice($cardNet)) ?></span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value <?= $ecarts !== [] ? 'is-negative' : 'is-positive' ?>"><?= e((string) count($ecarts)) ?></span>
        <span class="stat-label">Écarts détectés (30 derniers jours)</span>
        <span class="card-meta">négatif = manquant (vol potentiel)</span>
    </div>
</div>

<section class="card surface glass">
    <h2 class="card-title">Écarts détectés (30 derniers jours)</h2>
    <?php if ($ecarts === []): ?>
        <p class="card-meta">Aucun écart : chaque comptage a collé au théorique.</p>
    <?php else: ?>
        <ul class="list-rows">
            <?php foreach ($ecarts as $c): ?>
                <li>
                    <code><?= e(formatPrice((float) $c['ecart'])) ?></code>
                    <span class="card-meta">compté <?= e(formatPrice((float) $c['counted_amount'])) ?>
                        / théorique <?= e(formatPrice((float) $c['theoretical_amount'])) ?></span>
                    <span class="card-meta"><?= e(formatDateTime((string) $c['created_at'])) ?></span>
                    <?php if (($c['label'] ?? '') !== ''): ?><span class="card-meta"><?= e((string) $c['label']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<div class="grid grid-2">
    <section class="card surface glass">
        <h2 class="card-title">Comptage physique</h2>
        <p class="card-meta">Comptez le liquide présent : l'écart est historisé et la caisse est réalignée sur le compté.</p>
        <form method="post" action="<?= e(url('/admin/caisses/comptage')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="counted">Montant compté (€)</label>
                <input type="text" id="counted" name="counted" inputmode="decimal" placeholder="ex: 152,30" required>
            </div>
            <div class="field">
                <label for="count-label">Note <span class="muted">(optionnel)</span></label>
                <input type="text" id="count-label" name="label" placeholder="ex: comptage soirée">
            </div>
            <?php datetime_selects_field('date', 'count-date'); ?>
            <button type="submit" class="btn btn-primary btn-sm">Enregistrer le comptage</button>
        </form>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Dépôt à la banque</h2>
        <p class="card-meta">
            Frais bancaires fixes : <strong>−<?= e(formatPrice(CashLedger::DEPOSIT_FEE)) ?> par dépôt</strong>
            (net crédité en banque ≈ montant − <?= e(formatPrice(CashLedger::DEPOSIT_FEE)) ?>).
            Ils sont ajoutés automatiquement aux dépenses, catégorie « Frais bancaires ».
        </p>
        <form method="post" action="<?= e(url('/admin/caisses/depot')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="depot-amount">Montant déposé (€)</label>
                <input type="text" id="depot-amount" name="amount" inputmode="decimal" placeholder="ex: 100" required>
            </div>
            <div class="field">
                <label>Détail par coupures <span class="muted">(optionnel — pour la traçabilité du liquide)</span></label>
                <?php
                $ccId = 'ad';
                $ccMode = 'check';
                $ccAmountInput = 'depot-amount';
                $ccSubmitBtn = 'admin-depot-submit';
                $ccBig = false;
                require AEIC_VIEWS . '/admin/compta/_cash_coupures.php';
                ?>
            </div>
            <?php datetime_selects_field('date', 'depot-date'); ?>
            <div class="field">
                <label for="depot-label">Note <span class="muted">(n° de bordereau, banque…)</span></label>
                <input type="text" id="depot-label" name="label" placeholder="ex: bordereau 4512 — La Banque Postale">
            </div>
            <button type="submit" class="btn btn-outline btn-sm" id="admin-depot-submit">Enregistrer le dépôt</button>
        </form>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Prise chez soi</h2>
        <p class="card-meta">Transfert caisse ↔ chez soi, détaillé par coupures : le montant est déduit du détail (jamais saisi à la main). « Ramené » remet l'argent en caisse.</p>
        <form method="post" action="<?= e(url('/admin/caisses/prise')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label>Sens</label>
                <div class="prise-sens" role="radiogroup" aria-label="Sens de la prise">
                    <label class="prise-sens-opt"><input type="radio" name="sens" value="prise" checked><span>🏠 Pris chez soi</span></label>
                    <label class="prise-sens-opt"><input type="radio" name="sens" value="retour"><span>↩️ Ramené</span></label>
                </div>
            </div>
            <div class="field">
                <label>Coupures <span class="muted">(obligatoires)</span></label>
                <?php
                $ccId = 'ap';
                $ccMode = 'amount';
                $ccAmountInput = null;
                $ccSubmitBtn = 'admin-prise-submit';
                $ccBig = false;
                require AEIC_VIEWS . '/admin/compta/_cash_coupures.php';
                ?>
            </div>
            <?php datetime_selects_field('date', 'prise-date'); ?>
            <div class="field">
                <label for="prise-label">Note <span class="muted">(optionnel)</span></label>
                <input type="text" id="prise-label" name="label" placeholder="ex: prise fin de soirée">
            </div>
            <button type="submit" class="btn btn-primary btn-sm" id="admin-prise-submit" disabled>Enregistrer la prise</button>
        </form>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Fond de caisse</h2>
        <p class="card-meta">Entrée de liquide hors ventes (caisse initiale, argent ramené de la banque).</p>
        <form method="post" action="<?= e(url('/admin/caisses/fond')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="fond-amount">Montant (€)</label>
                <input type="text" id="fond-amount" name="amount" inputmode="decimal" placeholder="ex: 50" required>
            </div>
            <div class="field">
                <label for="fond-label">Note <span class="muted">(optionnel)</span></label>
                <input type="text" id="fond-label" name="label" placeholder="ex: fond de caisse semaine">
            </div>
            <?php datetime_selects_field('date', 'fond-date'); ?>
            <button type="submit" class="btn btn-outline btn-sm">Enregistrer le fond</button>
        </form>
    </section>
</div>

<section class="card surface glass">
    <h2 class="card-title">Historique des mouvements</h2>
    <?php if ($movements === []): ?>
        <p class="card-meta">Aucun mouvement : enregistrez un fond de caisse pour commencer.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th>Type</th><th>Montant</th><th>Note</th><th>Par</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <?php
                    $amount = (float) $m['amount'];
                    // PRISE = transfert signé : le libellé suit le sens
                    // (négatif = argent pris, positif = ramené).
                    $typeLabel = (string) ($typeLabels[$m['type']] ?? $m['type']);
                    if ($m['type'] === CashMovement::TYPE_PRISE) {
                        $typeLabel = $amount < 0 ? 'Prise chez soi' : 'Retour de chez soi';
                    }
                    ?>
                    <tr>
                        <td><?= e(formatDateTime((string) $m['created_at'])) ?></td>
                        <td><?= e($typeLabel) ?></td>
                        <td class="<?= $amount < 0 ? 'is-negative' : 'is-positive' ?>"><?= $amount > 0 ? '+' : '' ?><?= e(formatPrice($amount)) ?></td>
                        <td><?= e((string) ($m['label'] ?? '')) ?></td>
                        <td><?= e((string) ($m['created_by'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card surface glass">
    <h2 class="card-title">Historique des comptages</h2>
    <?php if ($counts === []): ?>
        <p class="card-meta">Aucun comptage enregistré.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th>Compté</th><th>Théorique</th><th>Écart</th><th>Note</th><th>Par</th></tr></thead>
                <tbody>
                <?php foreach ($counts as $c): ?>
                    <?php $ecart = (float) $c['ecart']; ?>
                    <tr>
                        <td><?= e(formatDateTime((string) $c['created_at'])) ?></td>
                        <td><?= e(formatPrice((float) $c['counted_amount'])) ?></td>
                        <td><?= e(formatPrice((float) $c['theoretical_amount'])) ?></td>
                        <td class="<?= $ecart < 0 ? 'is-negative' : ($ecart > 0 ? '' : 'is-positive') ?>">
                            <?= $ecart > 0 ? '+' : '' ?><?= e(formatPrice($ecart)) ?>
                        </td>
                        <td><?= e((string) ($c['label'] ?? '')) ?></td>
                        <td><?= e((string) ($c['created_by'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
