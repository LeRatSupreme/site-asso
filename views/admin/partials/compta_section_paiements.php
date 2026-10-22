<?php

declare(strict_types=1);

/**
 * Section « Carte et liquide » (niveau 2, <details> fermé) — partiel
 * partagé entre le dashboard compta (/admin/compta) et l'accueil admin
 * (/admin, variante compta). Même payload Dashboard\Data dans les deux
 * pages : aucune donnée ni calcul métier propre à cette vue.
 *
 * Attend en scope : $split, $txCount, $cardFee, $cardNet, $feeRate,
 * $vat, $vatTotal — et, depuis le lot 4B (calculs remontés dans
 * Dashboard\Data::construire()) : $splitTotal (CARTE + LIQUIDE) et
 * $cardPct (part de la carte en %, arrondie).
 *
 * @var array<string,float> $split
 * @var int $txCount
 * @var float $cardFee
 * @var float $cardNet
 * @var string $feeRate
 * @var list<array<string,mixed>> $vat
 * @var float $vatTotal
 * @var float $splitTotal
 * @var int $cardPct
 */

// Lignes de TVA non nulles (les lignes à 0 € sont masquées, niveau 2).
$vatLines = array_values(array_filter(
    $vat,
    static fn(array $v): bool => abs((float) ($v['vat'] ?? 0)) > 0.005
));
?>
<details class="card surface glass">
    <summary>💳 Carte et liquide</summary>
    <ul class="split-list">
        <li>
            <span>Carte</span>
            <strong><?= e(formatPrice($split['CARTE'] ?? 0)) ?></strong>
        </li>
        <li>
            <span>Liquide</span>
            <strong><?= e(formatPrice($split['LIQUIDE'] ?? 0)) ?></strong>
        </li>
    </ul>
    <div class="split-bar" title="Carte vs Liquide">
        <div class="split-bar-card" style="width:<?= e((string) $cardPct) ?>%"></div>
    </div>
    <p class="muted">
        Total : <strong><?= e(formatPrice($splitTotal)) ?></strong> · carte : <strong><?= e((string) $cardPct) ?> %</strong> des encaissements
    </p>
    <p class="muted"><?= e((string) $txCount) ?> transaction<?= $txCount > 1 ? 's' : '' ?> sur la période</p>
    <?php if (($split['CARTE'] ?? 0) > 0): ?>
        <p class="muted">
            <span title="Commission estimée de SumUp sur les paiements par carte : taux × ventes carte. Estimation, jamais prélevée ici.">
                Frais SumUp estimés ≈ <strong>−<?= e(formatPrice($cardFee)) ?></strong>
                (<?= e($feeRate) ?> %) · net carte ≈ <strong><?= e(formatPrice($cardNet)) ?></strong> <span class="aide-mark">?</span>
            </span>
        </p>
    <?php endif; ?>
    <p class="muted">
        <span title="TVA collectée sur les ventes de la période, ventilée par taux (20 %, 10 %, 5,5 %…) — à reverser à l'État.">
            TVA collectée sur la période : <strong><?= e(formatPrice($vatTotal)) ?></strong> <span class="aide-mark">?</span>
        </span>
    </p>
    <?php if ($vatLines !== []): ?>
        <table class="table">
            <thead><tr><th>Taux</th><th>HT</th><th>TVA</th><th>TTC</th></tr></thead>
            <tbody>
                <?php foreach ($vatLines as $v): ?>
                    <tr>
                        <td><?= e((string) $v['rate']) ?> %</td>
                        <td><?= e(formatPrice($v['ht'])) ?></td>
                        <td><?= e(formatPrice($v['vat'])) ?></td>
                        <td><?= e(formatPrice($v['ttc'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="muted">Pas de TVA collectée sur la période.</p>
    <?php endif; ?>
</details>
