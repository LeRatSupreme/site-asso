<?php

declare(strict_types=1);

/**
 * Page dédiée « Ajouter une appellation » : saisie en série du lien
 * libellé facture/CSV -> nom court. Grille multi-lignes : une ligne
 * par appellation, tout est enregistré d'un coup (Entrée = champ
 * suivant, nouvelle ligne automatique en bas). La liste complète
 * reste consultable et éditable dans la page Mapping des libellés.
 *
 * @var list<string> $categories
 * @var list<string> $products
 * @var list<array<string,mixed>> $pending
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Ajouter une appellation</h1>
    </div>
</div>

<div class="compta-grid">
    <section class="card surface glass">
        <h2 class="card-title">Nouvelles appellations</h2>
        <p class="muted">Retient une fois pour toutes le lien entre un <strong>libellé long</strong> et le <strong>nom court</strong> utilisé par l'asso : « MONSTER PIPELINE PUNCH » → « Monster rose ». Les appellations servent aux imports CSV SumUp <em>et</em> aux factures scannées (METRO…) : au prochain scan du même libellé, le nom court est proposé directement.</p>
        <p class="muted"><strong>Astuce :</strong> une ligne par appellation, <kbd>Entrée</kbd> pour passer au champ suivant (nouvelle ligne automatique en bas) — enregistre tout d'un coup. Les lignes incomplètes sont ignorées.</p>

        <form method="post" action="<?= e(url('/admin/compta/aliases/save-bulk')) ?>" id="alias-bulk-new">
            <?= csrf_field() ?>

            <div class="alias-bulk-head">
                <span>Libellé d'origine (facture / CSV)</span>
                <span>Nom court (produit)</span>
                <span>Catégorie (optionnel)</span>
                <span></span>
            </div>
            <div id="alias-rows">
                <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="alias-line">
                        <input type="text" name="raw_description[]" placeholder="ex : MONSTER PIPELINE PUNCH 24X0,5L" autocomplete="off">
                        <input type="text" name="product_key[]" list="alias-products" placeholder="ex : Monster rose" autocomplete="off">
                        <input type="text" name="category[]" list="alias-categories" placeholder="ex : Boissons" autocomplete="off">
                        <button type="button" class="btn btn-ghost btn-sm alias-del" title="Vider la ligne">✕</button>
                    </div>
                <?php endfor; ?>
            </div>
            <template id="alias-row-tpl">
                <div class="alias-line">
                    <input type="text" name="raw_description[]" placeholder="ex : MONSTER PIPELINE PUNCH 24X0,5L" autocomplete="off">
                    <input type="text" name="product_key[]" list="alias-products" placeholder="ex : Monster rose" autocomplete="off">
                    <input type="text" name="category[]" list="alias-categories" placeholder="ex : Boissons" autocomplete="off">
                    <button type="button" class="btn btn-ghost btn-sm alias-del" title="Vider la ligne">✕</button>
                </div>
            </template>

            <div class="form-actions">
                <button type="button" class="btn btn-outline" id="alias-add">＋ 5 lignes</button>
                <span class="muted"><span id="alias-count">0</span> ligne(s) remplie(s)</span>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer tout</button>
                <a class="btn btn-ghost" href="<?= e(url('/admin/compta/aliases')) ?>">Voir toutes les appellations</a>
            </div>
        </form>

        <datalist id="alias-products">
            <?php foreach (($products ?? []) as $p): ?>
                <option value="<?= e((string) $p) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <datalist id="alias-categories">
            <?php foreach ($categories as $cat): ?>
                <option value="<?= e((string) $cat) ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </section>

    <section class="card surface glass">
        <h2 class="card-title">Libellés factures non rattachés</h2>
        <p class="muted">Libellés détectés par les scans de factures (METRO…) mais inconnus du catalogue — ex. « EVIEN 50CL » qui devrait être « Eau ». Rattache-les ici : au prochain scan du même libellé, le nom court sera proposé directement. Les libellés déjà résolus disparaissent de la file tout seuls.</p>
        <?php if (($pending ?? []) === []): ?>
            <p class="muted">Aucun libellé en attente : tous les libellés scannés sont reconnus.</p>
        <?php endif; ?>
        <?php foreach (($pending ?? []) as $row): ?>
            <?php $label = (string) $row['label']; ?>
            <form method="post" action="<?= e(url('/admin/compta/aliases/save')) ?>" class="alias-form">
                <?= csrf_field() ?>
                <input type="hidden" name="stay" value="1">
                <div class="alias-row">
                    <div>
                        <code><?= e($label) ?></code>
                        <span class="muted">(<?= (int) $row['occurrences'] ?> fois)</span>
                    </div>
                    <div>
                        <input type="hidden" name="raw_description" value="<?= e($label) ?>">
                        <input type="text" name="product_key" placeholder="ex : Eau" required autocomplete="off" list="alias-products">
                        <input type="text" name="category" placeholder="ex : <?= e($categories[0] ?? 'Catégorie') ?>" list="alias-categories" autocomplete="off">
                        <button type="submit" class="btn btn-primary btn-sm">Rattacher</button>
                    </div>
                </div>
            </form>
        <?php endforeach; ?>
    </section>
</div>

<style>
    .alias-bulk-head,
    .alias-line {
        display: grid;
        grid-template-columns: minmax(220px, 2fr) minmax(140px, 1fr) minmax(110px, 1fr) auto;
        gap: 0.5rem;
        align-items: center;
    }
    .alias-bulk-head {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        opacity: 0.7;
        padding: 0 0.25rem 0.35rem;
    }
    #alias-rows {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        max-height: 60vh;
        overflow-y: auto;
        padding-right: 0.15rem;
    }
    .alias-line input[type="text"] {
        width: 100%;
        margin: 0;
    }
    .alias-line .alias-del {
        padding: 0.3rem 0.55rem;
    }
    kbd {
        background: rgba(127, 127, 127, 0.18);
        border-radius: 4px;
        padding: 0.05rem 0.35rem;
        font-size: 0.85em;
    }
</style>

<script>
(function () {
    var grid = document.getElementById('alias-rows');
    var tpl = document.getElementById('alias-row-tpl');
    var count = document.getElementById('alias-count');
    if (!grid || !tpl) return;

    function rows() { return grid.querySelectorAll('.alias-line'); }
    function textInputs(row) { return row.querySelectorAll('input[type="text"]'); }

    function update() {
        var n = 0;
        rows().forEach(function (row) {
            var filled = false;
            textInputs(row).forEach(function (inp) {
                if (inp.value.trim() !== '') filled = true;
            });
            row.classList.toggle('is-filled', filled);
            if (filled) n++;
        });
        if (count) count.textContent = n;
    }

    function addRow(focus) {
        var row = tpl.content.firstElementChild.cloneNode(true);
        grid.appendChild(row);
        grid.scrollTop = grid.scrollHeight;
        if (focus) textInputs(row)[0].focus();
        update();
        return row;
    }

    document.getElementById('alias-add').addEventListener('click', function () {
        for (var i = 0; i < 5; i++) addRow(i === 0);
    });

    grid.addEventListener('input', update);

    grid.addEventListener('click', function (e) {
        var btn = e.target.closest('.alias-del');
        if (!btn) return;
        var row = btn.closest('.alias-line');
        if (rows().length === 1) {
            textInputs(row).forEach(function (inp) { inp.value = ''; });
        } else {
            row.remove();
        }
        update();
    });

    // Entrée : champ suivant ; en bas de grille, nouvelle ligne.
    grid.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') return;
        e.preventDefault();
        var inputs = Array.prototype.slice.call(grid.querySelectorAll('input[type="text"]'));
        var i = inputs.indexOf(e.target);
        if (i > -1 && i < inputs.length - 1) {
            inputs[i + 1].focus();
        } else {
            addRow(true);
        }
    });

    update();
})();
</script>
