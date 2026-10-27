<?php

declare(strict_types=1);

/**
 * @var array<string,mixed> $user
 * @var list<array{key:string,stock:?int,counted_at:?string,counted_qty:?int,gap:?int,theoretical:?int}> $rows
 * @var list<array{key:string,counted_at:?string,counted_qty:?int,gap:?int,theoretical:?int}> $discontinuedRows
 * @var list<array<string,mixed>> $history
 * @var list<array<string,mixed>> $gaps
 * @var array<string, array{sales:int, purchases:int, losses:int, counts:int, costs:int, aliases:int, stock:?int, discontinued:bool, promotions:int, checks:int, infinite:int, zero_since:int, packs:int, fiche:int}> $keyStats
 * @var list<list<string>> $mergeDupes
 * @var float $stockValue   Valeur du stock valorisé (Σ stock × coût du lot en cours).
 * @var int   $stockUnits   Unités physiques en stock (stocks théoriques positifs).
 * @var int   $valuedLines  Produits valorisés (coût connu).
 * @var int   $noCostLines  Produits en stock sans coût saisi (hors total).
 * @var float $pausedValue  Valeur du stock des produits « en pause » (hors vente).
 * @var int   $pausedUnits  Unités en stock des produits « en pause ».
 * @var array<string, array{name:string,value:float,units:int,valued:int,noCost:int,pausedValue:float,pausedUnits:int}> $stockByCategory
 *            Ventilation des métriques ci-dessus par catégorie (rayon), triée par valeur décroissante.
 * @var array{resumed:list<string>, paused:list<string>} $sweep Changements automatiques appliqués au chargement.
 * @var list<array<string,mixed>> $pending_sessions Sessions de relevé tablette ayant encore des écarts à corriger.
 * @var int $invPage   Page courante de la grille de comptage (lot 4B, 50/page).
 * @var int $invPages  Nombre total de pages.
 * @var int $invTotal  Nombre total de produits à compter (toutes pages).
 * @var array{dispo:bool,total:int,modele:list<array{source:string,target:string,score:float,ressemblance:int}>,residuels:list<array{source:string,target:string,motif:string}>} $t1Suggestions
 *            Carte « Suggestions de rapprochement » (T1 lot C) : paires prêtes
 *            à décider (assemblage unique AdminStockController::inventory —
 *            clés vivantes, déjà décidées exclues, plafond 20/section).
 */
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Inventaire</h1>
        <p class="muted">Compte le stock <strong>physique</strong> et compare au <strong>théorique</strong> (dernier comptage + achats − ventes). Un écart = perte, casse, offert ou erreur de saisie.</p>
    </div>
</div>

<?php if ($sweep['resumed'] !== [] || $sweep['paused'] !== []): ?>
<div class="alert alert-info">
    <strong>Automatique</strong> —
    <?php if ($sweep['resumed'] !== []): ?>
        remis en vente (stock disponible) : <strong><?= e(implode(', ', $sweep['resumed'])) ?></strong>
    <?php endif; ?>
    <?php if ($sweep['resumed'] !== [] && $sweep['paused'] !== []): ?> · <?php endif; ?>
    <?php if ($sweep['paused'] !== []): ?>
        mis en pause (stock à 0 depuis 7 jours ou plus) : <strong><?= e(implode(', ', $sweep['paused'])) ?></strong>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php $pendingTotal = array_sum(array_map(static fn (array $s): int => (int) $s['pending_count'], $pending_sessions ?? [])); ?>
<?php if (($pending_sessions ?? []) !== []): ?>
<section class="card surface glass">
    <style>
        .releves-pending-list { margin: 0.35rem 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 0.35rem; }
        .releves-pending-list .muted { font-size: 0.8rem; }
    </style>
    <h2 class="card-title">Relevés tablette à corriger : <?= count($pending_sessions) ?> session(s), <?= $pendingTotal ?> écart(s) en attente</h2>
    <p class="muted">Un relevé tablette ne touche le stock qu'après « Corriger &amp; synchroniser le stock » sur sa page de détail.</p>
    <ul class="releves-pending-list">
        <?php foreach ($pending_sessions as $s): ?>
            <li>
                <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/compta/inventaire-tablette/' . rawurlencode((string) $s['id']))) ?>">
                    Détail du relevé du <?= e(formatDate((string) $s['counted_at'])) ?> (<?= (int) $s['pending_count'] ?> écart(s))
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

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

