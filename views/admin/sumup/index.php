<?php

declare(strict_types=1);

/**
 * Mini dashboard SumUp — fondé sur les ventes importées (rapports SumUp).
 *
 * @var int $year
 * @var int $month
 * @var string $monthLabel
 * @var float $cardTotal
 * @var float $cashTotal
 * @var int $txCount
 * @var float $caTotal
 */
?>
<p class="muted">
    Ce tableau de bord reprend les <strong>ventes déjà importées</strong> dans la
    comptabilité (rapports SumUp). Aucun appel à l'API SumUp n'est effectué :
    importez un nouveau rapport dans
    <a href="<?= e(url('/admin/compta/import')) ?>">Importer CSV</a> pour rafraîchir les chiffres.
</p>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Encaissé par carte (<?= e($monthLabel) ?>)</p>
        <p class="kpi-value"><?= e(formatPrice($cardTotal)) ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Encaissé en espèces (<?= e($monthLabel) ?>)</p>
        <p class="kpi-value"><?= e(formatPrice($cashTotal)) ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Transactions (lignes)</p>
        <p class="kpi-value"><?= e((string) $txCount) ?></p>
        <p class="kpi-sub">Total encaissé : <?= e(formatPrice($caTotal)) ?></p>
    </div>
</div>

<div class="card surface glass">
    <h2 class="card-title">Détail comptable</h2>
    <p class="muted">
        Les paiements par carte correspondent aux encaissements SumUp. Pour le
        journal complet, les coûts et le bénéfice par produit, consultez la
        comptabilité.
    </p>
    <p>
        <a class="btn btn-primary" href="<?= e(url('/admin/compta')) ?>">Aller à la comptabilité →</a>
    </p>
</div>
