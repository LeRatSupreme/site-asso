<?php

declare(strict_types=1);

use App\Models\Cart;

/**
 * Catalogue cafétéria + panier.
 *
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $products
 * @var Cart $cart
 * @var bool $ordersEnabled
 * @var string|null $sumupLink
 */

$byCategory = [];
$uncategorized = [];
foreach ($products as $p) {
    $cat = $p['category_name'] ?? null;
    if ($cat !== null && $cat !== '') {
        $byCategory[$cat][] = $p;
    } else {
        $uncategorized[] = $p;
    }
}
if ($uncategorized !== []) {
    $byCategory['Autres'] = $uncategorized;
}
?>
<header class="dash-head">
    <span class="eyebrow">Cafétéria</span>
    <h1 class="page-title">Commander</h1>
</header>

<?php if (!$ordersEnabled): ?>
    <div class="empty-state surface glass">
        <p>Les commandes sont actuellement désactivées. Revenez plus tard !</p>
    </div>
<?php else: ?>
    <div class="cafeteria-layout">
        <div class="cafeteria-catalog">
            <?php foreach ($byCategory as $catName => $catProducts): ?>
                <section class="card surface glass">
                    <h2 class="card-title"><?= e($catName) ?></h2>
                    <div class="grid grid-3">
                        <?php foreach ($catProducts as $p): ?>
                            <div class="product-card">
                                <div class="product-info">
                                    <strong><?= e($p['name'] ?? '') ?></strong>
                                    <span class="product-price"><?= e(formatPrice($p['price'] ?? 0)) ?></span>
                                    <?php if ((int) ($p['stock'] ?? 0) <= 0): ?>
                                        <span class="badge badge-danger">Épuisé</span>
                                    <?php endif; ?>
                                </div>
                                <form method="post" action="<?= e(url('/eleve/cafeteria/add')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="product_id" value="<?= e((string) $p['id']) ?>">
                                    <input type="number" name="quantity" value="1" min="1" max="99" class="qty-input" aria-label="Quantité">
                                    <button type="submit" class="btn btn-primary btn-sm">Ajouter</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <aside class="cafeteria-cart surface glass">
            <h2 class="card-title">Mon panier</h2>
            <?php if ($cart->isEmpty()): ?>
                <p class="card-meta">Votre panier est vide.</p>
            <?php else: ?>
                <ul class="list-rows">
                    <?php foreach ($cart->items() as $id => $entry): ?>
                        <li>
                            <span><?= e((int) $entry['quantity']) ?> × <?= e($entry['product']['name'] ?? '') ?></span>
                            <strong><?= e(formatPrice((float) $entry['product']['price'] * (int) $entry['quantity'])) ?></strong>
                            <form method="post" action="<?= e(url('/eleve/cafeteria/remove')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="product_id" value="<?= e((string) $id) ?>">
                                <button type="submit" class="btn btn-ghost btn-sm" aria-label="Retirer">×</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="order-total">Total : <strong><?= e(formatPrice($cart->total())) ?></strong></p>

                <form method="post" action="<?= e(url('/eleve/cafeteria/checkout')) ?>">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="notes">Notes (optionnel)</label>
                        <textarea id="notes" name="notes" rows="2" placeholder="Allergies, précisions..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Valider ma commande</button>
                </form>
                <?php if ($sumupLink !== null): ?>
                    <a class="btn btn-sumup btn-block" href="<?= e($sumupLink) ?>" rel="noopener noreferrer" target="_blank">Payer ma commande (SumUp)</a>
                    <p class="card-meta">Optionnel : les commandes AEIC sont réglées et retrouvées au comptoir.</p>
                <?php endif; ?>
                <form method="post" action="<?= e(url('/eleve/cafeteria/clear')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-block btn-sm">Vider le panier</button>
                </form>
            <?php endif; ?>
        </aside>
    </div>
<?php endif; ?>