<section class="card surface glass">
    <h2 class="card-title">Comptage</h2>

    <?php if ($rows !== [] || $discontinuedRows !== []): ?>
    <?php
        // Catégories avec au moins une ligne affichable (stock valorisé,
        // sans coût ou en pause) : les rayons vides ne polluent pas le tableau.
        $hasNoCostCol = $noCostLines > 0;
        $hasPausedCol = $pausedValue > 0.0 || $pausedUnits > 0;
    ?>
    <style>
        .stock-total-row { margin: 0.25rem 0 0.6rem; }
        .stock-total-label {
            font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.08em;
            font-weight: 800; color: var(--muted, #8892a6);
        }
        .table-stock-cat { margin: 0; }
        .table-stock-cat td, .table-stock-cat th { padding: 0.45rem 0.6rem; font-size: 0.86rem; }
        .table-stock-cat th { font-size: 0.72rem; }
        .table-stock-cat tfoot td {
            border-top: 2px solid rgba(72, 189, 211, 0.35);
            background: rgba(72, 189, 211, 0.07);
            font-weight: 800;
        }
        .stock-cat-value { font-weight: 800; color: var(--primary, #48bdd3); white-space: nowrap; }
        .table-stock-cat tfoot .stock-cat-value { font-size: 1.05rem; font-weight: 900; }
        .stock-cat-sub { display: block; font-size: 0.72rem; color: var(--muted, #8892a6); font-weight: 500; }
        .stock-cat-warn { color: var(--accent-warning, #f59e0b); font-weight: 700; }
    </style>
    <div class="stock-total-row">
        <p class="stock-total-label"
           title="Σ (stock théorique × coût de revient du lot en cours, même source que la page Réappro). Produits jamais comptés ou à stock nul/négatif exclus.">Valeur du stock — par catégorie</p>
        <div class="table-wrap">
            <table class="table table-stock-cat">
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <th class="th-num">Valeur</th>
                        <th class="th-num">En stock</th>
                        <?php if ($hasNoCostCol): ?><th>Coût manquant</th><?php endif; ?>
                        <?php if ($hasPausedCol): ?><th>Hors vente (en pause)</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stockByCategory as $c): ?>
                        <tr>
                            <td><strong><?= e((string) $c['name']) ?></strong></td>
                            <td class="num stock-cat-value"><?= e(formatPrice($c['value'])) ?></td>
                            <td class="num">
                                <?= (int) $c['units'] ?> u.
                                <span class="stock-cat-sub"><?= (int) $c['valued'] ?> produit<?= (int) $c['valued'] > 1 ? 's' : '' ?> valorisé<?= (int) $c['valued'] > 1 ? 's' : '' ?></span>
                            </td>
                            <?php if ($hasNoCostCol): ?>
                                <td>
                                    <?php if ((int) $c['noCost'] > 0): ?>
                                        <span class="stock-cat-warn"><?= (int) $c['noCost'] ?> produit<?= (int) $c['noCost'] > 1 ? 's' : '' ?></span>
                                        <span class="stock-cat-sub">sans coût saisi — hors total</span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <?php if ($hasPausedCol): ?>
                                <td>
                                    <?php if ($c['pausedValue'] > 0.0 || (int) $c['pausedUnits'] > 0): ?>
                                        <?= e(formatPrice($c['pausedValue'])) ?>
                                        <span class="stock-cat-sub"><?= (int) $c['pausedUnits'] ?> u. — non comptés dans la valeur principale</span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td class="num stock-cat-value"><?= e(formatPrice($stockValue)) ?></td>
                        <td class="num">
                            <?= $stockUnits ?> u.
                            <span class="stock-cat-sub"><?= $valuedLines ?> produit<?= $valuedLines > 1 ? 's' : '' ?> valorisé<?= $valuedLines > 1 ? 's' : '' ?></span>
                        </td>
                        <?php if ($hasNoCostCol): ?>
                            <td>
                                <span class="stock-cat-warn"><?= $noCostLines ?> produit<?= $noCostLines > 1 ? 's' : '' ?></span>
                                <span class="stock-cat-sub">sans coût saisi (page Coûts de revient)</span>
                            </td>
                        <?php endif; ?>
                        <?php if ($hasPausedCol): ?>
                            <td>
                                <?= e(formatPrice($pausedValue)) ?>
                                <span class="stock-cat-sub"><?= $pausedUnits ?> u. — non comptés dans la valeur principale</span>
                            </td>
                        <?php endif; ?>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Boîte à outils tableaux (lot 4B) : filtre rapide, choix des
         colonnes et compteur de la grille de comptage. Hors du
         formulaire et hors du conteneur scrollable : aucun champ de
         saisie n'est touché, le menu n'est pas rogné. -->
    <div class="table-tools">
        <input type="search" class="tt-filter" data-table-filter="#inventory-table"
               aria-label="Filtrer les produits à compter" placeholder="Filtrer la grille…">
        <details class="tt-cols" data-table-cols="#inventory-table">
            <summary>Colonnes</summary>
        </details>
        <span class="tt-count muted" data-table-count="#inventory-table" aria-live="polite"></span>
    </div>
    <form method="post" action="<?= e(url('/admin/compta/inventaire/save')) ?>" data-preserve-scroll>
        <?= csrf_field() ?>
        <div class="table-wrap">
        <table class="table" id="inventory-table" data-table
               data-cols-visible="produit,dernier_comptage,stock_theorique,saisie,dernier_ecart,actions">
            <thead>
                <tr>
                    <th data-col="produit" data-col-fixed>Produit</th>
                    <th data-col="dernier_comptage">Dernier comptage</th>
                    <th class="th-num" data-col="stock_theorique">Stock théorique</th>
                    <th data-col="saisie" data-col-fixed>Saisie physique</th>
                    <th data-col="dernier_ecart">Dernier écart</th>
                    <th data-col="actions"><span class="muted">Pause</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): if (!empty($r['paused'])) { continue; } $disId = 'dis-' . substr(md5((string) $r['key']), 0, 10); ?>
                    <tr>
                        <td>
                            <strong><?= e($r['key']) ?></strong>
                            <button type="button" class="btn btn-ghost btn-sm merge-row-btn"
                                    data-key="<?= e($r['key']) ?>"
                                    title="Fusionner ce produit avec un autre (doublon) : pré-remplit la clé source">Fusionner</button>
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
                                   min="0" step="1" placeholder="—" style="width:90px" inputmode="numeric"
                                   aria-label="Quantité physiquement comptée pour <?= e($r['key']) ?>">
                        </td>
                        <td>
                            <?php if ($r['gap'] === null): ?>
                                <span class="muted">—</span>
                            <?php elseif ($r['gap'] < 0): ?>
                                <span class="badge badge-danger" aria-label="Écart négatif : il manque <?= (int) abs((int) $r['gap']) ?> en stock par rapport au théorique"><?= (int) $r['gap'] ?></span>
                            <?php elseif ($r['gap'] === 0): ?>
                                <span class="badge badge-success" aria-label="Écart nul : le comptage correspond au stock théorique">0</span>
                            <?php else: ?>
                                <span class="badge badge-warning" aria-label="Écart positif : <?= (int) $r['gap'] ?> de plus que le stock théorique">+<?= (int) $r['gap'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="submit" class="btn btn-ghost btn-sm" form="<?= $disId ?>"
                                    title="Plus en vente pour l'instant (ex. saisonnier : Redbull Summer hors été) : sort de cette grille, du comptage à l'aveugle et du réappro — rien n'est supprimé, rétablissement en bas de page">Mettre en pause</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="muted">Aucun produit à compter. Importe d'abord un rapport SumUp.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
        <p class="muted">Seules les lignes renseignées sont comptées. Chaque comptage devient le nouveau point de départ du stock théorique.
        Les produits « En pause » (plus en vente pour l'instant) n'apparaissent pas dans cette grille : ils sont listés en bas de page, rétablissement en un clic.</p>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Enregistrer le comptage</button>
            <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer les quantités saisies ?')) this.form.reset();">Annuler</button>
        </div>
    </form>

    <?php if ($invPages > 1): ?>
    <!-- Pagination de la grille (lot 4B, modèle page audit) : seules les
         lignes renseignées sont comptées, les pages se saisissent à la
         suite sans perte. -->
    <p class="muted">
        <?= e((string) $invTotal) ?> produit<?= $invTotal > 1 ? 's' : '' ?> à compter — page <?= e((string) $invPage) ?> sur <?= e((string) $invPages) ?>
    </p>
    <div class="admin-actions">
        <?php if ($invPage > 1): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/inventaire?page=' . ($invPage - 1))) ?>">← Page précédente</a>
        <?php endif; ?>
        <?php if ($invPage < $invPages): ?>
            <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/inventaire?page=' . ($invPage + 1))) ?>">Page suivante →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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
                    <th>Par</th>
                    <th>Note</th>
                </tr>
            </thead>
        <tbody>
            <?php foreach ($history as $h): $gap = (int) $h['gap']; ?>
                <?php
                    // Qui a compté : membre admin (usr_… → nom), identité
                    // kiosque (« Prénom Nom (rôle) ») — ou rien pour les
                    // écritures de script / anciens comptages anonymes.
                    $by = trim((string) ($h['created_by'] ?? ''));
                    if ($by !== '' && str_starts_with($by, 'usr_')) {
                        $who = trim(($h['prenom'] ?? '') . ' ' . ($h['nom'] ?? ''));
                    } elseif ($by === '' || $by === 'kiosque' || $by === 'auto' || $by === 'script' || $by === 'system') {
                        $who = '';
                    } else {
                        $who = $by;
                    }
                ?>
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
                    <td><?= $who !== '' ? e($who) : '' ?></td>
                    <td><?= e((string) ($h['note'] ?? '') !== '' ? (string) $h['note'] : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($history === []): ?>
                <tr><td colspan="7" class="muted">Aucun comptage enregistré.</td></tr>
            <?php endif; ?>
        </tbody>
        </table>
    </details>
</div>

<div class="card surface glass table-wrap">
    <details class="cost-card-lots" open>
        <summary>Plus en vente pour l'instant (<?= count($discontinuedRows) ?>)</summary>
        <p class="muted">Marquage <strong>temporaire</strong> (ex. Redbull Summer hors été) : ces produits sortent de la grille de comptage ci-dessus, du comptage à l'aveugle et du réappro — <strong>rien n'est supprimé</strong> (ventes, stock et comptages conservés). « Remettre en vente » les réactive aussitôt.
        Automatique : les produits mis en pause <em>par le système</em> (stock à 0 depuis 7 jours ou plus) repassent en vente dès que du stock réapparaît — les pauses posées manuellement ne sont jamais touchées.</p>
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

<div class="card surface glass" id="t1-suggestions-card">
    <span id="suggestions-rapprochement" aria-hidden="true"></span>
    <h2 class="card-title">Suggestions de rapprochement</h2>
    <p class="muted">Des libellés proches peuvent désigner le même produit (ex. « Caprisun » et « Caprisun tropical »). Chaque suggestion présente les deux libellés, celui à conserver en vert, et sa <strong>ressemblance</strong> (pourcentage). Tu restes décisionnaire : <strong>Fusionner</strong> déplace toutes les données d'une clé vers l'autre (irréversible), <strong>Ignorer</strong> ne touche à rien et retire la paire de cette liste.</p>

    <style>
        .t1-empty { background: rgba(72, 189, 211, 0.08); border-radius: 8px; padding: 10px 12px; }
        .t1-section { margin: 0 0 14px; }
        .t1-section-title { font-size: 0.85rem; font-weight: 700; margin: 0 0 4px; }
        .t1-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 6px 0; border-top: 1px solid var(--border, #e5e7eb); font-size: 0.87rem; }
        .t1-names { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; min-width: 0; }
        .t1-keep { color: var(--primary); }
        .t1-arrow { color: var(--muted, #8892a6); }
        .t1-motif { color: var(--muted, #8892a6); font-size: 0.78rem; flex-basis: 100%; }
        .t1-actions { display: flex; gap: 6px; }
    </style>

    <?php if (!($t1Suggestions['dispo'] ?? false)): ?>
        <p class="muted t1-empty">Aucune suggestion disponible pour l'instant (générez-les via <code>scripts/t1_similarity.py</code>).</p>
    <?php elseif (($t1Suggestions['total'] ?? 0) === 0): ?>
        <p class="muted t1-empty">Toutes les suggestions ont été traitées — aucune ne reste à qualifier pour l'instant.</p>
    <?php else: ?>
        <?php if (($t1Suggestions['modele'] ?? []) !== []): ?>
        <div class="t1-section">
            <p class="t1-section-title">Suggestions de rapprochement (par ressemblance décroissante) — en vert, la clé suggérée comme cible :</p>
            <?php foreach ($t1Suggestions['modele'] as $p): ?>
            <div class="t1-row">
                <span class="t1-names">
                    <span><?= e($p['source']) ?></span>
                    <span class="t1-arrow">→</span>
                    <strong class="t1-keep"><?= e($p['target']) ?></strong>
                    <span class="badge badge-muted">ressemblance <?= (int) $p['ressemblance'] ?> %</span>
                </span>
                <span class="t1-actions">
                    <?php // Un formulaire par décision : la confirmation ne s'applique qu'à la fusion (irréversible), jamais à « Ignorer » (1 clic). ?>
                    <form method="post" action="<?= e(url('/admin/compta/inventaire/t1/decide')) ?>"
                          data-confirm="Fusionner « <?= e($p['source']) ?> » dans « <?= e($p['target']) ?> ? Action irréversible."
                          data-confirm-button="Fusionner">
                        <?= csrf_field() ?>
                        <input type="hidden" name="source" value="<?= e($p['source']) ?>">
                        <input type="hidden" name="target" value="<?= e($p['target']) ?>">
                        <input type="hidden" name="decision" value="fusion">
                        <input type="hidden" name="origine" value="modele">
                        <input type="hidden" name="score" value="<?= e(number_format($p['score'], 4, '.', '')) ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Fusionner</button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/compta/inventaire/t1/decide')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="source" value="<?= e($p['source']) ?>">
                        <input type="hidden" name="target" value="<?= e($p['target']) ?>">
                        <input type="hidden" name="decision" value="ignorer">
                        <input type="hidden" name="origine" value="modele">
                        <input type="hidden" name="score" value="<?= e(number_format($p['score'], 4, '.', '')) ?>">
                        <button type="submit" class="btn btn-outline btn-sm">Ignorer</button>
                    </form>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (($t1Suggestions['residuels'] ?? []) !== []): ?>
        <div class="t1-section">
            <p class="t1-section-title">À qualifier (doutes relevés dans l'analyse, sans ressemblance chiffrée) :</p>
            <?php foreach ($t1Suggestions['residuels'] as $p): ?>
            <div class="t1-row">
                <span class="t1-names">
                    <span><?= e($p['source']) ?></span>
                    <span class="t1-arrow">→</span>
                    <strong class="t1-keep"><?= e($p['target']) ?></strong>
                </span>
                <?php if (($p['motif'] ?? '') !== ''): ?>
                <span class="t1-motif"><?= e($p['motif']) ?></span>
                <?php endif; ?>
                <span class="t1-actions">
                    <form method="post" action="<?= e(url('/admin/compta/inventaire/t1/decide')) ?>"
                          data-confirm="Fusionner « <?= e($p['source']) ?> » dans « <?= e($p['target']) ?> ? Action irréversible."
                          data-confirm-button="Fusionner">
                        <?= csrf_field() ?>
                        <input type="hidden" name="source" value="<?= e($p['source']) ?>">
                        <input type="hidden" name="target" value="<?= e($p['target']) ?>">
                        <input type="hidden" name="decision" value="fusion">
                        <input type="hidden" name="origine" value="residuel">
                        <button type="submit" class="btn btn-danger btn-sm">Fusionner</button>
                    </form>
                    <form method="post" action="<?= e(url('/admin/compta/inventaire/t1/decide')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="source" value="<?= e($p['source']) ?>">
                        <input type="hidden" name="target" value="<?= e($p['target']) ?>">
                        <input type="hidden" name="decision" value="ignorer">
                        <input type="hidden" name="origine" value="residuel">
                        <button type="submit" class="btn btn-outline btn-sm">Ignorer</button>
                    </form>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php $t1Affichees = count($t1Suggestions['modele'] ?? []) + count($t1Suggestions['residuels'] ?? []); ?>
        <?php if (($t1Suggestions['total'] ?? 0) > $t1Affichees): ?>
        <p class="muted">+ <?= (int) $t1Suggestions['total'] - $t1Affichees ?> autre(s) paire(s) attendront ces décisions avant d'apparaître ici.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card surface glass" id="t1-auto-card">
    <span id="fusions-auto" aria-hidden="true"></span>
    <h2 class="card-title">Fusions automatiques</h2>
    <p class="muted">Le rapprochement agit seul à deux niveaux, toujours réversible : les doublons de <strong>casse pure</strong> (ex. « Pepsi » / « pepsi ») sont fusionnés immédiatement ; les paires très sûres (même base de nom, ex. « Fanta » / « Fanta Orange ») attendent une <strong>garde de 48 h</strong> pendant laquelle tu peux tout annuler d'un clic. Chaque fusion est archivée avant d'être faite : la clé peut être <strong>restaurée</strong> (son historique de ventes, lui, reste sur la clé conservée). Le reste part en suggestion humaine ci-dessus.</p>

    <?php $t1a = $t1Auto ?? ['en_attente' => [], 'mures' => [], 'appliquees' => [], 'annulees' => []]; ?>

    <?php if (($t1a['en_attente'] ?? []) !== []): ?>
    <div class="t1-section">
        <p class="t1-section-title">En quarantaine (48 h, fuseau de Paris) — fusion automatique sauf annulation :</p>
        <?php foreach ($t1a['en_attente'] as $p): ?>
        <div class="t1-row">
            <span class="t1-names">
                <span><?= e($p['source']) ?></span>
                <span class="t1-arrow">→</span>
                <strong class="t1-keep"><?= e($p['target']) ?></strong>
                <span class="badge badge-muted">ressemblance <?= (int) $p['score'] ?> %</span>
                <span class="badge badge-muted">dans <?= e($p['reste']) ?></span>
            </span>
            <span class="t1-actions">
                <form method="post" action="<?= e(url('/admin/compta/inventaire/t1/auto/veto')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($p['id']) ?>">
                    <button type="submit" class="btn btn-outline btn-sm">Annuler (garder séparés)</button>
                </form>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (($t1a['mures'] ?? []) !== []): ?>
    <div class="t1-section">
        <p class="t1-section-title">Mûres — seront fusionnées au prochain chargement de cette page :</p>
        <?php foreach ($t1a['mures'] as $p): ?>
        <div class="t1-row">
            <span class="t1-names">
                <span><?= e($p['source']) ?></span>
                <span class="t1-arrow">→</span>
                <strong class="t1-keep"><?= e($p['target']) ?></strong>
                <span class="badge badge-muted">ressemblance <?= (int) $p['score'] ?> %</span>
            </span>
        </div>
        <?php endforeach; ?>
        <form method="post" action="<?= e(url('/admin/compta/inventaire/t1/auto/appliquer')) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary btn-sm">Appliquer maintenant les fusions mûres</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (($t1a['appliquees'] ?? []) !== []): ?>
    <div class="t1-section">
        <p class="t1-section-title">Fusions automatiques appliquées (7 derniers jours) :</p>
        <?php foreach ($t1a['appliquees'] as $p): ?>
        <div class="t1-row">
            <span class="t1-names">
                <span class="t1-arrow"><?= e($p['quand']) ?></span>
                <span><?= e($p['source']) ?></span>
                <span class="t1-arrow">→</span>
                <strong class="t1-keep"><?= e($p['target']) ?></strong>
                <?php if (($p['motif'] ?? '') !== ''): ?>
                <span class="badge badge-muted"><?= ($p['niveau'] ?? 0) === 1 ? 'casse' : e($p['motif']) ?></span>
                <?php endif; ?>
            </span>
            <?php if (($p['audit_id'] ?? '') !== '' && !empty($p['archivable'])): ?>
            <span class="t1-actions">
                <form method="post" action="<?= e(url('/admin/compta/inventaire/t1/auto/restaurer')) ?>"
                      data-confirm="Restaurer la clé « <?= e($p['source']) ?> » (fiche, alias, stock du snapshot) ? Son historique de ventes reste sur « <?= e($p['target']) ?> »."
                      data-confirm-button="Restaurer">
                    <?= csrf_field() ?>
                    <input type="hidden" name="audit_id" value="<?= e($p['audit_id']) ?>">
                    <button type="submit" class="btn btn-outline btn-sm">Restaurer la clé</button>
                </form>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (($t1a['annulees'] ?? []) !== []): ?>
    <div class="t1-section">
        <p class="t1-section-title">Annulées (jamais fusionnées, traces conservées) :</p>
        <?php foreach ($t1a['annulees'] as $p): ?>
        <div class="t1-row">
            <span class="t1-names">
                <s><?= e($p['source']) ?></s>
                <span class="t1-arrow">→</span>
                <s><?= e($p['target']) ?></s>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (($t1a['en_attente'] ?? []) === [] && ($t1a['mures'] ?? []) === [] && ($t1a['appliquees'] ?? []) === [] && ($t1a['annulees'] ?? []) === []): ?>
    <p class="muted t1-empty">Aucune fusion automatique en cours ni récente.</p>
    <?php endif; ?>
</div>

<div class="card surface glass" id="merge-card">
    <span id="fusion-doublons" aria-hidden="true"></span>
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
        .merge-all { margin-top: 12px; }
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
        <?php if (count($dupeRows) >= 2): ?>
        <?php // « Tout fusionner » : uniquement des champs cachés générés depuis les paires AFFICHÉES ci-dessus (aucune entrée libre) ; le contrôleur revalide chaque paire. ?>
        <?php $allPairs = implode(' ; ', array_map(static fn (array $d): string => $d['drop'] . ' → ' . $d['keep'], $dupeRows)); ?>
        <form method="post" action="<?= e(url('/admin/compta/inventaire/merge-all')) ?>"
              data-confirm="Fusionner définitivement : <?= e($allPairs) ?> ? Action irréversible."
              data-confirm-button="Tout fusionner" class="merge-all">
            <?= csrf_field() ?>
            <?php foreach ($dupeRows as $d): ?>
                <input type="hidden" name="pairs_from[]" value="<?= e($d['drop']) ?>">
                <input type="hidden" name="pairs_to[]" value="<?= e($d['keep']) ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-danger btn-sm">Tout fusionner (<?= count($dupeRows) ?> paires)</button>
        </form>
        <?php endif; ?>
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
        if (s.promotions > 0) parts.push(plural(s.promotions, 'promotion'));
        if (s.checks > 0) parts.push(plural(s.checks, 'case cochée'));
        if (s.infinite > 0) parts.push('stock ∞');
        if (s.zero_since > 0) parts.push('à zéro depuis');
        if (s.packs > 0) parts.push('pack de ' + s.packs);
        if (s.fiche > 0) parts.push('fiche carte');
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
            if (ss.promotions > 0) moved.push(plural(ss.promotions, 'promotion'));
            if (ss.checks > 0) moved.push(plural(ss.checks, 'case cochée'));
            if (ss.infinite > 0) moved.push('drapeau stock ∞');
            if (ss.zero_since > 0) moved.push('suivi « à zéro »');
            if (ss.packs > 0) moved.push('pack de ' + ss.packs);
            if (ss.fiche > 0) moved.push('fiche carte');
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

<!-- Boîte à outils tableaux (lot 4B) : tri, filtre et colonnes de la
     grille de comptage. -->
<script src="<?= e(rootAssetVersioned('/assets/js/table-tools.js')) ?>" defer></script>
