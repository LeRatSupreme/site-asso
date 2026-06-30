<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var list<array{value:string,label:string}> $months
 * @var bool $all
 */
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Comptabilité</p>
            <h1 class="page-title">Bénéfice par catégorie</h1>
            <p class="muted">Vue d'ensemble par grand regroupement (Boisson, Nourriture, Spécial…).</p>
        </div>
        <form method="get" class="compta-monthselect">
            <select name="month" onchange="this.form.submit()">
                <option value="all" <?= $all ? 'selected' : '' ?>>Toute l'année</option>
                <?php foreach ($months as $m): ?>
                    <option value="<?= e($m['value']) ?>" <?= (!$all && $m['value'] === ($_GET['month'] ?? '')) ? 'selected' : '' ?>><?= e($m['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php
    $totQty = 0;
    $totCa = 0.0;
    $totProfit = 0.0;
    foreach ($rows as $r) {
        $totQty    += (int) $r['qty'];
        $totCa     += (float) $r['ca'];
        $totProfit += (float) $r['profit'];
    }
    $totMargin = $totCa > 0 ? round($totProfit / $totCa * 100, 1) : 0.0;
?>

<!-- Total global : 4 cartes séparées -->
<div class="grid grid-4 stat-cards">
    <div class="stat-card surface glass">
        <span class="stat-value"><?= $totQty ?></span>
        <span class="stat-label">Quantité vendue</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(formatPrice($totCa)) ?></span>
        <span class="stat-label">Chiffre d'affaires</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value is-positive"><?= e(formatPrice($totProfit)) ?></span>
        <span class="stat-label">Bénéfice</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(number_format($totMargin, 1, ',', ' ')) ?> %</span>
        <span class="stat-label">Marge globale</span>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="empty-state card surface glass">
        <p class="muted">Aucune vente sur cette période.</p>
    </div>
<?php else: ?>
    <div class="cat-grid">
        <?php foreach ($rows as $r):
            $ca     = (float) $r['ca'];
            $profit = (float) $r['profit'];
            $marg   = $ca > 0 ? round($profit / $ca * 100, 1) : 0.0;
        ?>
            <article class="cat-card card surface glass <?= $profit >= 0 ? 'is-positive' : 'is-negative' ?>">
                <header class="cat-card-head">
                    <h2 class="cat-card-title"><?= e((string) $r['category']) ?></h2>
                    <span class="cat-card-qty muted"><?= (int) $r['qty'] ?> vendus</span>
                </header>

                <div class="cat-card-metrics">
                    <div class="cat-metric">
                        <span class="cat-metric-label">Chiffre d'affaires</span>
                        <strong class="cat-metric-value"><?= e(formatPrice($ca)) ?></strong>
                    </div>
                    <div class="cat-metric">
                        <span class="cat-metric-label">Bénéfice</span>
                        <strong class="cat-metric-value <?= $profit >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($profit)) ?></strong>
                    </div>
                    <div class="cat-metric">
                        <span class="cat-metric-label">Marge</span>
                        <span class="cat-metric-badge <?= $marg >= 50 ? 'badge-success' : ($marg >= 20 ? 'badge-info' : 'badge-warning') ?>"><?= e(number_format($marg, 1, ',', ' ')) ?> %</span>
                    </div>
                </div>

                <div class="cat-card-bar" title="Part du chiffre d'affaires">
                    <div class="cat-card-bar-fill" style="width: <?= $totCa > 0 ? max(2, round($ca / $totCa * 100)) : 0 ?>%"></div>
                </div>
                <p class="cat-card-share muted"><?= $totCa > 0 ? e(number_format($ca / $totCa * 100, 1, ',', ' ')) : '0,0' ?> % du CA total</p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
