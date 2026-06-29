<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>>                $costs
 * @var array<string, list<array<string,mixed>>> $grouped
 * @var list<string>                             $productKeys
 * @var array<string,mixed>                      $form
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Coûts de revient</h1>
        <p class="muted">Un lot = un coût d'achat unitaire daté par produit. Comme tu achètes à plusieurs endroits, crée un nouveau lot quand le prix change ; l'ancien est clôturé automatiquement la veille. Le bénéfice est calculé avec le lot valide à la date de chaque vente.</p>
    </div>
</div>

<div class="costs-layout">

    <!-- Formulaire d'ajout -->
    <section class="card surface glass costs-form">
        <h2 class="card-title">Ajouter un lot</h2>

        <form method="post" action="<?= e(url('/admin/compta/couts/save')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label for="product_key">Produit canonique</label>
                <input
                    type="text"
                    id="product_key"
                    name="product_key"
                    list="product-keys"
                    value="<?= e((string) $form['product_key']) ?>"
                    placeholder="Commence à taper… (ex: Bueno)"
                    autocomplete="off"
                    required>
                <p class="field-help">Sélectionne dans la liste pour éviter les fautes d'orthographe. Tu peux aussi créer une nouvelle clé si besoin.</p>
                <datalist id="product-keys">
                    <?php foreach ($productKeys as $k): ?>
                        <option value="<?= e($k) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="cost_price">Coût unitaire (€)</label>
                    <input type="text" id="cost_price" name="cost_price" value="<?= e((string) $form['cost_price']) ?>" placeholder="0,60" inputmode="decimal" required>
                </div>
                <div class="field">
                    <label for="valid_from">Début de validité</label>
                    <input type="date" id="valid_from" name="valid_from" value="<?= e((string) $form['valid_from']) ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="supplier">Fournisseur <span class="muted">(optionnel)</span></label>
                <input type="text" id="supplier" name="supplier" value="<?= e((string) $form['supplier']) ?>" placeholder="ex: Metro, Carrefour…">
            </div>

            <div class="field">
                <label for="notes">Notes <span class="muted">(optionnel)</span></label>
                <textarea id="notes" name="notes" rows="2" placeholder="ex: Promotion, conditionnement…"></textarea>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Enregistrer le lot</button>
        </form>
    </section>

    <!-- Lots existants, regroupés par produit -->
    <section class="card surface glass costs-list">
        <div class="costs-list-head">
            <h2 class="card-title">Lots existants</h2>
            <?php if ($costs !== []): ?>
                <span class="badge badge-muted"><?= count($costs) ?> lot<?= count($costs) > 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($costs === []): ?>
            <div class="empty-state">
                <p class="muted">Aucun lot défini pour le moment.</p>
                <p class="muted">Importe d'abord un rapport SumUp, puis ajoute un coût pour chaque produit pour calculer les bénéfices.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped as $productName => $lots): ?>
                <?php
                // Lot en cours = sans valid_to, le plus récent.
                $current = null;
                foreach ($lots as $l) {
                    if (empty($l['valid_to'])) { $current = $l; break; }
                }
                ?>
                <article class="cost-product <?= $current ? 'has-current' : 'has-none' ?>">
                    <header class="cost-product-head">
                        <h3><?= e($productName) ?></h3>
                        <?php if ($current !== null): ?>
                            <span class="badge badge-success">Lot en cours : <?= e(formatPrice($current['cost_price'])) ?> / unité</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Aucun lot actif</span>
                        <?php endif; ?>
                    </header>

                    <table class="table">
                        <thead>
                            <tr><th>Coût unit.</th><th>Du</th><th>Au</th><th>Fournisseur</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lots as $c): ?>
                                <tr class="<?= empty($c['valid_to']) ? 'row-current' : '' ?>">
                                    <td><strong><?= e(formatPrice($c['cost_price'])) ?></strong></td>
                                    <td><?= e(formatDate($c['valid_from'])) ?></td>
                                    <td>
                                        <?php if (!empty($c['valid_to'])): ?>
                                            <?= e(formatDate($c['valid_to'])) ?>
                                        <?php else: ?>
                                            <span class="badge badge-success">en cours</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string) ($c['supplier'] ?? '—')) ?></td>
                                    <td>
                                        <?php if (empty($c['valid_to'])): ?>
                                            <form method="post" action="<?= e(url('/admin/compta/couts/' . rawurlencode((string) $c['id']) . '/close')) ?>" onsubmit="return confirm('Clôturer ce lot maintenant ? Un nouveau lot devra être créé ensuite.');">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-outline btn-sm">Clôturer</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="cost-product-add">
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/compta/couts?product_key=' . rawurlencode($productName))) ?>">＋ Ajouter un lot pour <?= e($productName) ?></a>
                    </p>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</div>
