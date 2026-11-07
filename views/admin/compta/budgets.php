<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 * @var array{year:int,month:int,value:string,choisi:bool} $editMonth
 * @var array<string,float>|null $editPlanned Budget RÉEL du mois édité (null = sans ?month : champs au prorata de la période, comportement historique).
 * @var array{values:array<string,int>, label:string} $suggest Suggestions « réalisé récent » : moyenne du réalisé des 3 mois précédant le mois édité, arrondie à l'euro (lecture seule, aucun enregistrement automatique).
 * @var string $currentMonthValue Mois calendaire courant « YYYY-MM ».
 * @var list<array{value:string,label:string,ca_planned:float,ca_realized:float,exp_planned:float,exp_realized:float}> $annual Grille annuelle (lecture seule).
 * @var string $rangeLabel
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

<?php
// Sélecteur du mois ÉDITÉ (≠ période comparée) : ‹ précédent · mois actif ·
// suivant › · retour au mois courant. Les liens conservent les autres
// paramètres GET (période notamment), comme le fait la barre « Période ».
$keep = $_GET;
unset($keep['month']);
$monthLink = static fn(string $value): string => '?' . http_build_query(array_merge($keep, ['month' => $value]));
$editFirst = new DateTimeImmutable($editMonth['value'] . '-01');
$prevMonth = $editFirst->modify('-1 month');
$nextMonth = $editFirst->modify('+1 month');
?>
<div class="period-bar">
    <span class="period-bar-label">Mois édité</span>
    <nav class="period-pills" aria-label="Choix du mois de budget édité">
        <?php if ((int) $prevMonth->format('Y') >= 2020): ?>
            <a class="period-pill" href="<?= e($monthLink($prevMonth->format('Y-m'))) ?>" title="Éditer le mois précédent">‹ <?= e($prevMonth->format('m/Y')) ?></a>
        <?php endif; ?>
        <span class="period-pill is-active" title="Mois en cours d'édition"><?= e($editFirst->format('m/Y')) ?></span>
        <a class="period-pill" href="<?= e($monthLink($nextMonth->format('Y-m'))) ?>" title="Éditer le mois suivant"><?= e($nextMonth->format('m/Y')) ?> ›</a>
        <?php if ($editMonth['value'] !== $currentMonthValue): ?>
            <a class="period-pill" href="<?= e($monthLink($currentMonthValue)) ?>">Mois courant</a>
        <?php endif; ?>
    </nav>
    <span class="muted" style="font-size:0.85rem">Ce sélecteur choisit le mois <strong>enregistré par le formulaire</strong> ; la comparaison reste celle de la période ci-dessus.</span>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Résultat prévu</p>
        <p class="kpi-value"><?= e(formatPrice($resultPlanned)) ?></p>
        <p class="kpi-sub">CA prévu − dépenses prévues · <?= e($rangeLabel) ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Résultat réalisé</p>
        <p class="kpi-value <?= $resultRealized >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($resultRealized)) ?></p>
        <p class="kpi-sub">CA réalisé − dépenses réalisées · <?= e($rangeLabel) ?></p>
    </div>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Prévu vs Réalisé</h2>
    <p class="muted">
        Réalisé : <strong><?= e($rangeLabel) ?></strong> — tu édites le budget du mois <strong><?= e(sprintf('%02d/%04d', $editMonth['month'], $editMonth['year'])) ?></strong><?= $editMonth['choisi'] ? ' (choisi dans « Mois édité »)' : ' (dernier mois de la période)' ?>.
        Le « prévu » est ajusté au prorata des jours de la période (ex : 1 jour en septembre compare à 1/30 du budget de septembre) pour que la comparaison reste équitable sur n'importe quelle durée<?= $editMonth['choisi'] ? ' — les champs ci-dessous, eux, montrent le budget complet du mois édité' : '' ?>.
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

                        // Valeur du champ : avec ?month= (mois choisi), le
                        // budget RÉEL du mois édité ; sinon (comportement
                        // historique) le « prévu » au prorata de la période.
                        $editValue = $editPlanned !== null
                            ? (float) ($editPlanned[$r['key']] ?? 0)
                            : (float) $r['planned'];

                        // Suggestion « réalisé récent » (calculée côté
                        // contrôleur) : transportée dans data-suggest déjà
                        // formatée à la française — le bouton « Pré-remplir »
                        // n'a plus qu'à recopier, zéro calcul en JS. Vide si
                        // aucun réalisé sur la fenêtre.
                        $suggestValue = (int) ($suggest['values'][$r['key']] ?? 0);

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
                                value="<?= $editValue > 0 ? e(number_format($editValue, 2, ',', ' ')) : '' ?>"
                                data-suggest="<?= $suggestValue > 0 ? e(number_format((float) $suggestValue, 2, ',', ' ')) : '' ?>"
                                inputmode="decimal" style="width:130px" placeholder="—"
                                aria-label="Budget prévu (en euros) pour <?= e($r['label']) ?>">
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
        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center">
            <button type="submit" class="btn btn-primary">Enregistrer le budget</button>
            <button type="button" class="btn btn-ghost" id="budget-suggest"
                title="Remplit les champs « Prévu » avec la moyenne du réalisé des 3 mois précédant le mois édité">Pré-remplir avec le réalisé récent</button>
            <span class="muted" style="font-size:0.85rem">moyenne réalisée <?= e($suggest['label']) ?> — ajuste puis enregistre ; rien n'est sauvé sans « Enregistrer le budget ».</span>
        </div>
        <p class="muted" style="font-size:0.85rem">Les montants se saisissent à la française (ex : 250,50 ou 10 000). Laisse vide ou mets 0 pour supprimer une ligne. La ligne « Bilan » compare le résultat (CA − dépenses) prévu au réalisé. <strong>Matière (cafétéria)</strong> se remplit automatiquement avec tes achats enregistrés (page Achats &amp; stock) — inutile de les ressaisir en dépenses.</p>
    </form>
