<?php

declare(strict_types=1);

/**
 * Auto-détection des doublons de libellés.
 *
 * @var list<array<string,mixed>> $rows
 * @var list<string> $productKeys
 */
?>
<div class="card surface glass">
    <h2 class="card-title">Suggestions de mapping</h2>
    <p class="muted">
        Chaque libellé CSV rencontré est regroupé vers une <strong>clé canonique
        suggérée</strong> (normalisation : minuscules, accents retirés, séparateurs
        uniformisés, suffixes de couleur/gout retirés). La colonne « Produit
        canonique » est <strong>éditable</strong> : corrigez avant d'appliquer.
        Les libellés déjà aliasés sont signalés ; modifier leur clé canonique
        déclenche une <strong>fusion complète</strong> — ventes, achats, coûts
        de revient (lots), stock, packs et historiques de l'ancienne clé sont
        transférés vers la nouvelle <strong>sans perte</strong>.
    </p>

    <?php if ($rows === []): ?>
        <p class="muted">Aucune vente à analyser. Importez d'abord un rapport SumUp.</p>
    <?php else: ?>
        <!-- Cibles proposées : clés produits EXISTANTES (fusion vers une
             clé connue = zéro doublon créé ; la saisie libre reste possible). -->
        <datalist id="product-keys">
            <?php foreach ($productKeys as $pk): ?>
            <option value="<?= e($pk) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <form method="post" action="<?= e(url('/admin/compta/aliases/apply')) ?>" id="alias-apply-form">
            <?= csrf_field() ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Libellé CSV</th>
                            <th>Occurrences</th>
                            <th>Suggestion auto</th>
                            <th>Produit canonique (éditable)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $i => $r): ?>
                            <tr<?= !empty($r['already']) ? ' class="row-muted"' : '' ?>>
                                <td>
                                    <code><?= e((string) $r['raw']) ?></code>
                                    <?php if (!empty($r['already'])): ?>
                                        <span class="badge badge-muted">déjà mappé</span>
                                    <?php endif; ?>
                                    <input type="hidden" name="raw[<?= (int) $i ?>]" value="<?= e((string) $r['raw']) ?>">
                                </td>
                                <td><?= e((string) $r['occurrences']) ?></td>
                                <td><span class="muted"><?= e((string) $r['suggested']) ?></span></td>
                                <td>
                                    <input type="text" name="canonical[<?= (int) $i ?>]"
                                           value="<?= e((string) $r['canonical']) ?>"
                                           placeholder="<?= e((string) $r['suggested']) ?>"
                                           list="product-keys"
                                           data-current="<?= e((string) $r['canonical']) ?>"
                                           data-raw="<?= e((string) $r['raw']) ?>"
                                           data-already="<?= !empty($r['already']) ? '1' : '0' ?>">
                                    <div class="fusion-note" hidden></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="admin-actions">
                <button type="submit" class="btn btn-primary">Appliquer le mapping</button>
                <a class="btn btn-outline" href="<?= e(url('/admin/compta/aliases')) ?>">Annuler</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<style>
.fusion-note {
    margin-top: 0.3rem; font-size: 0.75rem; line-height: 1.35;
    color: #fbbf24;
}
.fusion-note strong { color: #fbbf24; }
tr.is-fusion > td { border-top-color: rgba(251, 191, 36, 0.35); }
</style>

<script>
(function () {
    'use strict';

    var form = document.getElementById('alias-apply-form');
    if (!form) return;

    /* Une FUSION = libellé déjà mappé dont la clé canonique change :
       l'ancienne clé sera absorbée (ventes, achats, coûts de revient,
       stock, packs, historiques — sans perte). On la signale en live. */
    function fusions() {
        var planned = [];
        Array.prototype.forEach.call(form.querySelectorAll('input[name^="canonical"]'), function (input) {
            if (input.getAttribute('data-already') !== '1') return;
            var current = input.getAttribute('data-current') || '';
            var next = input.value.trim();
            if (next === '' || next === current) return;
            planned.push({ raw: input.getAttribute('data-raw') || '', from: current, to: next });
        });
        return planned;
    }

    function refresh() {
        Array.prototype.forEach.call(form.querySelectorAll('input[name^="canonical"]'), function (input) {
            var tr = input.closest('tr');
            var note = tr ? tr.querySelector('.fusion-note') : null;
            var isFusion = input.getAttribute('data-already') === '1'
                && input.value.trim() !== ''
                && input.value.trim() !== (input.getAttribute('data-current') || '');
            if (tr) tr.classList.toggle('is-fusion', isFusion);
            if (note) {
                if (isFusion) {
                    note.hidden = false;
                    note.innerHTML = '🔁 <strong>Fusion</strong> : « ' + escapeHtml(input.getAttribute('data-current') || '')
                        + ' » sera absorbée dans « ' + escapeHtml(input.value.trim())
                        + ' » — ventes, achats, coûts de revient (lots), stock, packs et historiques transférés sans perte.';
                } else {
                    note.hidden = true;
                    note.textContent = '';
                }
            }
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    form.addEventListener('input', refresh);
    refresh();

    /* Confirmation explicite AVANT d'appliquer : liste des fusions
       prévues (ancienne clé → nouvelle clé), pour éviter l'erreur
       irréversible de cible mal choisie. */
    form.addEventListener('submit', function (e) {
        var planned = fusions();
        if (planned.length === 0) return;
        var lines = planned.map(function (f) {
            return '  • « ' + f.from + ' » → « ' + f.to + ' » (libellé : ' + f.raw + ')';
        }).join('\n');
        var msg = planned.length + ' fusion(s) de clé(s) vont être appliquées :\n' + lines
            + '\n\nToutes les données (ventes, achats, coûts de revient, stock, packs) seront transférées sans perte.\nConfirmer ?';
        if (!window.confirm(msg)) {
            e.preventDefault();
        }
    });
})();
</script>
