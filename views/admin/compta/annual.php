<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var int $year
 * @var list<int> $years
 * @var list<array{m:int,ca:float,profit:float,margin:?float,expenses:float,net:float,caPrev:float,delta:?float}> $rows
 * @var array{ca:float,profit:float,expenses:float,net:float,caPrev:float} $totals
 * @var list<array<string,mixed>> $vat
 * @var array{baskets:int,avg_basket:float,avg_items:float} $baskets
 */
?>
<?php
$vatTotal = 0.0;
foreach ($vat as $v) {
    $vatTotal += (float) $v['vat'];
}
$caDiff = $totals['ca'] - $totals['caPrev'];
$totalMargin = $totals['ca'] > 0 ? round($totals['profit'] / $totals['ca'] * 100, 1) : null;
$totalDelta = $totals['caPrev'] > 0 ? round(($totals['ca'] - $totals['caPrev']) / $totals['caPrev'] * 100, 1) : null;
?>

<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Comptabilité</p>
            <h1 class="page-title"><?= e(sprintf('Rapport annuel %d', $year)) ?></h1>
            <p class="muted">Vue 12 mois : CA, bénéfice, dépenses et <strong>résultat net</strong>, avec comparaison N-1. Le must du suivi trésorerie.</p>
        </div>
    </div>
</div>

<div class="admin-actions">
    <form method="get" class="compta-monthselect">
        <label for="year">Année :</label>
        <select id="year" name="year" onchange="this.form.submit()">
            <?php foreach ($years as $y): ?>
                <option value="<?= e((string) $y) ?>" <?= $y === $year ? 'selected' : '' ?>><?= e((string) $y) ?></option>
            <?php endforeach; ?>
            <?php if ($years === []): ?>
                <option value="<?= e((string) (int) date('Y')) ?>" selected><?= e((string) (int) date('Y')) ?></option>
            <?php endif; ?>
        </select>
    </form>
    <a class="btn btn-outline" href="<?= e(url('/admin/compta/annuel?year=' . $year . '&export=csv')) ?>">📄 Exporter CSV</a>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">CA TTC</p>
        <p class="kpi-value"><?= e(formatPrice($totals['ca'])) ?></p>
        <p class="kpi-sub">vs N-1 : <?= $caDiff >= 0 ? '+' : '−' ?><?= e(formatPrice(abs($caDiff))) ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Résultat net</p>
        <p class="kpi-value <?= $totals['net'] >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($totals['net'])) ?></p>
        <p class="kpi-sub">bénéfice − dépenses</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Panier moyen</p>
        <p class="kpi-value"><?= e(formatPrice($baskets['avg_basket'])) ?></p>
        <p class="kpi-sub"><?= e(sprintf('%d transactions', $baskets['baskets'])) ?> — <?= e(number_format($baskets['avg_items'], 1, ',', ' ')) ?> articles/panier</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">TVA collectée</p>
        <p class="kpi-value"><?= e(formatPrice($vatTotal)) ?></p>
        <p class="kpi-sub"><?= e(sprintf('%d taux', count($vat))) ?></p>
    </div>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Année en 12 mois</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Mois</th>
                <th>CA TTC</th>
                <th>Bénéfice</th>
                <th>Marge</th>
                <th>Dépenses</th>
                <th>Résultat net</th>
                <th>CA N-1</th>
                <th>Évolution</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e(sprintf('%02d/%04d', $r['m'], $year)) ?></strong></td>
                    <td><?= e(formatPrice($r['ca'])) ?></td>
                    <td><?= e(formatPrice($r['profit'])) ?></td>
                    <td><?= $r['margin'] === null ? '<span class="muted">—</span>' : e(number_format($r['margin'], 1, ',', ' ')) . ' %' ?></td>
                    <td><?= e(formatPrice($r['expenses'])) ?></td>
                    <td><strong class="<?= $r['net'] >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($r['net'])) ?></strong></td>
                    <td class="muted"><?= e(formatPrice($r['caPrev'])) ?></td>
                    <td>
                        <?php if ($r['delta'] === null): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <strong class="<?= $r['delta'] >= 0 ? 'is-positive' : 'is-negative' ?>"><?= $r['delta'] >= 0 ? '▲' : '▼' ?></strong>
                            <?= e(number_format($r['delta'], 1, ',', ' ')) ?> %
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th>TOTAL</th>
                <th><?= e(formatPrice($totals['ca'])) ?></th>
                <th><?= e(formatPrice($totals['profit'])) ?></th>
                <th><?= $totalMargin === null ? '—' : e(number_format($totalMargin, 1, ',', ' ')) . ' %' ?></th>
                <th><?= e(formatPrice($totals['expenses'])) ?></th>
                <th class="<?= $totals['net'] >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($totals['net'])) ?></th>
                <th><?= e(formatPrice($totals['caPrev'])) ?></th>
                <th>
                    <?php if ($totalDelta === null): ?>
                        —
                    <?php else: ?>
                        <strong class="<?= $totalDelta >= 0 ? 'is-positive' : 'is-negative' ?>"><?= $totalDelta >= 0 ? '▲' : '▼' ?></strong>
                        <?= e(number_format($totalDelta, 1, ',', ' ')) ?> %
                    <?php endif; ?>
                </th>
            </tr>
        </tfoot>
    </table>
</div>

<div class="compta-grid">
    <div class="card surface glass">
        <h2 class="card-title">TVA collectée par taux</h2>
        <?php if ($vat === []): ?>
            <div class="empty-state">
                <p class="muted">Aucune donnée TVA (price_ht absent des imports).</p>
            </div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Taux</th>
                        <th>Base HT</th>
                        <th>TVA</th>
                        <th>Total TTC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vat as $v): ?>
                        <tr>
                            <td><span class="badge"><?= e((string) $v['rate']) ?></span></td>
                            <td><?= e(formatPrice($v['ht'])) ?></td>
                            <td><?= e(formatPrice($v['vat'])) ?></td>
                            <td><?= e(formatPrice($v['ttc'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card surface glass">
        <h2 class="card-title">Paniers &amp; transactions</h2>
        <ul class="split-list">
            <li>
                <span>Transactions</span>
                <strong><?= e((string) $baskets['baskets']) ?></strong>
            </li>
            <li>
                <span>Panier moyen</span>
                <strong><?= e(formatPrice($baskets['avg_basket'])) ?></strong>
            </li>
            <li>
                <span>Articles par panier</span>
                <strong><?= e(number_format($baskets['avg_items'], 1, ',', ' ')) ?></strong>
            </li>
        </ul>
    </div>
</div>