</div>

<?php
// Mini-grille annuelle — LECTURE SEULE (aucun formulaire ici, aucun champ
// modifiable) : 12 mois × prévu vs réalisé, mêmes sources que la page.
$annualTotals = ['ca_planned' => 0.0, 'ca_realized' => 0.0, 'exp_planned' => 0.0, 'exp_realized' => 0.0];
foreach ($annual as $a) {
    foreach (array_keys($annualTotals) as $k) {
        $annualTotals[$k] += (float) $a[$k];
    }
}
$cell = static fn(float $v): string => $v > 0.0 ? e(formatPrice($v)) : '<span class="muted">—</span>';
?>
<div class="card surface glass table-wrap">
    <h2 class="card-title">Année <?= e($editMonth['year']) ?> d'un coup d'œil</h2>
    <p class="muted">Lecture seule : budgets vs réalisé par mois calendaire (les achats de stock comptent dans les dépenses, comme dans le tableau ci-dessus). Clique un mois pour l'éditer.</p>
    <table class="table">
        <thead>
            <tr>
                <th>Mois</th>
                <th class="th-num">CA prévu</th>
                <th class="th-num">CA réalisé</th>
                <th class="th-num">Dépenses prévues</th>
                <th class="th-num">Dépenses réalisées</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($annual as $a): ?>
                <?php $enCours = $a['value'] === $currentMonthValue; ?>
                <tr>
                    <td>
                        <a href="<?= e($monthLink($a['value'])) ?>" title="Éditer le budget de ce mois"><?= e($a['label']) ?></a>
                        <?= $enCours ? '<span class="badge badge-muted">en cours</span>' : '' ?>
                    </td>
                    <td class="num"><?= $cell((float) $a['ca_planned']) ?></td>
                    <td class="num"><?= $cell((float) $a['ca_realized']) ?></td>
                    <td class="num"><?= $cell((float) $a['exp_planned']) ?></td>
                    <td class="num"><?= $cell((float) $a['exp_realized']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th>Total</th>
                <th class="num"><?= e(formatPrice($annualTotals['ca_planned'])) ?></th>
                <th class="num"><?= e(formatPrice($annualTotals['ca_realized'])) ?></th>
                <th class="num"><?= e(formatPrice($annualTotals['exp_planned'])) ?></th>
                <th class="num"><?= e(formatPrice($annualTotals['exp_realized'])) ?></th>
            </tr>
        </tfoot>
    </table>
</div>

<script>
(function () {
    // « Pré-remplir avec le réalisé récent » : recopie dans chaque champ la
    // suggestion calculée côté CONTRÔLEUR (attribut data-suggest, déjà
    // formatée en euros à la française). Aucun calcul ni requête ici, aucune
    // écriture : l'admin ajuste puis valide par « Enregistrer le budget ».
    var btn = document.getElementById('budget-suggest');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', function () {
        var champs = document.querySelectorAll('input[name^="planned["][data-suggest]');
        for (var i = 0; i < champs.length; i++) {
            champs[i].value = champs[i].getAttribute('data-suggest') || '';
        }
    });
})();
</script>

