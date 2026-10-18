<?php

declare(strict_types=1);

use App\Models\Loss;

/**
 * Détail d'une session « Inventaire tablette SumUp ».
 *
 * Page autonome (propre breadcrumb) : KPIs, tableau des lignes triées
 * par écart croissant puis produit, et bloc de correction (irrévocable)
 * avec un motif de perte par ligne à écart non nul.
 *
 * IMPORTANT (ordre du POST) : les <select name="reasons[]"> sont rendus
 * DANS LE MÊME ORDRE que $lines (ordre lines() : écart croissant puis
 * produit), en sautant les lignes corrigées ou sans écart. Le
 * contrôleur aligne reasons[] sur ce même sous-ensemble : ne PAS
 * réordonner les colonnes ni les lignes sans synchroniser le
 * contrôleur.
 *
 * @var array<string,mixed>       $user
 * @var array<string,mixed>       $session   ligne inventory_tablet_sessions
 * @var list<array<string,mixed>> $lines     lignes triées par écart croissant
 *                                           puis produit (gap NULL, gap,
 *                                           unit_cost, loss_value, avis,
 *                                           corrected, corrected_at,
 *                                           corrected_by, tablet_qty,
 *                                           site_theoretical, product_key…)
 * @var int                       $gapsCount nombre de lignes à écart ≠ 0
 * @var float                     $lossTotal perte € estimée de la session
 * @var bool                      $correctable true = aucune ligne corrigée
 *                                           ET au moins un écart non corrigé
 */

// Libellés FR des motifs de perte (Loss::REASONS).
$reasonLabels = [
    'CASSE'  => 'Casse',
    'PERIME' => 'Périmé',
    'VOL'    => 'Vol / suspicion de vol',
    'OFFERT' => 'Offert',
    'ERREUR' => 'Erreur de saisie',
    'DIVERS' => 'Divers',
];

