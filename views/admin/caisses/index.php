<?php

declare(strict_types=1);

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
 * @var list<array<string,mixed>> $movements Mouvements manuels récents.
 * @var list<array<string,mixed>> $counts    Comptages récents.
 * @var list<array<string,mixed>> $ecarts    Comptages avec écart (30 j).
 */

$typeLabels = [
    CashMovement::TYPE_FOND       => '🏦 Fond',
    CashMovement::TYPE_DEPOT      => '🏛️ Dépôt banque',
    CashMovement::TYPE_AJUSTEMENT => '⚖️ Ajustement',
];
?>

<div class="stat-cards grid grid-2">
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(formatPrice($balance)) ?></span>
        <span class="stat-label">Caisse théorique</span>
        <span class="card-meta">dont <?= e(formatPrice($salesTotal)) ?> de ventes en liquide</span>
        <span class="card-meta">+ <?= e(formatPrice($cardTotal)) ?> de ventes par carte (hors caisse)</span>
        <span class="card-meta">frais SumUp estimés ≈ −<?= e(formatPrice($cardFee)) ?> (<?= e($feeRate) ?> %) · net ≈ <?= e(formatPrice($cardNet)) ?></span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value <?= $ecarts !== [] ? 'is-negative' : 'is-positive' ?>"><?= e((string) count($ecarts)) ?></span>
        <span class="stat-label">⚠️ Écarts détectés (30 derniers jours)</span>
        <span class="card-meta">négatif = manquant (vol potentiel)</span>
    </div>
</div>

<section class="card surface glass">
    <h2 class="card-title">⚠️ Écarts détectés (30 derniers jours)</h2>
    <?php if ($ecarts === []): ?>
        <p class="card-meta">Aucun écart : chaque comptage a collé au théorique. 👍</p>
    <?php else: ?>
        <ul class="list-rows">
            <?php foreach ($ecarts as $c): ?>
                <li>
                    <code><?= $c['ecart'] < 0 ? '🔴' : '🟠' ?> <?= e(formatPrice((float) $c['ecart'])) ?></code>
                    <span class="card-meta">compté <?= e(formatPrice((float) $c['counted_amount'])) ?>
                        / théorique <?= e(formatPrice((float) $c['theoretical_amount'])) ?></span>
                    <span class="card-meta"><?= e(formatDateTime((string) $c['created_at'])) ?></span>
                    <?php if (($c['label'] ?? '') !== ''): ?><span class="card-meta"><?= e((string) $c['label']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<div class="grid grid-3">
    <section class="card surface glass">
        <h2 class="card-title">🧾 Comptage physique</h2>
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
        <h2 class="card-title">🏛️ Dépôt à la banque</h2>
        <form method="post" action="<?= e(url('/admin/caisses/depot')) ?>">
            <?= csrf_field() ?>
            <div class="field">
                <label for="depot-amount">Montant déposé (€)</label>
                <input type="text" id="depot-amount" name="amount" inputmode="decimal" placeholder="ex: 100" required>
            </div>
            <?php datetime_selects_field('date', 'depot-date'); ?>
            <div class="field">
                <label for="depot-label">Note <span class="muted">(n° de bordereau, banque…)</span></label>
                <input type="text" id="depot-label" name="label" placeholder="ex: bordereau 4512 — La Banque Postale">
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Enregistrer le dépôt</button>
        </form>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">🏦 Fond de caisse</h2>
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
                    <?php $amount = (float) $m['amount']; ?>
                    <tr>
                        <td><?= e(formatDateTime((string) $m['created_at'])) ?></td>
                        <td><?= e($typeLabels[$m['type']] ?? (string) $m['type']) ?></td>
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
