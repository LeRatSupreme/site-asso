<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $aliases
 * @var list<array<string,mixed>> $unmapped
 * @var list<string> $categories
 * @var list<string> $known_keys
 */
?>
<datalist id="alias-categories">
    <?php foreach ($categories as $cat): ?>
        <option value="<?= e((string) $cat) ?>"></option>
    <?php endforeach; ?>
</datalist>
<div class="compta-grid">
    <div class="card surface glass">
        <h2 class="card-title">File « à classer »</h2>
        <p class="muted">Libellés CSV rencontrés non encore rattachés à un produit canonique. Rattachez-les une fois pour toutes : ils seront résolus automatiquement aux prochains imports. Les catégories proposées sont celles de la page Catégories (cafétéria).</p>
        <p class="admin-actions">
            <a class="btn btn-primary" href="<?= e(url('/admin/compta/aliases/new')) ?>">＋ Ajouter une appellation</a>
            <a class="btn btn-outline" href="<?= e(url('/admin/compta/aliases/auto')) ?>">Auto-détecter les doublons</a>
        </p>
        <?php if ($unmapped === []): ?>
            <p class="muted">Aucun libellé à classer. </p>
        <?php endif; ?>
        <?php foreach ($unmapped as $u): ?>
            <form method="post" action="<?= e(url('/admin/compta/aliases/save')) ?>" class="alias-form">
                <?= csrf_field() ?>
                <div class="alias-row">
                    <div>
                        <code><?= e((string) $u['description']) ?></code>
                        <span class="muted">(<?= e((string) $u['occurrences']) ?> fois)</span>
                    </div>
                    <div>
                        <input type="hidden" name="raw_description" value="<?= e((string) $u['description']) ?>">
                        <input type="text" name="product_key" placeholder="ex: Bueno" required>
                        <input type="text" name="category" placeholder="ex: <?= e($categories[0] ?? 'Catégorie') ?>" list="alias-categories">
                        <button type="submit" class="btn btn-primary btn-sm">Rattacher</button>
                    </div>
                </div>
            </form>
        <?php endforeach; ?>
    </div>

    <div class="card surface glass table-wrap">
        <h2 class="card-title">Alias existants</h2>
        <form id="alias-bulk-form" method="post" action="<?= e(url('/admin/compta/aliases/bulk')) ?>" data-preserve-scroll>
            <?= csrf_field() ?>
        </form>
        <p class="admin-actions">
            <button type="submit" class="btn btn-primary btn-sm" form="alias-bulk-form">Enregistrer les catégories</button>
            <span class="muted">Modifie plusieurs catégories puis enregistre tout d'un coup — les ventes correspondantes sont mises à jour.</span>
        </p>
        <table class="table">
            <thead><tr><th>Libellé d'origine</th><th>Produit canonique</th><th>Catégorie</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($aliases as $a): ?>
                    <tr>
                        <td><code><?= e((string) $a['raw_description']) ?></code></td>
                        <td><strong><?= e((string) $a['product_key']) ?></strong></td>
                        <td>
                            <input type="hidden" name="bulk_raw[]" value="<?= e((string) $a['raw_description']) ?>" form="alias-bulk-form">
                            <input type="text" name="bulk_cat[]" value="<?= e((string) ($a['category'] ?? '')) ?>" placeholder="ex: <?= e($categories[0] ?? 'Catégorie') ?>" list="alias-categories" style="width:130px" form="alias-bulk-form">
                        </td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/compta/aliases/' . rawurlencode((string) $a['id']) . '/delete')) ?>" data-confirm="Supprimer cet alias ?" data-preserve-scroll>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($aliases === []): ?>
                    <tr><td colspan="4" class="muted">Aucun alias défini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="admin-actions">
            <button type="submit" class="btn btn-primary btn-sm" form="alias-bulk-form">Enregistrer les catégories</button>
        </p>
    </div>
</div>

<div class="card surface glass merge-products-card">
    <h2 class="card-title">Fusionner deux produits</h2>
    <p class="muted">Même produit sous deux noms (ex. « Cristaline » et « Eau ») ? Rattache tout l'historique du produit absorbé au produit conservé : ventes, achats, pertes, alias, comptages, coûts, stocks, drapeaux, promotions, fiches carte. Action irréversible.</p>
    <form id="merge-products-form" method="post" action="<?= e(url('/admin/compta/aliases/merge')) ?>"
          data-confirm="Fusionner définitivement ces deux produits ? Cette action est irréversible."
          data-confirm-button="Fusionner" class="merge-products-form">
        <?= csrf_field() ?>
        <div class="merge-products-fields">
            <div class="field">
                <label for="merge-products-from">Produit absorbé</label>
                <input type="text" id="merge-products-from" name="from" list="merge-products-keys"
                       placeholder="ex: Cristaline" maxlength="255" required>
            </div>
            <div class="field">
                <label for="merge-products-to">Produit conservé</label>
                <input type="text" id="merge-products-to" name="to" list="merge-products-keys"
                       placeholder="ex: Eau" maxlength="255" required>
            </div>
            <button type="submit" class="btn btn-destructive merge-products-submit">Fusionner</button>
        </div>
    </form>
    <datalist id="merge-products-keys">
        <?php foreach ($known_keys as $k): ?>
            <option value="<?= e((string) $k) ?>"></option>
        <?php endforeach; ?>
    </datalist>
</div>

<script>
/* Confirmation nominative : le message reprend les deux champs saisis.
   Écouteur posé sur le formulaire : il s'exécute AVANT le handler global
   data-confirm (posé sur document), qui lit alors le message à jour. */
(function () {
    var form = document.getElementById('merge-products-form');
    var from = document.getElementById('merge-products-from');
    var to = document.getElementById('merge-products-to');
    if (!form || !from || !to) return;
    form.addEventListener('submit', function () {
        form.setAttribute('data-confirm',
            'Fusionner définitivement « ' + (from.value || '').trim() +
            ' » dans « ' + (to.value || '').trim() + ' » ? Cette action est irréversible.');
    });
})();
</script>

<style>
    .merge-products-card { margin-top: 18px; }
    .merge-products-fields { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
    .merge-products-fields .field { flex: 1 1 220px; }
    .merge-products-fields input[type="text"] { width: 100%; }
    .merge-products-submit { flex: 0 0 auto; }
</style>
