<?php

declare(strict_types=1);

/**
 * Auto-détection des doublons de libellés.
 *
 * Deux sections (contrat AdminComptaController::autoMappingRows()) :
 *  - « À mapper » : rows NON handled — formulaire éditable inchangé
 *    (mêmes noms de champs, mêmes indices d'origine) ;
 *  - « Déjà couverts » : rows handled, regroupées par clé résolue —
 *    lecture seule, AUCUN champ dans le POST d'application.
 *
 * @var list<array<string,mixed>> $rows
 * @var list<array<string,mixed>> $covered
 * @var list<string> $productKeys
 */
?>
<div class="card surface glass">
    <h2 class="card-title">Suggestions de mapping</h2>
    <p class="muted">
        Chaque libellé CSV rencontré est regroupé vers une <strong>clé canonique
        suggérée</strong> (normalisation : minuscules, accents retirés, séparateurs
        uniformisés, suffixes de couleur/gout retirés). L'écran est scindé en deux
        sections : <strong>À mapper</strong> — colonne « Produit canonique »
        <strong>éditable</strong>, corrigez avant d'appliquer — et <strong>Déjà
        couverts</strong> — libellés qui résolvent déjà vers une clé (lecture
        seule). Les libellés déjà aliasés sont signalés ; modifier leur clé
        canonique déclenche une <strong>fusion complète</strong> — ventes, achats,
        coûts de revient (lots), stock, packs et historiques de l'ancienne clé sont
        transférés vers la nouvelle <strong>sans perte</strong>.
    </p>

    <?php if ($rows === []): ?>
        <p class="muted">Aucune vente à analyser. Importez d'abord un rapport SumUp.</p>
    <?php else: ?>
        <?php
        // Rows à mapper = NON handled : une ligne handled est déjà couverte
        // (résolution alias exact ou repli normalisé) — rendu readonly dans
        // « Déjà couverts », exclue du POST d'application. Les indices
        // d'ORIGINE ($i sur $rows complet) sont conservés : le formulaire
        // et aliasesApply() restent strictement inchangés.
        $pendingIdx = [];
        foreach ($rows as $i => $r) {
            if (empty($r['handled'])) {
                $pendingIdx[] = $i;
            }
        }
        ?>
        <!-- Cibles proposées : clés produits EXISTANTES (fusion vers une
             clé connue = zéro doublon créé ; la saisie libre reste possible). -->
        <datalist id="product-keys">
            <?php foreach ($productKeys as $pk): ?>
            <option value="<?= e($pk) ?>"></option>
            <?php endforeach; ?>
        </datalist>

        <h3 class="mapping-section-title">À mapper</h3>
        <?php if ($pendingIdx === []): ?>
            <p class="muted">Rien à mapper — tous les libellés sont couverts.</p>
        <?php else: ?>
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
                        <?php foreach ($pendingIdx as $i): ?>
                            <?php $r = $rows[$i]; ?>
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

        <?php if ($covered !== []): ?>
            <h3 class="mapping-section-title">Déjà couverts</h3>
            <p class="muted">
                Lecture seule : ces libellés résolvent déjà vers leur clé canonique
                (alias exact ou repli normalisé) — aucune action, ils ne figurent
                pas dans le formulaire ci-dessus.
            </p>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Clé canonique</th>
                            <th>Occurrences</th>
                            <th>Libellés sources</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($covered as $g): ?>
                            <?php
                            $resolvedTo = (string) ($g['resolved_to'] ?? '');
                            if ($resolvedTo === '') {
                                continue;
                            }
                            $gLabels = is_array($g['labels'] ?? null) ? $g['labels'] : [];
                            ?>
                            <tr class="row-muted">
                                <td>
                                    <span class="badge badge-info">résout vers</span>
                                    <strong><?= e($resolvedTo) ?></strong>
                                </td>
                                <td><?= (int) ($g['occurrences'] ?? 0) ?></td>
                                <td>
                                    <?php if ($gLabels === []): ?>
                                        <span class="muted">—</span>
                                    <?php else: ?>
                                        <details class="covered-sources">
                                            <summary><?= count($gLabels) ?> libellé(s)</summary>
                                            <ul>
                                                <?php foreach ($gLabels as $l): ?>
                                                    <?php $via = (string) ($l['via'] ?? ''); ?>
                                                    <li>
                                                        <code><?= e((string) ($l['label'] ?? '')) ?></code>
                                                        <span class="muted">(<?= (int) ($l['occurrences'] ?? 0) ?> fois)</span>
                                                        <?php if ($via !== ''): ?>
                                                            <span class="badge badge-<?= $via === 'alias' ? 'success' : 'muted' ?>"><?= e($via) ?></span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.fusion-note {
    margin-top: 0.3rem; font-size: 0.75rem; line-height: 1.35;
    color: #fbbf24;
}
.fusion-note strong { color: #fbbf24; }
tr.is-fusion > td { border-top-color: rgba(251, 191, 36, 0.35); }
.mapping-section-title { font-size: 0.95rem; margin: 1.25rem 0 0.55rem; }
.covered-sources summary { cursor: pointer; }
.covered-sources ul { margin: 0.35rem 0 0.2rem 1.1rem; list-style: disc; }
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
