<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 * @var array{ca:float,profit:float,qty:int,ca_products:float} $agg
 * @var array<string,float> $split
 * @var list<array<string,mixed>> $top
 * @var list<array<string,mixed>> $byCategory
 * @var int $reorderAlerts
 * @var float $margin
 * @var float $expenseTtc
 * @var float $netResult
 * @var float $noCostCa
 * @var float $vatTotal
 * @var list<array<string,mixed>> $lossLeaders
 * @var list<array<string,mixed>> $invGaps
 * @var ?string $lastImport
 * @var ?int $daysSinceImport
 */

// Part du CA produits sans coût de revient connu (fiabilité du bénéfice).
$noCostPct = $agg['ca_products'] > 0 ? $noCostCa / $agg['ca_products'] * 100 : 0;

// Panneau « Alertes & suivi » : chaque entrée est un niveau + un HTML sûr
// (les parties dynamiques passent par e(), les liens par url()).
$alerts = [];

if ($daysSinceImport === null) {
    $alerts[] = ['level' => 'warning', 'html' => 'Aucun import SumUp pour l\'instant.'];
} elseif ($daysSinceImport >= 14) {
    $alerts[] = [
        'level' => 'danger',
        'html'  => sprintf(
            'Dernier import SumUp il y a %d jours (%s)',
            $daysSinceImport,
            e(formatDate($lastImport))
        ),
    ];
} else {
    $alerts[] = ['level' => 'ok', 'html' => 'Dernier import SumUp : ' . e(formatDateTime($lastImport))];
}

foreach (array_slice($lossLeaders, 0, 5) as $l) {
    $alerts[] = [
        'level' => 'danger',
        'html'  => sprintf(
            '%s : vendu %.2f € contre un coût de %.2f € %s',
            e((string) $l['product']),
            (float) $l['avg_price'],
            (float) $l['cost'],
            '<a href="' . e(url('/admin/compta/produits')) . '">voir les produits →</a>'
        ),
    ];
}

if ($invGaps !== []) {
    // Lien inventaire réservé à ADMIN (TRESORERIE reçoit 403 sur la page).
    $invLink = \App\Core\Auth::isAdmin()
        ? ' <a href="' . e(url('/admin/compta/inventaire')) . '">voir l\'inventaire →</a>'
        : '';
    $alerts[] = [
        'level' => 'warning',
        'html'  => sprintf(
            '%d écart(s) d\'inventaire sur 30 j (pertes/casses ?)%s',
            count($invGaps),
            $invLink
        ),
    ];
}

if ($reorderAlerts > 0) {
    $alerts[] = [
        'level' => 'warning',
        'html'  => sprintf(
            '%d produit(s) à racheter %s',
            $reorderAlerts,
            '<a href="' . e(url('/admin/compta/reappro')) . '">voir le réappro →</a>'
        ),
    ];
}
?>
<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/compta/import')) ?>">Importer un CSV</a>
</div>

<div class="admin-actions">
    <?php require AEIC_VIEWS . '/admin/compta/_period_bar.php'; ?>
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
        <p class="kpi-label">Résultat net</p>
        <p class="kpi-value <?= $netResult >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($netResult)) ?></p>
        <p class="kpi-sub">bénéfice − dépenses (<?= e(formatPrice($expenseTtc)) ?>)</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">CA sans coût</p>
        <p class="kpi-value <?= $noCostPct >= 20 ? 'is-negative' : '' ?>"><?= e(number_format($noCostPct, 1, ',', ' ')) ?> %</p>
        <p class="kpi-sub">fiabilité du bénéfice — <a href="<?= e(url('/admin/compta/couts')) ?>">compléter →</a></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Alertes réappro</p>
        <p class="kpi-value <?= $reorderAlerts > 0 ? 'is-negative' : 'is-positive' ?>"><?= e((string) $reorderAlerts) ?></p>
        <p class="kpi-sub"><a href="<?= e(url('/admin/compta/reappro')) ?>">Voir le réappro →</a></p>
    </div>
</div>

<?php if ($alerts !== []): ?>
<div class="card surface glass alert-card">
    <h2 class="card-title">🔔 Alertes &amp; suivi</h2>
    <ul class="alert-list">
        <?php foreach ($alerts as $a): ?>
            <li class="alert-item alert-<?= e($a['level']) ?>"><?= $a['html'] ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php else: ?>
<div class="card surface glass alert-card">
    <p class="muted">✅ Tout est à jour</p>
</div>
<?php endif; ?>

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
        <p class="muted">TVA collectée sur la période : <strong><?= e(formatPrice($vatTotal)) ?></strong></p>
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

<div class="card surface glass">
    <h2 class="card-title">Suivi avancé</h2>
    <p>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/depenses')) ?>">Dépenses</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/budgets')) ?>">Budgets</a>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/achats')) ?>">Achats &amp; stock</a>
        <?php if (\App\Core\Auth::isAdmin()): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/inventaire')) ?>">Inventaire</a>
        <?php endif; ?>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/annuel')) ?>">Rapport annuel</a>
    </p>
</div>

