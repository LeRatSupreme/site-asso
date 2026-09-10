<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 * @var array{year:int,month:int,value:string} $editMonth
 * @var int $monthsCount
 * @var list<array{key:string,label:string,planned:float,realized:float}> $rows
 * @var array{planned:float,realized:float} $totals
 */

// Écart global = somme des écarts affichés (CA : réalisé − prévu,
// dépenses : prévu − réalisé).
$totalGap = 0.0;
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Budgets prévisionnels</h1>
        <p class="muted">Fixe un <strong>objectif de CA</strong> et des <strong>enveloppes de dépenses</strong> par mois, puis compare au réel. Laisse vide ou mets 0 pour supprimer une ligne.</p>
    </div>
</div>

<div class="admin-actions">
    <form method="get" class="reappro-bar" id="period-filters">
        <div class="reappro-field">
            <label class="field-label" for="period">📅 Période</label>
            <select name="period" id="period">
                <?php foreach ($periodOptions as $k => $label): ?>
                    <option value="<?= e($k) ?>" <?= $k === $period['preset'] ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="reappro-dates" id="period-custom" <?= $period['preset'] === 'custom' ? '' : 'hidden' ?>>
            <div>
                <label class="field-label" for="from">Du</label>
                <input type="date" name="from" id="from" value="<?= e($period['from'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="to">Au</label>
                <input type="date" name="to" id="to" value="<?= e($period['to'] ?? '') ?>">
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Appliquer</button>
    </form>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Prévu (période)</p>
        <p class="kpi-value"><?= e(formatPrice($totals['planned'])) ?></p>
        <p class="kpi-sub"><?= sprintf('%d mois couverts', $monthsCount) ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Réalisé (période)</p>
        <p class="kpi-value"><?= e(formatPrice($totals['realized'])) ?></p>
        <p class="kpi-sub"><?= sprintf('%d mois couverts', $monthsCount) ?></p>
    </div>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Prévu vs Réalisé</h2>
    <p class="muted">Période affichée : <?= (int) $monthsCount ?> mois — tu édites le budget du mois <strong><?= e(sprintf('%02d/%04d', $editMonth['month'], $editMonth['year'])) ?></strong> (dernier mois de la période).</p>
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

                        // CA : positif = objectif dépassé. Dépenses : positif = reste disponible.
                        $gap = $isCa ? $r['realized'] - $r['planned'] : $r['planned'] - $r['realized'];
                        $totalGap += $gap;

                        if ($r['planned'] <= 0) {
                            $state = '<span class="badge badge-muted">—</span>';
                        } elseif ($isCa) {
                            $state = $r['realized'] >= $r['planned']
                                ? '<span class="badge badge-success">Objectif dépassé</span>'
                                : '<span class="badge badge-warning">Sous l\'objectif</span>';
                        } else {
                            $state = $r['realized'] <= $r['planned']
                                ? '<span class="badge badge-success">Dans le budget</span>'
                                : '<span class="badge badge-danger">Dépassé</span>';
                        }
                    ?>
                    <tr>
                        <td><?= $isCa ? '<strong>' . e($r['label']) . '</strong>' : e($r['label']) ?></td>
                        <td>
                            <input type="text" name="planned[<?= e($r['key']) ?>]"
                                value="<?= $r['planned'] > 0 ? e(number_format($r['planned'], 2, ',', '')) : '' ?>"
                                inputmode="decimal" style="width:110px" placeholder="—">
                        </td>
                        <td class="num"><?= e(formatPrice($r['realized'])) ?></td>
                        <td class="num"><?= e(($gap > 0 ? '+' : '') . formatPrice($gap)) ?></td>
                        <td><?= $state ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th><?= e(formatPrice($totals['planned'])) ?></th>
                    <th class="num"><?= e(formatPrice($totals['realized'])) ?></th>
                    <th class="num"><?= e(($totalGap > 0 ? '+' : '') . formatPrice($totalGap)) ?></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
        <button type="submit" class="btn btn-primary">Enregistrer le budget</button>
        <p class="muted" style="font-size:0.85rem">Les montants se saisissent à la française (ex : 250,50). Laisse vide ou mets 0 pour supprimer une ligne du budget.</p>
    </form>
</div>

<script>
(function () {
    // Sélecteur « 📅 Période » : les presets soumettent seuls, comme sur
    // Réappro ; « Personnalisé » révèle d'abord les bornes de dates.
    var form = document.getElementById('period-filters');
    var custom = document.getElementById('period-custom');
    if (!form) return;

    var select = form.querySelector('select[name="period"]');
    if (!select) return;

    select.addEventListener('change', function () {
        if (custom) {
            custom.hidden = select.value !== 'custom';
        }
        if (select.value === 'custom') {
            var from = document.getElementById('from');
            if (from) from.focus();
        } else {
            form.submit();
        }
    });
})();
</script>
