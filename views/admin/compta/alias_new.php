<?php

declare(strict_types=1);

/**
 * Page dédiée « Ajouter une appellation » : saisie en série du lien
 * libellé facture/CSV -> nom court. Grille multi-lignes : une ligne
 * par appellation, tout est enregistré d'un coup (Entrée = champ
 * suivant, nouvelle ligne automatique en bas). Y arrivent PRÉ-REMPLIS
 * les libellés extraits des scans de factures (un par libellé en
 * file) ET les fiches carte au nom de facture (« OASIS TROPICAL SLIM
 * BTE 33CL »…) : il ne reste qu'à saisir le nom court — à
 * l'enregistrement, la fiche longue est fusionnée automatiquement
 * dans le produit court. Les appellations enregistrées sont listées
 * EN BAS de cette page, leur NOM COURT y est modifiable sur place
 * (repointage via /admin/compta/aliases/apply : ventes, coûts et
 * stocks de l'ancienne cible suivent, sans perte) ; « Mapping des
 * libellés » reste réservé aux alias produits courts.
 *
 * @var list<string> $categories
 * @var list<string> $products
 * @var list<array{label:string, occurrences:int, hint:string, suggested:string, category:string}> $pendingRows
 * @var list<array<string,mixed>> $appellations
 */
?><?php
// Datalist « noms courts » du tableau Appellations enregistrées :
// connus du contrôleur (known_keys = ProductAlias::knownProductKeys())
// s'il les fournit, sinon repli sur les produits déjà vendus
// ($products — même assemblage que la grille « Nouvelles
// appellations » du haut de la page).
$knownKeys = $known_keys ?? $products ?? [];
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
                <?php foreach (($pendingRows ?? []) as $row): ?>
                    <?php
                        $label = (string) $row['label'];
                        $isFiche = ($row['hint'] ?? 'scan') === 'fiche';
                        $occ = max(0, (int) ($row['occurrences'] ?? 0));
                        $hint = $isFiche ? 'fiche produit' : 'scanné ×' . max(1, $occ);
                        $title = $isFiche
                            ? 'Fiche produit existante au nom long — saisis le nom court : à l\'enregistrement, la fiche longue est fusionnée automatiquement dans le produit court.'
                            : 'Libellé extrait d\'un scan de facture (' . $occ . ' fois) — saisis le nom court à droite.';
                    ?>
                    <div class="alias-line">
                        <div class="alias-raw">
                            <input type="text" name="raw_description[]" value="<?= e($label) ?>" readonly title="<?= e($title) ?>">
                            <span class="alias-hint"><?= e($hint) ?></span>
                        </div>
                        <input type="text" name="product_key[]" list="alias-products" value="<?= e((string) ($row['suggested'] ?? '')) ?>" placeholder="ex : Eau" autocomplete="off">
                        <input type="text" name="category[]" list="alias-categories" value="<?= e((string) ($row['category'] ?? '')) ?>" placeholder="ex : Boissons" autocomplete="off">
                        <button type="button" class="btn btn-ghost btn-sm alias-del" title="Retirer la ligne">✕</button>
                    </div>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < 3; $i++): ?>
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
                <a class="btn btn-ghost" href="<?= e(url('/admin/compta/aliases')) ?>">Mapping des libellés (produits)</a>
            </div>
        </form>

        <?php if (($appellations ?? []) !== []): ?>
            <h2 class="card-title" style="margin-top:1.5rem">Appellations enregistrées <span class="muted" style="font-size:0.8rem">(<?= count($appellations) ?>)</span></h2>
            <form id="appellation-repoint-form" method="post" action="<?= e(url('/admin/compta/aliases/apply')) ?>">
                <?= csrf_field() ?>
            </form>
            <p class="muted">Le <strong>nom court</strong> est modifiable ligne par ligne : à l'enregistrement, l'appellation est repointée vers le nouveau produit et les ventes correspondantes suivent — les coûts et stocks de l'ancien nom sont fusionnés sans perte (même mécanisme que « Auto-détecter les doublons »).</p>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm" form="appellation-repoint-form">Enregistrer les noms courts</button>
                <span class="muted">Un seul bouton pour tout le tableau ; la confirmation s'affiche ensuite sur « Mapping des libellés ».</span>
            </div>
            <div class="table-wrap">
                <table class="table" id="appellation-table">
                    <thead><tr><th>Libellé d'origine</th><th>Nom court</th><th>Catégorie</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($appellations as $i => $a): ?>
                            <tr>
                                <td><code><?= e((string) $a['raw_description']) ?></code></td>
                                <td>
                                    <input type="hidden" name="raw[<?= (int) $i ?>]" value="<?= e((string) $a['raw_description']) ?>" form="appellation-repoint-form">
                                    <input type="text" name="canonical[<?= (int) $i ?>]" value="<?= e((string) $a['product_key']) ?>" list="appellation-keys" maxlength="255" required autocomplete="off" class="alias-key-input" form="appellation-repoint-form" aria-label="Nom court de « <?= e((string) $a['raw_description']) ?> » (modifier pour repointer l'appellation)">
                                </td>
                                <td><?= e((string) ($a['category'] ?? '')) ?></td>
                                <td>
                                    <form method="post" action="<?= e(url('/admin/compta/aliases/' . rawurlencode((string) $a['id']) . '/delete')) ?>" data-confirm="Supprimer cette appellation ?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="back" value="new">
                                        <button type="submit" class="btn btn-destructive btn-sm">Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm" form="appellation-repoint-form">Enregistrer les noms courts</button>
            </div>
            <p class="muted">Ces libellés longs restent actifs pour les imports CSV et les scans ; ils n'apparaissent plus dans « Mapping des libellés » (réservé aux produits). Ressaisis un libellé ci-dessus pour le replacer ici, ou modifie son nom court dans le tableau pour le repointer sur un autre produit.</p>
        <?php endif; ?>

        <p class="muted">Les libellés scannés et les fiches au nom long arrivent ici ; saisis le nom court — à l'enregistrement, la fiche longue est fusionnée automatiquement dans le produit court.</p>

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
        <datalist id="appellation-keys">
            <?php foreach ($knownKeys as $k): ?>
                <option value="<?= e((string) $k) ?>"></option>
            <?php endforeach; ?>
        </datalist>
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
    /* Libellé pré-rempli (scan ou fiche au nom long) : lecture seule,
       discret, avec son badge d'origine à côté. */
    .alias-line input[type="text"][readonly] {
        opacity: 0.85;
        cursor: default;
    }
    .alias-raw {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        min-width: 0;
    }
    .alias-raw input[type="text"] {
        flex: 1;
        min-width: 0;
    }
    .alias-hint {
        font-size: 0.72rem;
        opacity: 0.65;
        white-space: nowrap;
        border: 1px solid rgba(127, 127, 127, 0.35);
        border-radius: 999px;
        padding: 0.05rem 0.45rem;
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
    /* Cellule « Nom court » devenue éditable dans le tableau
       Appellations enregistrées : même famille que .alias-label-input
       de la page Mapping (monospace, largeur bornée) — repointage en
       masse vers /admin/compta/aliases/apply. */
    .alias-key-input {
        width: 170px;
        max-width: 100%;
        padding: 0.35rem 0.5rem;
        border: 1px solid var(--border, rgba(255, 255, 255, 0.12));
        border-radius: var(--radius-sm, 6px);
        background: rgba(255, 255, 255, 0.04);
        color: var(--foreground, inherit);
        font-family: ui-monospace, 'SF Mono', Consolas, monospace;
        font-size: 0.85rem;
    }
    .alias-key-input:focus,
    .alias-key-input.is-changed {
        outline: none;
        border-color: var(--primary, #48bdd3);
        box-shadow: 0 0 0 3px rgba(72, 189, 211, 0.18);
    }
    @media (max-width: 700px) {
        /* Leçon mobile : champs ≥16px sinon zoom auto iOS Safari. */
        #appellation-table input[type="text"] { font-size: 16px; }
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
        if (row.querySelector('input[readonly]')) {
            // Ligne pré-remplie (scan ou fiche au nom long) : le libellé
            // est en lecture seule — on RETIRE la ligne (pas de « vidage »
            // possible), et la grille ne reste jamais vide.
            row.remove();
            if (rows().length === 0) addRow(false);
        } else if (rows().length === 1) {
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

/* Tableau « Appellations enregistrées » : surligne en direct les noms
   courts modifiés et demande une confirmation explicite avant le
   repointage (l'ancienne clé produit est absorbée : ventes, coûts et
   stocks transférés — même garde-fou que la page Auto-détection). */
(function () {
    'use strict';

    var form = document.getElementById('appellation-repoint-form');
    if (!form) return;

    var inputs = Array.prototype.slice.call(form.querySelectorAll('input[name^="canonical"]'))
        .filter(function (input) { return input.type === 'text'; });

    function planned() {
        return inputs
            .filter(function (input) {
                return input.value.trim() !== (input.getAttribute('data-initial') || '').trim();
            })
            .map(function (input) {
                var tr = input.closest('tr');
                return {
                    from: input.getAttribute('data-initial') || '',
                    to: input.value.trim(),
                    raw: tr ? (tr.querySelector('code') ? tr.querySelector('code').textContent : '') : ''
                };
            });
    }

    inputs.forEach(function (input) {
        input.setAttribute('data-initial', input.value);
        input.addEventListener('input', function () {
            input.classList.toggle('is-changed',
                input.value.trim() !== (input.getAttribute('data-initial') || '').trim());
        });
    });

    form.addEventListener('submit', function (e) {
        var changes = planned();
        if (changes.length === 0) return;
        var lines = changes.map(function (c) {
            return '  • « ' + c.from + ' » → « ' + c.to + ' » (libellé : ' + c.raw + ')';
        }).join('\n');
        var msg = changes.length + ' nom(s) court(s) modifié(s) :\n' + lines
            + '\n\nLes ventes de ces libellés suivront le nouveau produit, et les coûts/stocks de l\'ancien nom seront fusionnés sans perte.\nConfirmer ?';
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });
})();
</script>
