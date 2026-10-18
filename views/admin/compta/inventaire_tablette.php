<?php

declare(strict_types=1);

/**
 * Inventaire tablette SumUp — vue minimale fonctionnelle (vague 1).
 *
 * L'interface complète (onglets saisie/historique/analyse, graphiques,
 * deltas vs période précédente) sera construite en vague 2 ; les données
 * d'analyse et la fenêtre précédente sont déjà passées par le
 * contrôleur pour cette évolution.
 *
 * En-tête et onglets de niveau 1 fournis par la page fusionnée
 * « Opérations » (operations.php) qui inclut cette vue.
 *
 * @var array<string,mixed>       $user
 * @var string                    $tab          'saisie'|'historique'|'analyse'
 * @var string                    $from         Début fenêtre d'analyse (Y-m-d)
 * @var string                    $to           Fin fenêtre d'analyse (Y-m-d)
 * @var string                    $prevFrom     Début fenêtre précédente (Y-m-d)
 * @var string                    $prevTo       Fin fenêtre précédente (Y-m-d)
 * @var list<array{key:string, theoretical:?int, unit_cost:?string}> $products
 * @var list<array<string,mixed>> $sessions     (+ lines_count, gaps_count,
 *                                              missing_cost_count,
 *                                              loss_value_sum,
 *                                              corrected_count)
 * @var array{kpis:array{sessions:int,gaps:int,loss_value:float,missing_cost_count:int,products_affected:int}, per_session:list<array{date:string,label:string,gaps:int,loss_value:float}>, top_products:list<array{key:string,gaps_units_sum:int,loss_value:float}>} $analysis
 * @var array{kpis:array{sessions:int,gaps:int,loss_value:float,missing_cost_count:int,products_affected:int}, per_session:list<array{date:string,label:string,gaps:int,loss_value:float}>, top_products:list<array{key:string,gaps_units_sum:int,loss_value:float}>} $prev
 */
?>
<p class="muted">
    Releve les quantités affichées sur la <strong>tablette SumUp</strong> : le site calcule l'écart
    contre son stock théorique (dernier comptage + achats − ventes − pertes). Chaque écart
    négatif est une suspicion de vol/casse, valorisée en perte € estimée.
</p>

<section class="card surface glass">
    <h2 class="card-title">Nouveau relevé tablette</h2>
    <form method="post" action="<?= e(url('/admin/compta/inventaire-tablette/save')) ?>">
        <?= csrf_field() ?>

        <div class="field-row">
            <div class="field">
                <label for="counted_at">Date du relevé</label>
                <input type="date" id="counted_at" name="counted_at" value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="field">
                <label for="note">Note <span class="muted">(optionnel)</span></label>
                <input type="text" id="note" name="note" placeholder="ex : relevé du vendredi soir">
            </div>
        </div>

        <p class="muted">Ne saisis que les produits visibles sur la tablette — les lignes laissées vides sont ignorées.</p>

        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th class="th-num">Théorique site</th>
                        <th class="th-num">Qté tablette</th>
                        <th>Avis <span class="muted">(optionnel)</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td>
                                <?= e($p['key']) ?>
                                <input type="hidden" name="keys[]" value="<?= e($p['key']) ?>">
                            </td>
                            <td class="num"><?= $p['theoretical'] !== null ? (int) $p['theoretical'] : '—' ?></td>
                            <td class="num">
                                <input type="number" name="qtys[]" min="0" step="1" inputmode="numeric" style="width:6rem">
                            </td>
                            <td><input type="text" name="avis[]" maxlength="500" style="width:100%"></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($products === []): ?>
                        <tr><td colspan="4" class="muted">Aucun produit connu (aucune vente, stock ni comptage).</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"<?= $products === [] ? ' disabled' : '' ?>>Enregistrer le relevé</button>
        </div>
    </form>
</section>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Sessions de relevé</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th class="th-num">Produits</th>
                <th class="th-num">Écarts</th>
                <th class="th-num">Perte € estimée</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $s): ?>
                <tr>
                    <td><?= e(formatDate((string) $s['counted_at'])) ?></td>
                    <td class="num"><?= (int) $s['lines_count'] ?></td>
                    <td class="num"><?= (int) $s['gaps_count'] > 0 ? '<strong>' . (int) $s['gaps_count'] . '</strong>' : '0' ?></td>
                    <td class="num <?= (float) $s['loss_value_sum'] > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice((float) $s['loss_value_sum'])) ?></td>
                    <td class="row-actions">
                        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/inventaire-tablette/' . rawurlencode((string) $s['id']))) ?>">Détail</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($sessions === []): ?>
                <tr><td colspan="5" class="muted">Aucune session enregistrée.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
