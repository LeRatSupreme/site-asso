<?php

declare(strict_types=1);

/**
 * Caisse (POS) : sélection de produits → validation comptant.
 *
 * @var list<array<string,mixed>> $categories
 * @var list<array<string,mixed>> $products
 */
?>
<div class="pos-grid">
    <section class="card surface glass pos-catalogue">
        <h2 class="card-title">Catalogue</h2>
        <?php foreach ($categories as $cat): ?>
            <h3 class="pos-cat-title"><?= e($cat['name'] ?? '') ?></h3>
            <div class="pos-products">
                <?php foreach ($products as $p): ?>
                    <?php if (($p['category_id'] ?? null) !== $cat['id']) continue; ?>
                    <button type="button"
                            class="pos-product"
                            data-id="<?= e((string) $p['id']) ?>"
                            data-name="<?= e($p['name'] ?? '') ?>"
                            data-price="<?= e((string) ($p['price'] ?? 0)) ?>"
                            data-stock="<?= e((string) ($p['stock'] ?? 0)) ?>">
                        <span class="pos-product-name"><?= e($p['name'] ?? '') ?></span>
                        <span class="pos-product-price"><?= e(formatPrice($p['price'] ?? 0)) ?></span>
                        <span class="pos-product-stock">stock : <?= e((string) ($p['stock'] ?? 0)) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="card surface glass pos-cart">
        <h2 class="card-title">Panier</h2>
        <ul id="pos-cart-list" class="list-rows"></ul>
        <p id="pos-total" class="pos-total">Total : <strong>0,00 €</strong></p>

        <form id="pos-form" method="post" action="<?= e(url('/admin/cafeteria/pos')) ?>">
            <?= csrf_field() ?>
            <div id="pos-fields"></div>
            <button type="submit" class="btn btn-primary btn-block" id="pos-checkout" disabled>Valider la vente</button>
        </form>
    </section>
</div>

<script>
(function () {
    var cart = {};
    var list = document.getElementById('pos-cart-list');
    var totalEl = document.getElementById('pos-total').querySelector('strong');
    var fields = document.getElementById('pos-fields');
    var checkoutBtn = document.getElementById('pos-checkout');

    function money(n) {
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(n);
    }

    function render() {
        list.innerHTML = '';
        fields.innerHTML = '';
        var total = 0;
        var entries = Object.keys(cart);
        entries.forEach(function (id) {
            var item = cart[id];
            total += item.qty * item.price;
            var li = document.createElement('li');
            li.innerHTML = '<span>' + item.name + ' ×' +
                '<button type="button" data-act="dec" data-id="' + id + '">−</button> ' +
                item.qty + ' <button type="button" data-act="inc" data-id="' + id + '">+</button>' +
                '</span><span>' + money(item.qty * item.price) + '</span>';
            list.appendChild(li);

            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'items[' + id + ']';
            input.value = item.qty;
            fields.appendChild(input);
        });
        totalEl.textContent = money(total);
        checkoutBtn.disabled = entries.length === 0;
    }

    document.querySelectorAll('.pos-product').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.dataset.id;
            if (!cart[id]) {
                cart[id] = { name: btn.dataset.name, price: parseFloat(btn.dataset.price), qty: 0, stock: parseInt(btn.dataset.stock, 10) };
            }
            if (cart[id].qty >= cart[id].stock) return;
            cart[id].qty++;
            render();
        });
    });

    list.addEventListener('click', function (e) {
        var t = e.target;
        if (t.tagName !== 'BUTTON') return;
        var id = t.dataset.id;
        if (!cart[id]) return;
        if (t.dataset.act === 'inc' && cart[id].qty < cart[id].stock) cart[id].qty++;
        if (t.dataset.act === 'dec') cart[id].qty--;
        if (cart[id].qty <= 0) delete cart[id];
        render();
    });
})();
</script>
