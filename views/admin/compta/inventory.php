<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var list<array{key:string,stock:?int,counted_at:?string,counted_qty:?int,gap:?int,theoretical:?int}> $rows
 * @var list<array{key:string,counted_at:?string,counted_qty:?int,gap:?int,theoretical:?int}> $discontinuedRows
 * @var list<array<string,mixed>> $history
 * @var list<array<string,mixed>> $gaps
 * @var array<string, array{sales:int, purchases:int, losses:int, counts:int, costs:int, aliases:int, stock:?int, discontinued:bool}> $keyStats
 * @var list<list<string>> $mergeDupes
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Inventaire</h1>
        <p class="muted">Compte le stock <strong>physique</strong> et compare au <strong>théorique</strong> (dernier comptage + achats − ventes). Un écart = perte, casse, offert ou erreur de saisie.</p>
    </div>
</div>

<?php $gapTotal = count($gaps); $gapShown = min(6, $gapTotal); ?>
<?php if ($gaps !== []): ?>
<section class="card surface glass">
    <style>
        .ecarts-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; }
        .ecarts-count { font-size: 0.75rem; color: var(--muted, #8892a6); font-weight: 600; }
        .table-ecarts td { padding: 0.35rem 0.55rem; font-size: 0.85rem; }
        .table-ecarts th { font-size: 0.72rem; }
        .ecarts-scroll.ecarts-open { max-height: 320px; overflow-y: auto; }
    </style>
    <div class="ecarts-head">
        <h2 class="card-title">Écarts détectés (30 derniers jours) <span class="ecarts-count">(<?= $gapTotal ?>)</span></h2>
        <?php if ($gapTotal > $gapShown): ?>
            <button type="button" class="btn btn-ghost btn-sm" id="ecarts-toggle"
                    onclick="(function (b) { var m = document.getElementById('ecarts-more'); var s = document.getElementById('ecarts-scroll'); var open = m.hidden; m.hidden = !open; s.classList.toggle('ecarts-open', open); b.textContent = open ? 'Réduire' : 'Voir tout (<?= $gapTotal ?>)'; })(this)">Voir tout (<?= $gapTotal ?>)</button>
        <?php endif; ?>
    </div>
    <div class="table-wrap ecarts-scroll" id="ecarts-scroll">
        <table class="table table-ecarts">
            <thead>
                <tr><th>Produit</th><th>Écart</th><th>Date</th></tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($gaps, 0, $gapShown) as $g): $gap = (int) $g['gap']; ?>
                    <tr>
                        <td><strong><?= e((string) $g['product_key']) ?></strong></td>
                        <td>
                            <?php if ($gap < 0): ?>
                                <span class="badge badge-danger"><?= $gap ?></span>
                                <span class="muted">perte</span>
                            <?php else: ?>
                                <span class="badge badge-warning">+<?= $gap ?></span>
                                <span class="muted">stock trouvé en plus</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(formatDateTime((string) $g['counted_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($gapTotal > $gapShown): ?>
            <tbody id="ecarts-more" hidden>
                <?php foreach (array_slice($gaps, $gapShown) as $g): $gap = (int) $g['gap']; ?>
                    <tr>
                        <td><strong><?= e((string) $g['product_key']) ?></strong></td>
                        <td>
                            <?php if ($gap < 0): ?>
                                <span class="badge badge-danger"><?= $gap ?></span>
                                <span class="muted">perte</span>
                            <?php else: ?>
                                <span class="badge badge-warning">+<?= $gap ?></span>
                                <span class="muted">stock trouvé en plus</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(formatDateTime((string) $g['counted_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card surface glass table-wrap">
    <h2 class="card-title">Comptage</h2>
    <form method="post" action="<?= e(url('/admin/compta/inventaire/save')) ?>">
        <?= csrf_field() ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Dernier comptage</th>
                    <th class="th-num">Stock théorique</th>
                    <th>Saisie physique</th>
                    <th>Dernier écart</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): if (!empty($r['paused'])) { continue; } $disId = 'dis-' . substr(md5((string) $r['key']), 0, 10); ?>
                    <tr>
                        <td>
                            <strong><?= e($r['key']) ?></strong>
                            <button type="button" class="btn btn-ghost btn-sm merge-row-btn"
                                    data-key="<?= e($r['key']) ?>"
                                    title="Fusionner ce produit avec un autre (doublon) : pré-remplit la clé source"></button>
                        </td>
                        <td>
                            <?php if ($r['counted_at'] !== null): ?>
                                <?= e(formatDateTime($r['counted_at'])) ?>
                                <span class="muted">(<?= (int) $r['counted_qty'] ?>)</span>
                            <?php else: ?>
                                <span class="muted">Jamais compté</span>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <?php if ($r['theoretical'] !== null): ?>
                                <?= (int) $r['theoretical'] ?>
                            <?php else: ?>
                                <span class="badge badge-warning">À compter</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="number" name="count[<?= e($r['key']) ?>]"
                                   min="0" step="1" placeholder="—" style="width:90px" inputmode="numeric">
                        </td>
                        <td>
                            <?php if ($r['gap'] === null): ?>
                                <span class="muted">—</span>
                            <?php elseif ($r['gap'] < 0): ?>
                                <span class="badge badge-danger"><?= (int) $r['gap'] ?></span>
                            <?php elseif ($r['gap'] === 0): ?>
                                <span class="badge badge-success">0</span>
                            <?php else: ?>
                                <span class="badge badge-warning">+<?= (int) $r['gap'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="submit" class="btn btn-ghost btn-sm" form="<?= $disId ?>"
                                    title="Plus en vente pour l'instant (ex. saisonnier : Redbull Summer hors été) : sort de cette grille, du comptage à l'aveugle et du réappro — rien n'est supprimé, rétablissement en bas de page"></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="muted">Aucun produit à compter. Importe d'abord un rapport SumUp.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p class="muted">Seules les lignes renseignées sont comptées. Chaque comptage devient le nouveau point de départ du stock théorique.
        Les produits « En pause » (plus en vente pour l'instant) n'apparaissent pas dans cette grille : ils sont listés en bas de page, rétablissement en un clic.</p>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Enregistrer le comptage</button>
            <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer les quantités saisies ?')) this.form.reset();">Annuler</button>
        </div>
    </form>

    <?php foreach ($rows as $r): ?>
        <?php if (!empty($r['paused'])) { continue; } // déjà en pause : rien à marquer ?>
        <?php $disId = 'dis-' . substr(md5((string) $r['key']), 0, 10); ?>
        <!-- plus en vente : formulaires hors du form principal (non imbriqués) -->
        <form id="<?= $disId ?>" method="post"
              action="<?= e(url('/admin/compta/inventaire/' . rawurlencode((string) $r['key']) . '/discontinue')) ?>"
              data-confirm="Marquer « <?= e((string) $r['key']) ?> » plus en vente pour l'instant ? Rien n'est supprimé : il passe en pause (grisé, sans saisie), sort des comptages et du réappro, rétablissement en un clic en bas de page."
              data-confirm-button="Plus en vente"
              data-preserve-scroll>
            <input type="hidden" name="back" value="inventaire">
            <?= csrf_field() ?>
        </form>
    <?php endforeach; ?>
</section>

<div class="card surface glass table-wrap">
    <details class="cost-card-lots">
        <summary>Historique des comptages <span class="muted">(<?= count($history) ?> dernières lignes)</span></summary>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Produit</th>
                    <th class="th-num">Compté</th>
                    <th class="th-num">Théorique</th>
                    <th>Écart</th>
                    <th>Note</th>
                </tr>
            </thead>
        <tbody>
            <?php foreach ($history as $h): $gap = (int) $h['gap']; ?>
                <tr>
                    <td><?= e(formatDateTime((string) $h['counted_at'])) ?></td>
                    <td><strong><?= e((string) $h['product_key']) ?></strong></td>
                    <td class="num"><?= (int) $h['counted_qty'] ?></td>
                    <td class="num"><?= (int) $h['theoretical_qty'] ?></td>
                    <td>
                        <?php if ($gap < 0): ?>
                            <span class="badge badge-danger"><?= $gap ?></span>
                        <?php elseif ($gap === 0): ?>
                            <span class="badge badge-success">0</span>
                        <?php else: ?>
                            <span class="badge badge-warning">+<?= $gap ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($h['note'] ?? '') !== '' ? (string) $h['note'] : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($history === []): ?>
                <tr><td colspan="6" class="muted">Aucun comptage enregistré.</td></tr>
            <?php endif; ?>
        </tbody>
        </table>
    </details>
</div>

<div class="card surface glass table-wrap">
    <details class="cost-card-lots" open>
        <summary>Plus en vente pour l'instant (<?= count($discontinuedRows) ?>)</summary>
        <p class="muted">Marquage <strong>temporaire</strong> (ex. Redbull Summer hors été) : ces produits sortent de la grille de comptage ci-dessus, du comptage à l'aveugle et du réappro — <strong>rien n'est supprimé</strong> (ventes, stock et comptages conservés). « Remettre en vente » les réactive aussitôt.</p>
        <?php if ($discontinuedRows === []): ?>
            <p class="muted">Aucun produit marqué plus en vente pour l'instant.</p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr><th>Produit</th><th>Dernier comptage</th><th class="th-num">Stock théorique</th><th>Dernier écart</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php foreach ($discontinuedRows as $d): ?>
                    <tr>
                        <td><code><?= e($d['key']) ?></code></td>
                        <td>
                            <?php if ($d['counted_at'] !== null): ?>
                                <?= e(formatDateTime($d['counted_at'])) ?>
                                <span class="muted">(<?= (int) $d['counted_qty'] ?>)</span>
                            <?php else: ?>
                                <span class="muted">Jamais compté</span>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <?php if ($d['theoretical'] !== null): ?>
                                <?= (int) $d['theoretical'] ?>
                            <?php else: ?>
                                <span class="badge badge-warning">À compter</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['gap'] === null): ?>
                                <span class="muted">—</span>
                            <?php elseif ($d['gap'] < 0): ?>
                                <span class="badge badge-danger"><?= (int) $d['gap'] ?></span>
                            <?php elseif ($d['gap'] === 0): ?>
                                <span class="badge badge-success">0</span>
                            <?php else: ?>
                                <span class="badge badge-warning">+<?= (int) $d['gap'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/compta/inventaire/' . rawurlencode($d['key']) . '/resume')) ?>" data-preserve-scroll>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline btn-sm">Remettre en vente</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </details>
</div>

<div class="card surface glass" id="merge-card">
    <h2 class="card-title">Fusionner des clés produits</h2>
    <p class="muted">Même produit sous deux noms (ex. « Madeleine » dans les ventes SumUp et « Madel Coquille » dans les achats) ? Déplace toutes les données d'une clé vers l'autre : ventes, achats, pertes, aliases, stocks, comptages, drapeaux. Le bouton à côté de chaque produit ci-dessus pré-remplit la clé source.</p>

    <style>
        .merge-dupes { margin: 0 0 14px; }
        .merge-dupes-title { font-size: 0.85rem; font-weight: 700; margin: 0 0 4px; }
        .merge-dupe-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 6px 0; border-top: 1px solid var(--border, #e5e7eb); font-size: 0.87rem; }
        .merge-dupe-names { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .merge-dupe-keep { color: var(--primary); }
        .merge-dupe-arrow { color: var(--muted, #8892a6); }
        .merge-fields { display: grid; grid-template-columns: 1fr auto 1fr; gap: 8px; align-items: start; }
        @media (max-width: 540px) { .merge-fields { grid-template-columns: 1fr; } .merge-swap { justify-self: center; } }
        .merge-swap { margin-top: 24px; }
        .merge-stats { font-size: 0.78rem; color: var(--muted, #8892a6); margin-top: 4px; min-height: 1em; }
        .merge-stats:empty { margin: 0; }
        .merge-sales-tag { color: var(--accent-success, #22c55e); font-weight: 700; }
        .merge-preview { font-size: 0.87rem; background: rgba(72, 189, 211, 0.10); border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 8px 12px; margin: 10px 0 0; }
        .merge-preview.is-warn { background: rgba(234, 88, 12, 0.10); }
        .merge-preview div + div { margin-top: 4px; }
    </style>

    <?php
    // Suggestion par groupe de doublons : la clé à conserver est celle des
    // ventes SumUp si le groupe en contient une (les prochains imports y
    // pointeront via l'alias créé), sinon la première par ordre alphabétique.
    $dupeRows = [];
    foreach ($mergeDupes as $members) {
        $keep = $members[0];
        foreach ($members as $m) {
            if (($keyStats[$m]['sales'] ?? 0) > 0) {
                $keep = $m;
                break;
            }
        }
        $drop = $members[0] === $keep ? ($members[1] ?? $members[0]) : $members[0];
        if ($drop === $keep) {
            continue;
        }
        $dupeRows[] = ['keep' => $keep, 'drop' => $drop, 'members' => $members];
    }
    ?>
    <?php if ($dupeRows !== []): ?>
    <div class="merge-dupes">
        <p class="merge-dupes-title">Doublons probables (même nom à l'orthographe près) — en vert, la clé suggérée comme cible :</p>
        <?php foreach ($dupeRows as $d): ?>
            <div class="merge-dupe-row">
                <span class="merge-dupe-names">
                    <span><?= e($d['drop']) ?></span>
                    <span class="merge-dupe-arrow">→</span>
                    <strong class="merge-dupe-keep"><?= e($d['keep']) ?></strong>
                </span>
                <button type="button" class="btn btn-outline btn-sm merge-dupe-fill"
                        data-drop="<?= e($d['drop']) ?>" data-keep="<?= e($d['keep']) ?>">Remplir</button>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/compta/inventaire/merge')) ?>"
          data-confirm="Fusionner ces clés ? Action irréversible." data-confirm-button="Fusionner" id="merge-form">
        <?= csrf_field() ?>
        <div class="merge-fields">
            <div class="field">
                <label for="merge-source">Clé source (doublon à absorber)</label>
                <div class="combobox">
                    <input type="text" id="merge-source" name="source" class="combobox-input"
                           placeholder="Rechercher une clé…" autocomplete="off" role="combobox"
                           aria-autocomplete="list" aria-expanded="false" aria-controls="merge-source-list" required>
                    <ul class="combobox-list" id="merge-source-list" role="listbox" hidden></ul>
                </div>
                <div class="merge-stats" id="merge-source-stats"></div>
            </div>
            <button type="button" class="btn btn-ghost btn-sm merge-swap" id="merge-swap"
                    title="Inverser source et cible" aria-label="Inverser source et cible">⇄</button>
            <div class="field">
                <label for="merge-target">Clé cible (conservée)</label>
                <div class="combobox">
                    <input type="text" id="merge-target" name="target" class="combobox-input"
                           placeholder="Rechercher une clé…" autocomplete="off" role="combobox"
                           aria-autocomplete="list" aria-expanded="false" aria-controls="merge-target-list" required>
                    <ul class="combobox-list" id="merge-target-list" role="listbox" hidden></ul>
                </div>
                <div class="merge-stats" id="merge-target-stats"></div>
            </div>
        </div>
        <div class="merge-preview" id="merge-preview" hidden></div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger btn-sm">Fusionner</button>
        </div>
        <p class="muted">Astuce : garde la clé des ventes SumUp comme cible (ex. « pulco », pas « Pulco Citronnade ») — les futurs rapports SumUp s'y rattacheront directement.</p>
    </form>
</div>

<script type="application/json" id="merge-stats"><?= json_encode($keyStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<script>
/* Fusion de clés : combobox recherchables + stats par clé + aperçu de la
   fusion avant validation. Le bouton d'une ligne et « Remplir » des
   doublons probables passent par window.AEICMerge. */
(function () {
    var statsEl = document.getElementById('merge-stats');
    var STATS = {};
    try { STATS = JSON.parse(statsEl ? statsEl.textContent : '{}') || {}; } catch (e) { STATS = {}; }
    var KEYS = Object.keys(STATS);

    var srcInput = document.getElementById('merge-source');
    var tgtInput = document.getElementById('merge-target');
    var srcList = document.getElementById('merge-source-list');
    var tgtList = document.getElementById('merge-target-list');
    var srcStats = document.getElementById('merge-source-stats');
    var tgtStats = document.getElementById('merge-target-stats');
    var preview = document.getElementById('merge-preview');
    if (!srcInput || !tgtInput) return;

    function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function highlight(text, q) {
        if (!q) return esc(text);
        var i = norm(text).indexOf(norm(q));
        if (i === -1) return esc(text);
        return esc(text.substring(0, i)) + '<mark>' + esc(text.substring(i, i + q.length)) + '</mark>' + esc(text.substring(i + q.length));
    }
    function plural(n, word) { return n + ' ' + word + (n > 1 ? 's' : ''); }

    function statHtml(key) {
        var s = STATS[key];
        if (!s) return key === '' ? '' : '<span class="badge badge-warning">clé inconnue — vérifie l\'orthographe</span>';
        var parts = [];
        if (s.sales > 0) parts.push(plural(s.sales, 'vente'));
        if (s.purchases > 0) parts.push(plural(s.purchases, 'achat'));
        if (s.losses > 0) parts.push(plural(s.losses, 'perte'));
        if (s.counts > 0) parts.push(plural(s.counts, 'comptage'));
        if (s.costs > 0) parts.push(plural(s.costs, 'lot de coût'));
        if (s.aliases > 0) parts.push(plural(s.aliases, 'alias'));
        if (s.stock !== null && s.stock !== undefined) parts.push('stock ' + s.stock);
        if (s.discontinued) parts.push('plus en vente');
        var html = esc(parts.length > 0 ? parts.join(' · ') : 'aucune donnée');
        if (s.sales > 0) html += ' <strong class="merge-sales-tag">= clé des ventes SumUp</strong>';
        return html;
    }

    function refresh() {
        if (srcStats) srcStats.innerHTML = statHtml(srcInput.value.trim());
        if (tgtStats) tgtStats.innerHTML = statHtml(tgtInput.value.trim());
        if (!preview) return;
        var s = srcInput.value.trim();
        var t = tgtInput.value.trim();
        if (s === '' || t === '') { preview.hidden = true; preview.innerHTML = ''; return; }
        preview.hidden = false;
        if (s === t || norm(s) === norm(t)) {
            preview.className = 'merge-preview is-warn';
            preview.textContent = 'Source et cible sont identiques : rien à fusionner.';
            return;
        }
        var ss = STATS[s];
        var ts = STATS[t];
        var lines = [];
        if (!ss) {
            lines.push('« ' + s + ' » est inconnue : vérifie l\'orthographe.');
        } else {
            var moved = [];
            if (ss.sales > 0) moved.push(plural(ss.sales, 'vente'));
            if (ss.purchases > 0) moved.push(plural(ss.purchases, 'achat'));
            if (ss.losses > 0) moved.push(plural(ss.losses, 'perte'));
            if (ss.counts > 0) moved.push(plural(ss.counts, 'comptage'));
            if (ss.costs > 0) moved.push(plural(ss.costs, 'lot de coût'));
            if (ss.aliases > 0) moved.push(plural(ss.aliases, 'alias'));
            if (ss.stock !== null && ss.stock !== undefined && ss.stock !== 0) moved.push('stock ' + ss.stock);
            lines.push(moved.length > 0
                ? 'Déplacera « ' + s + ' » → « ' + t + ' » : ' + moved.join(', ') + '.'
                : '« ' + s + ' » n\'a aucune donnée : la clé sera simplement vidée.');
        }
        if (!ts) {
            lines.push('« ' + t + ' » est inconnue : elle sera créée par la fusion.');
        } else if (ss && ts && ss.stock !== null && ss.stock !== undefined && ts.stock !== null && ts.stock !== undefined) {
            lines.push('Stock fusionné : ' + ss.stock + ' + ' + ts.stock + ' = ' + (ss.stock + ts.stock) + '.');
        }
        if (ss && ss.sales > 0 && (!ts || ts.sales === 0)) {
            lines.push('« ' + s + ' » est la clé des ventes SumUp : garde-la en cible (bouton ⇄ pour inverser).');
        }
        preview.className = 'merge-preview';
        var html = '';
        lines.forEach(function (p) { html += '<div>' + esc(p) + '</div>'; });
        preview.innerHTML = html;
    }

    function buildList(input, list) {
        var q = norm(input.value);
        var matches = KEYS.filter(function (k) { return q === '' || norm(k).indexOf(q) !== -1; });
        if (matches.length > 100) matches = matches.slice(0, 100);
        var html = '';
        matches.forEach(function (k) {
            html += '<li class="combobox-option" role="option" data-value="' + esc(k) + '">' + highlight(k, q) + '</li>';
        });
        list.innerHTML = html === '' ? '<li class="combobox-empty">Aucune clé.</li>' : html;
        Array.prototype.forEach.call(list.querySelectorAll('.combobox-option'), function (li) {
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                input.value = li.getAttribute('data-value');
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                refresh();
            });
        });
    }

    function wireCombo(input, list) {
        input.addEventListener('focus', function () { list.hidden = false; buildList(input, list); input.setAttribute('aria-expanded', 'true'); });
        input.addEventListener('input', function () { list.hidden = false; buildList(input, list); input.setAttribute('aria-expanded', 'true'); refresh(); });
        input.addEventListener('blur', function () { setTimeout(function () { list.hidden = true; input.setAttribute('aria-expanded', 'false'); }, 150); });
    }

    function setKey(input, key) { input.value = key; refresh(); }

    window.AEICMerge = {
        setSource: function (k) { setKey(srcInput, k); },
        setTarget: function (k) { setKey(tgtInput, k); },
        focusTarget: function () { tgtInput.focus({ preventScroll: true }); }
    };

    var swap = document.getElementById('merge-swap');
    if (swap) swap.addEventListener('click', function () {
        var v = srcInput.value;
        srcInput.value = tgtInput.value;
        tgtInput.value = v;
        refresh();
    });

    Array.prototype.forEach.call(document.querySelectorAll('.merge-row-btn'), function (b) {
        b.addEventListener('click', function () {
            window.AEICMerge.setSource(b.getAttribute('data-key'));
            var card = document.getElementById('merge-card');
            if (card && card.scrollIntoView) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.AEICMerge.focusTarget();
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('.merge-dupe-fill'), function (b) {
        b.addEventListener('click', function () {
            window.AEICMerge.setSource(b.getAttribute('data-drop'));
            window.AEICMerge.setTarget(b.getAttribute('data-keep'));
            var form = document.getElementById('merge-form');
            if (form && form.scrollIntoView) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });

    wireCombo(srcInput, srcList);
    wireCombo(tgtInput, tgtList);
    refresh();
})();
</script>
