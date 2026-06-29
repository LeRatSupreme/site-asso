<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var list<array{value:string,label:string}> $months
 * @var bool $all
 */
?>
<div class="admin-actions">
    <form method="get" class="compta-monthselect">
        <select name="month" onchange="this.form.submit()">
            <option value="all" <?= $all ? 'selected' : '' ?>>Toute l'année</option>
            <?php foreach ($months as $m): ?>
                <option value="<?= e($m['value']) ?>" <?= (!$all && $m['value'] === ($_GET['month'] ?? '')) ? 'selected' : '' ?>><?= e($m['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Catégorie</th><th>Qté</th><th>CA</th><th>Bénéfice</th><th>Marge</th></tr></thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $ca = (float) $r['ca']; $profit = (float) $r['profit']; $marg = $ca > 0 ? round($profit / $ca * 100, 1) : 0.0; ?>
                <tr>
                    <td><strong><?= e((string) $r['category']) ?></strong></td>
                    <td><?= e((string) $r['qty']) ?></td>
                    <td><?= e(formatPrice($ca)) ?></td>
                    <td class="<?= $profit >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($profit)) ?></td>
                    <td><?= e(number_format($marg, 1, ',', ' ')) ?> %</td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="5" class="muted">Aucune vente sur cette période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
