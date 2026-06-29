<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $costs
 * @var array<string,mixed> $form
 */
?>
<div class="compta-grid">
    <div class="card surface glass">
        <h2 class="card-title">Ajouter un lot de coût</h2>
        <p class="muted">Le lot précédent en cours est automatiquement clôturé (au jour précédent).</p>
        <form method="post" action="<?= e(url('/admin/compta/couts/save')) ?>">
            <?= csrf_field() ?>
            <p>
                <label for="product_key">Produit canonique</label><br>
                <input type="text" id="product_key" name="product_key" value="<?= e((string) $form['product_key']) ?>" placeholder="ex: Bueno" required>
            </p>
            <p>
                <label for="cost_price">Coût unitaire (€)</label><br>
                <input type="text" id="cost_price" name="cost_price" value="<?= e((string) $form['cost_price']) ?>" placeholder="0,60" required>
            </p>
            <p>
                <label for="valid_from">Début de validité</label><br>
                <input type="date" id="valid_from" name="valid_from" value="<?= e((string) $form['valid_from']) ?>" required>
            </p>
            <p>
                <label for="supplier">Fournisseur</label><br>
                <input type="text" id="supplier" name="supplier" value="<?= e((string) $form['supplier']) ?>">
            </p>
            <p>
                <label for="notes">Notes</label><br>
                <textarea id="notes" name="notes" rows="2"><?= e((string) $form['notes']) ?></textarea>
            </p>
            <p><button type="submit" class="btn btn-primary">Enregistrer le lot</button></p>
        </form>
    </div>

    <div class="card surface glass table-wrap">
        <h2 class="card-title">Lots existants</h2>
        <table class="table">
            <thead><tr><th>Produit</th><th>Coût unit.</th><th>Du</th><th>Au</th><th>Fournisseur</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($costs as $c): ?>
                    <tr>
                        <td><strong><?= e((string) $c['product_key']) ?></strong></td>
                        <td><?= e(formatPrice($c['cost_price'])) ?></td>
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
                                <form method="post" action="<?= e(url('/admin/compta/couts/' . rawurlencode((string) $c['id']) . '/close')) ?>" onsubmit="return confirm('Clôturer ce lot maintenant ?');">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-outline btn-sm">Clôturer</button>
                                </form>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($costs === []): ?>
                    <tr><td colspan="6" class="muted">Aucun lot défini pour le moment.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
