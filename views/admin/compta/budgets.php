<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 * @var array{year:int,month:int,value:string} $editMonth
 * @var int $monthsCount
 * @var list<array{0:int,1:int}> $months
 * @var list<array{key:string,label:string,planned:float,realized:float}> $rows
 * @var array{planned:float,realized:float} $totals
 */

// Totaux par nature : le CA (objectif) et les dépenses (enveloppes) ne se
// somment pas dans le même sens. Le bilan pertinent est le RÉSULTAT :
// CA − dépenses, côté prévu comme côté réalisé.
$plannedCa = 0.0;
$plannedExp = 0.0;
$realizedCa = 0.0;
$realizedExp = 0.0;
$hasAnyBudget = false;
foreach ($rows as $r) {
    if ($r['key'] === 'CA') {
        $plannedCa += $r['planned'];
        $realizedCa += $r['realized'];
    } else {
        $plannedExp += $r['planned'];
        $realizedExp += $r['realized'];
    }
    if ($r['planned'] > 0) {
        $hasAnyBudget = true;
    }
}
$resultPlanned = $plannedCa - $plannedExp;
$resultRealized = $realizedCa - $realizedExp;
$resultGap = $resultRealized - $resultPlanned;

// Libellé lisible des mois couverts : « sept. 2026 » ou « 12 mois entiers ».
$monthShort = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
if (count($months) <= 3) {
    $monthsLabel = implode(' · ', array_map(
        static fn(array $ym): string => $monthShort[$ym[1]] . ' ' . $ym[0],
        $months
    ));
} else {
    $monthsLabel = sprintf('%d mois entiers', count($months));
}
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Budgets prévisionnels</h1>
        <p class="muted">Fixe un <strong>objectif de CA</strong> et des <strong>enveloppes de dépenses</strong> par mois, puis compare au réel. Laisse vide ou mets 0 pour supprimer une ligne.</p>
    </div>
</div>

<div class="admin-actions">
    <?php require AEIC_VIEWS . '/admin/compta/_period_bar.php'; ?>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Résultat prévu</p>
        <p class="kpi-value"><?= e(formatPrice($resultPlanned)) ?></p>
        <p class="kpi-sub">CA prévu − dépenses prévues · <?= e($monthsLabel) ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Résultat réalisé</p>
        <p class="kpi-value <?= $resultRealized >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($resultRealized)) ?></p>
        <p class="kpi-sub">CA réalisé − dépenses réalisées · <?= e($monthsLabel) ?></p>
    </div>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Prévu vs Réalisé</h2>
    <p class="muted">
        Mois couverts : <strong><?= e($monthsLabel) ?></strong> — tu édites le budget du mois <strong><?= e(sprintf('%02d/%04d', $editMonth['month'], $editMonth['year'])) ?></strong> (dernier mois de la période).
        Les montants « réalisé » couvrent chaque mois <strong>en entier</strong>, même si la période choisie est plus courte (ex : du 30/09 au 30/09 affiche tout septembre).
    </p>
    <form method="post" action="<?= e(url('/admin/compta/budgets/save')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="month" value="<?= e($editMonth['value']) ?>">
        <table class="table">
            <thead>
                <tr>
                    <th>Catégorie</th>
                    <th>Prévu (€)</th>
                    <th class="th-num">Réalisé</th>
                    <th class="th-num">Écart</th>
                    <th>État</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $isCa = $r['key'] === 'CA';
                        $hasBudget = $r['planned'] > 0;

                        // CA : positif = objectif dépassé. Dépenses : positif = reste disponible.
                        // Sans budget saisi, l'écart n'a pas de sens : on affiche « — ».
                        $gap = $isCa ? $r['realized'] - $r['planned'] : $r['planned'] - $r['realized'];

                        if ($isCa) {
                            $state = !$hasBudget
                                ? '<span class="badge badge-muted">—</span>'
                                : ($r['realized'] >= $r['planned']
                                    ? '<span class="badge badge-success">Objectif dépassé</span>'
                                    : '<span class="badge badge-warning">Sous l\'objectif</span>');
                        } elseif (!$hasBudget) {
                            // Dépense sans enveloppe budgétée : rien n'est
                            // « dépassé » — il n'y a simplement pas de budget.
                            $state = $r['realized'] > 0
                                ? '<span class="badge badge-muted">Hors budget</span>'
                                : '<span class="badge badge-muted">—</span>';
                        } elseif ($r['realized'] <= 0) {
                            // Enveloppe prévue mais rien dépensé pour l'instant.
                            $state = '<span class="badge badge-muted">Non entamé</span>';
                        } else {
                            // Taux de consommation de l'enveloppe (arrondi).
                            $pct = (int) round($r['realized'] / $r['planned'] * 100);
                            $state = $r['realized'] <= $r['planned']
                                ? ($pct >= 85
                                    ? sprintf('<span class="badge badge-warning">Presque épuisé (%d %%)</span>', $pct)
                                    : sprintf('<span class="badge badge-success">Dans le budget (%d %%)</span>', $pct))
                                : sprintf('<span class="badge badge-danger">Dépassé (%d %%)</span>', $pct);
                        }
                    ?>
                    <tr>
                        <td><?= $isCa ? '<strong>' . e($r['label']) . '</strong>' : e($r['label']) ?></td>
                        <td>
                            <input type="text" name="planned[<?= e($r['key']) ?>]"
                                value="<?= $r['planned'] > 0 ? e(number_format($r['planned'], 2, ',', ' ')) : '' ?>"
                                inputmode="decimal" style="width:130px" placeholder="—">
                        </td>
                        <td class="num"><?= e(formatPrice($r['realized'])) ?></td>
                        <td class="num"><?= $hasBudget ? e(($gap > 0 ? '+' : '') . formatPrice($gap)) : '<span class="muted">—</span>' ?></td>
                        <td><?= $state ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Bilan (CA − dépenses)</th>
                    <th><?= e(formatPrice($resultPlanned)) ?></th>
                    <th class="num"><?= e(formatPrice($resultRealized)) ?></th>
                    <th class="num"><?php if ($hasAnyBudget): ?><span class="<?= $resultGap >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(($resultGap > 0 ? '+' : '') . formatPrice($resultGap)) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
        <button type="submit" class="btn btn-primary">Enregistrer le budget</button>
        <p class="muted" style="font-size:0.85rem">Les montants se saisissent à la française (ex : 250,50 ou 10 000). Laisse vide ou mets 0 pour supprimer une ligne. La ligne « Bilan » compare le résultat (CA − dépenses) prévu au réalisé. <strong>Matière (cafétéria)</strong> se remplit automatiquement avec tes achats enregistrés (page Achats &amp; stock) — inutile de les ressaisir en dépenses.</p>
    </form>
</div>

