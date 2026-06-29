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
                <div class="combobox" id="pk-combobox">
                    <input
                        type="text"
                        id="product_key"
                        name="product_key"
                        class="combobox-input"
                        value="<?= e((string) $form['product_key']) ?>"
                        placeholder="Rechercher un produit (ex: bueno)…"
                        autocomplete="off"
                        role="combobox"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        aria-controls="pk-listbox"
                        required>
                    <span class="combobox-badge" id="pk-count" hidden></span>
                    <ul class="combobox-list" id="pk-listbox" role="listbox" hidden></ul>
                </div>
                <p class="field-help">Tape pour filtrer la liste des produits connus (ventes SumUp + cafétéria + lots existants). Clique pour sélectionner — ou garde ta saisie pour créer une nouvelle clé.</p>
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

<script type="application/json" id="pk-data"><?= json_encode($productKeys, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script>
(function () {
    var dataEl = document.getElementById('pk-data');
    var keys = [];
    try { keys = JSON.parse(dataEl.textContent) || []; } catch (e) { keys = []; }
    keys = keys.filter(function (k) { return typeof k === 'string' && k.trim() !== ''; });

    var input    = document.getElementById('product_key');
    var listbox  = document.getElementById('pk-listbox');
    var countEl  = document.getElementById('pk-count');
    if (!input || !listbox) return;

    var active = -1;
    var current = [];

    function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
        });
    }

    function highlight(text, q) {
        if (!q) return escapeHtml(text);
        var i = norm(text).indexOf(norm(q));
        if (i === -1) return escapeHtml(text);
        var before = text.substring(0, i);
        var match  = text.substring(i, i + q.length);
        var after  = text.substring(i + q.length);
        return escapeHtml(before) + '<mark>' + escapeHtml(match) + '</mark>' + escapeHtml(after);
    }

    function buildList(q) {
        var nq = norm(q);
        var matches = nq === ''
            ? keys.slice(0, 100)
            : keys.filter(function (k) { return norm(k).indexOf(nq) !== -1; });

        matches.sort(function (a, b) {
            var na = norm(a), nb = norm(b);
            if (nq !== '') {
                var ai = na.indexOf(nq), bi = nb.indexOf(nq);
                if (ai !== bi) return ai - bi;
            }
            return a.localeCompare(b);
        });

        var exact = nq !== '' && keys.some(function (k) { return norm(k) === nq; });
        current = matches.slice();

        var html = '';
        current.forEach(function (k, idx) {
            html += '<li class="combobox-option" role="option" data-value="' + escapeHtml(k) + '" id="pk-opt-' + idx + '">' + highlight(k, q) + '</li>';
        });
        if (nq !== '' && !exact) {
            html += '<li class="combobox-option combobox-new" role="option" data-value="' + escapeHtml(q) + '" id="pk-opt-new">＋ Créer la clé « <strong>' + escapeHtml(q) + '</strong> »</li>';
            current.push(q);
        }
        if (current.length === 0) {
            html = '<li class="combobox-empty">Aucun produit. Saisis un nom pour en créer un.</li>';
        }

        listbox.innerHTML = html;
        active = -1;
        var total = matches.length;
        countEl.textContent = total + ' produit' + (total > 1 ? 's' : '');
        countEl.hidden = total === 0;

        Array.prototype.forEach.call(listbox.querySelectorAll('.combobox-option'), function (li) {
            li.addEventListener('mousedown', function (e) { e.preventDefault(); selectOption(li); });
        });
    }

    function open(q) {
        buildList(q || input.value);
        listbox.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }
    function close() {
        listbox.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        active = -1;
    }

    function setActive(idx) {
        var opts = listbox.querySelectorAll('.combobox-option');
        opts.forEach(function (o) { o.classList.remove('is-active'); });
        if (idx < 0) { active = -1; return; }
        if (idx >= opts.length) idx = opts.length - 1;
        active = idx;
        if (opts[idx]) {
            opts[idx].classList.add('is-active');
            opts[idx].scrollIntoView({ block: 'nearest' });
        }
    }

    function selectOption(li) {
        input.value = li.getAttribute('data-value') || '';
        close();
        input.focus();
    }

    input.addEventListener('focus', function () { open(''); });
    input.addEventListener('input', function () { open(input.value); });
    input.addEventListener('blur', function () { setTimeout(close, 150); });

    input.addEventListener('keydown', function (e) {
        var opts = listbox.querySelectorAll('.combobox-option');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (listbox.hidden) { open(input.value); }
            setActive(Math.min(active + 1, opts.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(Math.max(active - 1, 0));
        } else if (e.key === 'Enter') {
            if (!listbox.hidden && active >= 0 && opts[active]) {
                e.preventDefault();
                selectOption(opts[active]);
            }
        } else if (e.key === 'Escape') {
            close();
        }
    });
})();
</script>
