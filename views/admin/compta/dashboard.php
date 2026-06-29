<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var array{year:int,month:int,value:string} $month
 * @var list<array{value:string,label:string}> $months
 * @var array{ca:float,profit:float,qty:int,ca_products:float} $agg
 * @var array<string,float> $split
 * @var list<array<string,mixed>> $top
 * @var list<array<string,mixed>> $byCategory
 * @var int $reorderAlerts
 * @var float $margin
 */
?>
<div class="admin-actions">
    <form method="get" class="compta-monthselect">
        <label for="month">Mois :</label>
        <select id="month" name="month" onchange="this.form.submit()">
            <?php foreach ($months as $m): ?>
                <option value="<?= e($m['value']) ?>" <?= $m['value'] === $month['value'] ? 'selected' : '' ?>><?= e($m['label']) ?></option>
            <?php endforeach; ?>
            <?php if ($months === []): ?>
                <option value="<?= e($month['value']) ?>" selected><?= e($month['value']) ?></option>
            <?php endif; ?>
        </select>
    </form>
    <a class="btn btn-primary" href="<?= e(url('/admin/compta/import')) ?>">Importer un CSV</a>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">CA total</p>
        <p class="kpi-value"><?= e(formatPrice($agg['ca'])) ?></p>
        <p class="kpi-sub"><?= e(number_format($agg['qty'], 0, ',', ' ')) ?> articles vendus</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">CA produits identifiés</p>
        <p class="kpi-value"><?= e(formatPrice($agg['ca_products'])) ?></p>
        <p class="kpi-sub">hors montants personnalisés</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Bénéfice estimé</p>
        <p class="kpi-value <?= $agg['profit'] >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($agg['profit'])) ?></p>
        <p class="kpi-sub">marge <?= e(number_format($margin, 1, ',', ' ')) ?> %</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Alertes réappro</p>
        <p class="kpi-value <?= $reorderAlerts > 0 ? 'is-negative' : 'is-positive' ?>"><?= e((string) $reorderAlerts) ?></p>
        <p class="kpi-sub"><a href="<?= e(url('/admin/compta/reappro')) ?>">Voir le réappro →</a></p>
    </div>
</div>

<div class="compta-grid">
    <div class="card surface glass">
        <h2 class="card-title">Carte / Liquide</h2>
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
        <?php
        $total = ($split['CARTE'] ?? 0) + ($split['LIQUIDE'] ?? 0);
        $cardPct = $total > 0 ? round(($split['CARTE'] ?? 0) / $total * 100) : 0;
        ?>
        <div class="split-bar" title="Carte vs Liquide">
            <div class="split-bar-card" style="width:<?= e((string) $cardPct) ?>%"></div>
        </div>
    </div>

    <div class="card surface glass">
        <h2 class="card-title">Par catégorie</h2>
        <table class="table">
            <thead><tr><th>Catégorie</th><th>CA</th><th>Bénéfice</th></tr></thead>
            <tbody>
                <?php foreach ($byCategory as $c): ?>
                    <tr>
                        <td><?= e($c['category']) ?></td>
                        <td><?= e(formatPrice($c['ca'])) ?></td>
                        <td><?= e(formatPrice($c['profit'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byCategory === []): ?>
                    <tr><td colspan="3" class="muted">Aucune vente sur cette période.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Top produits</h2>
    <table class="table">
        <thead><tr><th>Produit</th><th>Qté</th><th>CA</th></tr></thead>
        <tbody>
            <?php foreach ($top as $t): ?>
                <tr>
                    <td><strong><?= e($t['label']) ?></strong></td>
                    <td><?= e((string) $t['qty']) ?></td>
                    <td><?= e(formatPrice($t['ca'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($top === []): ?>
                <tr><td colspan="3" class="muted">Aucune vente sur cette période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
