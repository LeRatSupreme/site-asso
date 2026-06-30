<?php

declare(strict_types=1);

/**
 * Mini dashboard SumUp — fondé sur les ventes importées.
 *
 * @var int $year
 * @var int $month
 * @var string $monthLabel
 * @var float $cardTotal
 * @var float $cashTotal
 * @var int $txCount
 * @var float $caTotal
 */

$cardPct = $caTotal > 0 ? round($cardTotal / $caTotal * 100, 1) : 0;
$cashPct = $caTotal > 0 ? round($cashTotal / $caTotal * 100, 1) : 0;
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">SumUp</p>
            <h1 class="page-title">Tableau de bord</h1>
            <p class="muted">Ventes encaissées via SumUp, issues des rapports importés. Pour rafraîchir, importe un nouveau CSV.</p>
        </div>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/compta/import')) ?>">📥 Importer un rapport</a>
    </div>
</div>

<!-- 4 cartes stats -->
<div class="grid grid-4 stat-cards">
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(formatPrice($cardTotal)) ?></span>
        <span class="stat-label">💳 Carte (<?= e($monthLabel) ?>)</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(formatPrice($cashTotal)) ?></span>
        <span class="stat-label">💵 Espèces (<?= e($monthLabel) ?>)</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e((string) $txCount) ?></span>
        <span class="stat-label">Transactions</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value is-positive"><?= e(formatPrice($caTotal)) ?></span>
        <span class="stat-label">Total encaissé</span>
    </div>
</div>

<!-- Répartition carte / espèces -->
<section class="card surface glass sumup-split">
    <h2 class="card-title">Répartition des encaissements</h2>
    <div class="split-bar">
        <div class="split-bar-card" style="width: <?= (float) $cardPct ?>%" title="Carte : <?= e(formatPrice($cardTotal)) ?> (<?= e(number_format($cardPct, 1, ',', ' ')) ?> %)">
            <span class="split-label">💳 <?= e(number_format($cardPct, 1, ',', ' ')) ?> %</span>
        </div>
        <div class="split-bar-cash" style="width: <?= (float) $cashPct ?>%" title="Espèces : <?= e(formatPrice($cashTotal)) ?> (<?= e(number_format($cashPct, 1, ',', ' ')) ?> %)">
            <span class="split-label">💵 <?= e(number_format($cashPct, 1, ',', ' ')) ?> %</span>
        </div>
    </div>
    <div class="split-legend">
        <span class="badge badge-success">💳 Carte — <?= e(formatPrice($cardTotal)) ?></span>
        <span class="badge badge-muted">💵 Espèces — <?= e(formatPrice($cashTotal)) ?></span>
    </div>
</section>

<!-- Liens rapides -->
<div class="grid grid-3">
    <a class="card surface glass card-hover sumup-link" href="<?= e(url('/admin/compta')) ?>">
        <span class="eyebrow">Comptabilité</span>
        <strong>Dashboard</strong>
        <p class="muted">CA, bénéfices, marges par produit et catégorie.</p>
    </a>
    <a class="card surface glass card-hover sumup-link" href="<?= e(url('/admin/compta/ventes')) ?>">
        <span class="eyebrow">Comptabilité</span>
        <strong>Journal des ventes</strong>
        <p class="muted">Toutes les transactions importées, filtrables.</p>
    </a>
    <a class="card surface glass card-hover sumup-link" href="<?= e(url('/admin/compta/reappro')) ?>">
        <span class="eyebrow">Comptabilité</span>
        <strong>Réapprovisionnement</strong>
        <p class="muted">Stocks, consommations et quantités à racheter.</p>
    </a>
</div>

<p class="card-meta">
    Les encaissements SumUp proviennent des rapports CSV importés manuellement. Aucun appel API temps réel — importe un nouveau rapport pour actualiser.
</p>
