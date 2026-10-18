<?php

declare(strict_types=1);

use App\Models\Loss;

/**
 * Détail d'une session « Inventaire tablette SumUp » — vue minimale
 * fonctionnelle (vague 1 ; sera remplacée/polie en vague 2).
 *
 * @var array<string,mixed>       $user
 * @var array<string,mixed>       $session   ligne inventory_tablet_sessions
 * @var list<array<string,mixed>> $lines     lignes triées par écart croissant
 *                                           puis produit (gap NULL, gap,
 *                                           unit_cost, loss_value, avis,
 *                                           corrected, tablet_qty,
 *                                           site_theoretical, product_key…)
 * @var int                       $gapsCount nombre de lignes à écart ≠ 0
 * @var float                     $lossTotal perte € estimée de la session
 * @var bool                      $correctable true = aucune ligne corrigée
 *                                           ET au moins un écart non corrigé
 */
?>
<p class="muted">
    <a href="<?= e(url('/admin/compta/inventaire-tablette')) ?>">← Inventaire tablette</a>
</p>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Relevé du</p>
        <p class="kpi-value"><?= e(formatDate((string) $session['counted_at'])) ?></p>
        <p class="kpi-sub"><?= (string) ($session['note'] ?? '') !== '' ? e((string) $session['note']) : 'sans note' ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Produits relevés</p>
        <p class="kpi-value"><?= count($lines) ?></p>
        <p class="kpi-sub">lignes enregistrées</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Écarts</p>
        <p class="kpi-value"><?= $gapsCount ?></p>
        <p class="kpi-sub">quantité tablette ≠ théorique site</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Perte € estimée</p>
        <p class="kpi-value <?= $lossTotal > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice($lossTotal)) ?></p>
        <p class="kpi-sub">manquants × coût unitaire</p>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/compta/inventaire-tablette/' . rawurlencode((string) $session['id']) . '/correct')) ?>"
      data-confirm="Corriger la session ? Cette action est IRRÉVOCABLE : des pertes seront créées et le stock sera aligné." data-preserve-scroll>
    <?= csrf_field() ?>

    <div class="card surface glass table-wrap">
        <h2 class="card-title">Lignes du relevé</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th class="th-num">Qté tablette</th>
                    <th class="th-num">Qté site</th>
                    <th class="th-num">Écart</th>
                    <th class="th-num">Coût unitaire</th>
                    <th class="th-num">Perte €</th>
                    <th>Avis</th>
                    <th>Corrigé</th>
                    <?php if ($correctable): ?><th>Motif de perte</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <?php
                        $gap = $line['gap'] !== null ? (int) $line['gap'] : null;
                        $corrected = (int) $line['corrected'] === 1;
                        // Une seule raison[] PAR ligne à écart non nul non corrigée :
                        // l'ordre du POST reste aligné sur le contrôleur.
                        $needsReason = $gap !== null && $gap !== 0 && !$corrected;
                    ?>
                    <tr>
                        <td><?= e((string) $line['product_key']) ?></td>
                        <td class="num"><?= (int) $line['tablet_qty'] ?></td>
                        <td class="num"><?= $line['site_theoretical'] !== null ? (int) $line['site_theoretical'] : '—' ?></td>
                        <td class="num">
                            <?php if ($gap === null): ?>
                                —
                            <?php else: ?>
                                <strong class="<?= $gap < 0 ? 'is-negative' : ($gap > 0 ? 'is-positive' : '') ?>">
                                    <?= $gap > 0 ? '+' . $gap : $gap ?>
                                </strong>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= $line['unit_cost'] !== null ? e(formatPrice((float) $line['unit_cost'], 3)) : '<span class="muted">manquant</span>' ?></td>
                        <td class="num"><?= $line['loss_value'] !== null ? e(formatPrice((float) $line['loss_value'])) : '—' ?></td>
                        <td><?= $line['avis'] !== null && (string) $line['avis'] !== '' ? e((string) $line['avis']) : '—' ?></td>
                        <td>
                            <?php if ($corrected): ?>
                                <span class="badge badge-success">Corrigé</span>
                            <?php else: ?>
                                <span class="badge badge-muted">À corriger</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($correctable): ?>
                            <td>
                                <?php if ($needsReason): ?>
                                    <select name="reasons[]" required>
                                        <?php foreach (Loss::REASONS as $reason): ?>
                                            <option value="<?= e($reason) ?>"<?= $reason === 'VOL' ? ' selected' : '' ?>><?= e($reason) ?></option>
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
                <button type="submit" class="btn btn-primary">Corriger la session (pertes + alignement stock)</button>
            </div>
        <?php endif; ?>
    </div>
</form>

<form method="post" action="<?= e(url('/admin/compta/inventaire-tablette/' . rawurlencode((string) $session['id']) . '/delete')) ?>"
      data-confirm="Supprimer cette session de relevé ?" data-preserve-scroll>
    <?= csrf_field() ?>
    <div class="form-actions">
        <button type="submit" class="btn btn-danger btn-sm">Supprimer la session</button>
    </div>
</form>
