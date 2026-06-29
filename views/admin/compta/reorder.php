<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var array<string,array{label:string,days:int}> $periods
 * @var string $currentPeriod
 * @var int $targetDays
 * @var int $alerts
 */

function reorder_qty(float $v): string {
    return number_format($v, ($v >= 10 ? 0 : 1), ',', ' ');
}

$totalToOrder = 0;
foreach ($rows as $r) {
    $totalToOrder += (int) $r['to_order'];
}
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Comptabilité</p>
            <h1 class="page-title">Réapprovisionnement</h1>
            <p class="muted">Quantités à racheter calculées à partir des ventes réelles (moyenne mobile 3 mois). Choisis la période à couvrir : la colonne « À commander » s'ajuste automatiquement.</p>
        </div>
        <form method="get" class="compta-monthselect">
            <label for="period" class="muted" style="display:block;font-size:0.75rem;margin-bottom:0.3rem">Couvrir pour :</label>
            <select name="period" id="period" onchange="this.form.submit()">
                <?php foreach ($periods as $k => $p): ?>
                    <option value="<?= e($k) ?>" <?= $k === $currentPeriod ? 'selected' : '' ?>><?= e($p['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($alerts > 0): ?>
    <div class="alert alert-warning">
        ⚠️ <strong><?= $alerts ?></strong> produit<?= $alerts > 1 ? 's ont' : ' a' ?> un stock faible (autonomie &lt; 7 jours) — à racheter en priorité.
    </div>
<?php endif; ?>

<div class="card surface glass table-wrap">
    <table class="table reorder-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Catégorie</th>
                <th class="th-num">Stock actuel</th>
                <th class="th-num">Conso moy.<br>/ jour</th>
                <th class="th-num">Conso moy.<br>/ semaine</th>
                <th class="th-num">Conso moy.<br>/ mois</th>
                <th class="th-num">Autonomie</th>
                <th class="th-num">Besoin<br>(<?= e($periods[$currentPeriod]['label']) ?>)</th>
                <th class="th-num">À commander</th>
                <th>État</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= !empty($r['is_alert']) ? 'row-alert' : '' ?>">
                    <td><strong><?= e((string) $r['name']) ?></strong></td>
                    <td><?= e((string) $r['category']) ?></td>
                    <td class="num"><?= e((string) $r['stock']) ?></td>
                    <td class="num muted"><?= reorder_qty((float) $r['avg_day']) ?></td>
                    <td class="num muted"><?= reorder_qty((float) $r['avg_week']) ?></td>
                    <td class="num muted"><?= reorder_qty((float) $r['avg_month']) ?></td>
                    <td class="num">
                        <?php if ($r['autonomy'] === null): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <strong><?= e((string) $r['autonomy']) ?></strong> j
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= e((string) $r['need']) ?></td>
                    <td class="num">
                        <?php if ((int) $r['to_order'] > 0): ?>
                            <strong class="is-positive" style="color:var(--primary)"><?= e((string) $r['to_order']) ?></strong>
                        <?php else: ?>
                            <span class="muted">0 (stock OK)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($r['is_alert'])): ?>
                            <span class="badge badge-warning">À racheter</span>
                        <?php else: ?>
                            <span class="badge badge-success">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="10" class="muted">Aucun produit à analyser. Ajoute d'abord des produits en cafétéria et importe un rapport SumUp.</td></tr>
            <?php endif; ?>
        </tbody>
        <?php if ($rows !== []): ?>
            <tfoot>
                <tr>
                    <th colspan="8" style="text-align:right">Total à commander (<?= e($periods[$currentPeriod]['label']) ?>) :</th>
                    <th class="num"><strong style="color:var(--primary)"><?= $totalToOrder ?></strong></th>
                    <th></th>
                </tr>
            </tfoot>
        <?php endif; ?>
    </table>
</div>

<p class="card-meta">
    Conso / jour = moyenne mensuelle ÷ 30 · Conso / semaine = conso / jour × 7 · Moyenne mobile 3 mois (ventes réelles SumUp).
    « À commander » = besoin sur la période − stock restant (minimum 0).
</p>