// Agrégats complémentaires + présence de lignes corrigées (déterminée
// côté vue pour conditionner la suppression ; le serveur reste
// autoritaire : deleteSession() refuse de toute façon).
$correctedGaps = 0;
$missingCostCount = 0;
$sessionHasCorrected = false;
foreach ($lines as $line) {
    $gap = $line['gap'] !== null ? (int) $line['gap'] : null;
    $isCorrected = (int) $line['corrected'] === 1;
    if ($isCorrected) {
        $sessionHasCorrected = true;
    }
    if ($gap !== null && $gap !== 0) {
        if ($isCorrected) {
            $correctedGaps++;
        }
        if ($gap < 0 && $line['unit_cost'] === null) {
            $missingCostCount++;
        }
    }
}
$allCorrected = $gapsCount > 0 && $correctedGaps === $gapsCount;
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité → Opérations → Inventaire</p>
        <h1 class="page-title">Relevé tablette du <?= e(formatDate((string) $session['counted_at'])) ?></h1>
        <p class="muted">
            <a href="<?= e(url('/admin/compta/inventaire-tablette')) ?>">← Toutes les sessions</a>
            <?php if ((string) ($session['note'] ?? '') !== ''): ?>
                · <?= e((string) $session['note']) ?>
            <?php endif; ?>
            · enregistré le <?= e(formatDateTime((string) $session['created_at'])) ?>
        </p>
    </div>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Produits saisis</p>
        <p class="kpi-value"><?= count($lines) ?></p>
        <p class="kpi-sub">lignes enregistrées</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Écarts</p>
        <p class="kpi-value"><?= $gapsCount ?></p>
        <p class="kpi-sub">quantité tablette ≠ théorique site</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Perte estimée</p>
        <p class="kpi-value <?= $lossTotal > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice($lossTotal)) ?></p>
        <p class="kpi-sub">manquants × coût unitaire</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Coûts manquants</p>
        <p class="kpi-value"><?= $missingCostCount ?></p>
        <p class="kpi-sub">manquants sans coût de revient</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Statut</p>
        <p class="kpi-value">
            <?php if ($allCorrected): ?>
                <span class="badge badge-success">Corrigée</span>
            <?php elseif ($correctable): ?>
                <span class="badge badge-warning">À corriger</span>
            <?php elseif ($correctedGaps > 0): ?>
                <span class="badge badge-info">Partielle</span>
            <?php else: ?>
                <span class="badge badge-muted">Sans écart</span>
            <?php endif; ?>
        </p>
        <p class="kpi-sub">
            <?php if ($gapsCount > 0): ?><?= $correctedGaps ?> / <?= $gapsCount ?> écart(s) corrigé(s)<?php else: ?>rien à corriger<?php endif; ?>
        </p>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/compta/inventaire-tablette/' . rawurlencode((string) $session['id']) . '/correct')) ?>"
      data-preserve-scroll>
    <?= csrf_field() ?>

    <div class="card surface glass table-wrap">
        <h2 class="card-title">Lignes du relevé</h2>
        <?php if ($correctable): ?>
            <p class="muted">
                Choisis un motif par produit à écart : les <strong>manquants</strong> créent des pertes
                valorisées (sorties du stock théorique), les <strong>surplus</strong> alignent le stock
                de référence à la hausse. Cette action est <strong>irrévocable</strong>.
            </p>
        <?php endif; ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th class="th-num">Stock tablette</th>
                    <th class="th-num">Stock site <span class="muted">(théorique)</span></th>
                    <th class="th-num">Écart</th>
                    <th class="th-num">Coût unit.</th>
                    <th class="th-num">Perte €</th>
                    <th>Avis</th>
                    <th>Corrigé</th>
                    <?php if ($correctable): ?><th class="th-num">Motif de perte</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <?php
                        $gap = $line['gap'] !== null ? (int) $line['gap'] : null;
                        $corrected = (int) $line['corrected'] === 1;
                        // Une seule reasons[] PAR ligne à écart non nul non
                        // corrigée, DANS L'ORDRE DU TABLEAU (= ordre lines())
                        // : l'alignement avec le contrôleur en dépend.
                        $needsReason = $gap !== null && $gap !== 0 && !$corrected;
                    ?>
                    <tr>
                        <td><?= e((string) $line['product_key']) ?></td>
                        <td class="num"><?= (int) $line['tablet_qty'] ?></td>
                        <td class="num"><?= $line['site_theoretical'] !== null ? (int) $line['site_theoretical'] : '—' ?></td>
                        <td class="num">
                            <?php if ($gap === null): ?>
                                —
                            <?php elseif ($gap < 0): ?>
                                <span class="badge badge-danger">manquant <?= -$gap ?></span>
                            <?php elseif ($gap > 0): ?>
                                <span class="badge badge-success">surplus <?= $gap ?></span>
                            <?php else: ?>
                                <span class="badge badge-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= $line['unit_cost'] !== null ? e(formatPrice((float) $line['unit_cost'], 3)) : '—' ?></td>
                        <td class="num">
                            <?php if ($line['loss_value'] !== null): ?>
                                <?= e(formatPrice((float) $line['loss_value'])) ?>
                            <?php elseif ($gap !== null && $gap < 0 && $line['unit_cost'] === null): ?>
                                <span class="badge badge-warning">coût manquant</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= $line['avis'] !== null && (string) $line['avis'] !== '' ? e((string) $line['avis']) : '—' ?></td>
                        <td>
                            <?php if ($corrected): ?>
                                <span class="badge badge-success">Corrigé</span>
                                <br><span class="muted">
                                    <?= e(formatDateTime((string) $line['corrected_at'])) ?>
                                    <?php if ((string) ($line['corrected_by'] ?? '') !== ''): ?>
                                        · <?= e((string) $line['corrected_by']) ?>
                                    <?php endif; ?>
                                </span>
                            <?php elseif ($needsReason): ?>
                                <span class="badge badge-warning">À corriger</span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <?php if ($correctable): ?>
                            <td class="num">
                                <?php if ($needsReason): ?>
                                    <select name="reasons[]" required>
                                        <?php foreach (Loss::REASONS as $reason): ?>
                                            <option value="<?= e($reason) ?>"<?= $reason === 'VOL' ? ' selected' : '' ?>><?= e($reasonLabels[$reason] ?? $reason) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($lines === []): ?>
                    <tr><td colspan="<?= $correctable ? 9 : 8 ?>" class="muted">Aucune ligne.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($correctable): ?>
            <div class="field">
                <label for="avis_global">Avis global <span class="muted">(optionnel — remplace l'avis de chaque ligne dans les pertes créées)</span></label>
                <input type="text" id="avis_global" name="avis" maxlength="500">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"
                        data-confirm="Corriger la session ? Cette action est IRRÉVOCABLE : des pertes seront créées, le stock sera aligné et la session ne pourra plus être corrigée ni supprimée.">
                    Corriger &amp; synchroniser le stock
                </button>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php if (!$sessionHasCorrected): ?>
    <form method="post" action="<?= e(url('/admin/compta/inventaire-tablette/' . rawurlencode((string) $session['id']) . '/delete')) ?>"
          data-confirm="Supprimer cette session de relevé ? Les lignes seront supprimées avec elle (aucune trace conservée)." data-preserve-scroll>
        <?= csrf_field() ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger btn-sm">Supprimer la session</button>
        </div>
    </form>
<?php endif; ?>
