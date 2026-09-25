<?php

declare(strict_types=1);

/**
 * Page Réapprovisionnement — analyse en lecture seule.
 *
 * Le stock affiché est le THÉORIQUE issu de l'inventaire (dernier comptage
 * + achats − ventes − pertes), la même source que la page Inventaire :
 * il est toujours à jour, aucune saisie manuelle ici.
 *
 * @var list<array<string,mixed>>                    $rows
 * @var array<string,array{label:string,days:int}>   $periods
 * @var string                                       $currentPeriod
 * @var int                                          $targetDays
 * @var int                                          $alerts
 * @var array<string,string>                         $refOptions
 * @var string                                       $currentRef
 * @var string|null                                  $refFrom
 * @var string|null                                  $refTo
 * @var int                                          $refOpenDays
 * @var string                                       $du
 * @var string                                       $au
 */

function reorder_qty(float $v): string {
    return number_format($v, ($v >= 10 ? 0 : 1), ',', ' ');
}

function reorder_date_fr(?string $day): string {
    if ($day === null || $day === '') { return '—'; }
    $t = strtotime($day);
    return $t === false ? $day : date('d/m/Y', $t);
}

function reorder_autonomy_class(?int $autonomy, bool $hasStock): string {
    if (!$hasStock || $autonomy === null) { return 'auto-none'; }
    if ($autonomy < 3) { return 'auto-danger'; }
    if ($autonomy < 7) { return 'auto-warn'; }
    return 'auto-ok';
}

// Lien « Inventaire » : même accès que la page elle-même (groupe Système
// ou attribution individuelle) — on ne montre jamais un lien 403.
$canInventory = \App\Core\Permissions::isSystemAdmin()
    || \App\Core\Permissions::userHasExtraPage('inventory');

// Libellés courts pour les pastilles de période.
$refShort = [
    '1d'     => '1 j',
    '7d'     => '7 j',
    '30d'    => '30 j',
    '3m'     => '3 mois',
    '6m'     => '6 mois',
    '12m'    => '12 mois',
    'ytd'    => 'Année',
    'all'    => 'Tout',
    'custom' => 'Perso.',
];

// Totaux (servent aux KPI et au pied de tableau).
$totalToOrder = 0;
$totalCost = 0.0;
$missingCost = 0;
$uncounted = 0;
foreach ($rows as $r) {
    $totalToOrder += (int) $r['to_order'];
    if ($r['order_cost'] !== null) {
        $totalCost += (float) $r['order_cost'];
    } elseif ((int) $r['to_order'] > 0) {
        $missingCost++;
    }
    if (($r['state'] ?? '') === 'unknown') {
        $uncounted++;
    }
}
?>
<div class="compta-head">
    <div class="compta-head-row">
        <div>
            <p class="eyebrow">Comptabilité</p>
            <h1 class="page-title">Réapprovisionnement</h1>
            <p class="muted">Le stock est le <strong>théorique de l'inventaire</strong> (dernier comptage + achats − ventes − pertes) : il suit automatiquement chaque mouvement, aucune saisie ici. La consommation moyenne de la période donne la quantité à commander pour couvrir l'horizon choisi.</p>
        </div>
        <?php if ($canInventory): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/compta/inventaire')) ?>">Faire l'inventaire</a>
        <?php endif; ?>
    </div>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Produits suivis</p>
        <p class="kpi-value"><?= count($rows) ?></p>
        <p class="kpi-sub">vendus sur la période analysée</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">À commander</p>
        <p class="kpi-value <?= $totalToOrder > 0 ? '' : 'is-positive' ?>"><?= (int) $totalToOrder ?></p>
        <p class="kpi-sub">unités pour couvrir <?= e($periods[$currentPeriod]['label']) ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Coût estimé du panier</p>
        <p class="kpi-value"><?= e(formatPrice($totalCost)) ?></p>
        <p class="kpi-sub"><?php if ($missingCost > 0): ?>+<?= (int) $missingCost ?> produit<?= $missingCost > 1 ? 's' : '' ?> sans coût saisi<?php else: ?>tous les coûts sont connus<?php endif ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Alertes stock</p>
        <p class="kpi-value <?= $alerts > 0 ? 'is-negative' : 'is-positive' ?>"><?= (int) $alerts ?></p>
        <p class="kpi-sub">autonomie &lt; 7 jours d'ouverture</p>
    </div>
</div>

<form method="get" class="reappro-bar" id="reappro-filters">
    <input type="hidden" name="q" id="f-q">
    <input type="hidden" name="cat" id="f-cat">
    <input type="hidden" name="etat" id="f-etat">
    <input type="hidden" name="tri" id="f-tri">
    <div class="reappro-field">
        <span class="field-label">Période analysée</span>
        <div class="chip-row" role="radiogroup" aria-label="Période analysée">
            <?php foreach ($refOptions as $k => $label): ?>
                <label class="chip" title="<?= e($label) ?>">
                    <input type="radio" name="ref" value="<?= e($k) ?>"
                           data-autosubmit="<?= $k === 'custom' ? '0' : '1' ?>"
                           <?= $k === $currentRef ? 'checked' : '' ?>>
                    <span><?= e($refShort[$k] ?? $label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="reappro-dates" id="ref-custom" <?= $currentRef === 'custom' ? '' : 'hidden' ?>>
        <div>
            <label class="field-label" for="du">Du</label>
            <input type="date" name="du" id="du" value="<?= e($du) ?>">
        </div>
        <div>
            <label class="field-label" for="au">Au</label>
            <input type="date" name="au" id="au" value="<?= e($au) ?>">
        </div>
    </div>

    <div class="reappro-field">
        <label class="field-label" for="period">Couvrir pour</label>
        <select name="period" id="period" data-autosubmit="1">
            <?php foreach ($periods as $k => $p): ?>
                <option value="<?= e($k) ?>" <?= $k === $currentPeriod ? 'selected' : '' ?>><?= e($p['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <button type="submit" class="btn btn-primary btn-sm">Appliquer</button>

    <span class="reappro-meta" title="Période analysée : bornes et jours d'ouverture (lundi-vendredi) réels">
        <?= e(reorder_date_fr($refFrom)) ?> → <?= e(reorder_date_fr($refTo)) ?>
        · <?= (int) $refOpenDays ?> j d'ouverture (sam.-dim. fermés)
    </span>
</form>

<?php if ($alerts > 0): ?>
    <div class="alert alert-warning">
        <strong><?= $alerts ?></strong> produit<?= $alerts > 1 ? 's ont' : ' a' ?> un stock faible (autonomie &lt; 7 jours) — à racheter en priorité.
    </div>
<?php endif; ?>
<?php if ($uncounted > 0): ?>
    <div class="alert alert-info">
        <strong><?= $uncounted ?></strong> produit<?= $uncounted > 1 ? 's jamais' : ' jamais' ?> compté<?= $uncounted > 1 ? 's' : '' ?> en inventaire : « à commander » couvre le besoin complet.<?php if ($canInventory): ?> <a href="<?= e(url('/admin/compta/inventaire')) ?>">Faire un comptage →</a><?php endif ?>
    </div>
<?php endif; ?>

<div class="card surface glass table-wrap">
    <style>
.reorder-table mark { background: rgba(72,189,211,0.32); color: inherit; border-radius: 3px; padding: 0 1px; }
.reorder-reset {
    border: 1px solid var(--border-strong); background: var(--card); color: var(--muted);
    border-radius: 8px; width: 32px; height: 36px; cursor: pointer; font-size: 0.9rem; line-height: 1;
    flex-shrink: 0;
}
.reorder-reset:hover { color: var(--foreground); border-color: var(--primary); }
</style>
    <div class="costs-toolbar" style="margin-bottom:0;border:none;background:none;padding:1rem 1.1rem 0;">
        <div class="search-box">
            <input type="text" id="reorder-search" placeholder="Rechercher un produit…" autocomplete="off">
        </div>
        <select id="reorder-cat" aria-label="Filtrer par catégorie">
            <option value="">Toutes les catégories</option>
        </select>
        <select id="reorder-state" aria-label="Filtrer par état">
            <option value="">Tous les états</option>
            <option value="reorder">À racheter</option>
            <option value="unknown">À compter</option>
            <option value="ok">OK</option>
        </select>
        <select id="reorder-sort" aria-label="Trier par">
            <option value="to_order">À commander ↓</option>
            <option value="name">Produit (A→Z)</option>
            <option value="name-desc">Produit (Z→A)</option>
            <option value="cat">Catégorie (A→Z)</option>
            <option value="qty-desc">Vendus ↓</option>
            <option value="month-desc">Conso / mois ↓</option>
            <option value="need-desc">Besoin ↓</option>
            <option value="stock-asc">Stock ↑</option>
            <option value="stock-desc">Stock ↓</option>
            <option value="autonomy-asc">Autonomie ↑</option>
        </select>
        <button type="button" id="reorder-reset" class="reorder-reset" hidden title="Réinitialiser les filtres"></button>
        <span class="costs-count muted" id="reorder-count"></span>
    </div>

    <table class="table reorder-table">
        <thead>
            <tr>
                <th>Produit</th>
                <th>Catégorie</th>
                <th class="th-num" title="Quantité totale vendue sur la période analysée">Vendus<br>(période)</th>
                <th class="th-num" title="Stock théorique de l'inventaire : dernier comptage + achats − ventes − pertes">Stock théorique</th>
                <th class="th-num" title="Conso moyenne par jour d'ouverture (lun-ven)">Conso / jour<br><small>(ouv.)</small></th>
                <th class="th-num" title="Conso / jour × 5">Conso /<br>semaine</th>
                <th class="th-num" title="Conso / jour × 21,77 (jours ouvrés moyens)">Conso /<br>mois</th>
                <th class="th-num" title="Jours d'ouverture avant rupture (stock ÷ conso / jour)">Autonomie</th>
                <th class="th-num" title="Besoin estimé pour couvrir l'horizon choisi">Besoin<br>(<?= e($periods[$currentPeriod]['label']) ?>)</th>
                <th class="th-num" title="Besoin − stock théorique (minimum 0)">À commander</th>
                <th class="th-num" title="À commander × coût de revient du lot en cours">Coût ligne</th>
                <th>État</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $key = (string) $r['name'];
                $hasStock = $r['stock'] !== null;
                $stockVal = $hasStock ? (int) $r['stock'] : null;
                $autonomy = $r['autonomy'] !== null ? (int) $r['autonomy'] : null;
            ?>
                <tr class="<?= !empty($r['is_alert']) ? 'row-alert' : '' ?>"
                    data-name="<?= e(strtolower($key)) ?>"
                    data-cat="<?= e(strtolower((string) $r['category'])) ?>"
                    data-state="<?= e((string) $r['state']) ?>"
                    data-hasstock="<?= $hasStock ? '1' : '0' ?>"
                    data-stock="<?= $hasStock ? (int) $stockVal : 0 ?>"
                    data-qty="<?= (int) $r['qty'] ?>"
                    data-month="<?= (float) $r['avg_month'] ?>"
                    data-need="<?= (int) $r['need'] ?>"
                    data-toorder="<?= (int) $r['to_order'] ?>"
                    data-unitcost="<?= $r['unit_cost'] !== null ? e((string) $r['unit_cost']) : '' ?>"
                    data-autonomy="<?= $autonomy ?? 99999 ?>">
                    <td><strong><?= e($key) ?></strong></td>
                    <td><?= e((string) $r['category']) ?></td>
                    <td class="num muted"><?= (int) $r['qty'] ?></td>
                    <td class="num">
                        <?php if ($hasStock): ?>
                            <strong class="stock-value<?= $stockVal <= 0 ? ' is-out' : '' ?>"
                                    title="<?= $stockVal < 0 ? 'Stock négatif : survente (ventes sans stock reconstitué)' : 'Stock théorique de l\'inventaire' ?>">
                                <?= $stockVal ?>
                            </strong>
                            <?php if (!empty($r['counted_at'])): ?>
                                <span class="cell-sub" title="Date du dernier comptage physique">compté le <?= e(reorder_date_fr(substr((string) $r['counted_at'], 0, 10))) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-muted" title="Jamais compté en inventaire : fais un comptage pour affiner">À compter</span>
                        <?php endif; ?>
                    </td>
                    <td class="num muted"><?= reorder_qty((float) $r['avg_day']) ?></td>
                    <td class="num muted"><?= reorder_qty((float) $r['avg_week']) ?></td>
                    <td class="num muted"><?= reorder_qty((float) $r['avg_month']) ?></td>
                    <td class="num">
                        <?php if (!$hasStock || $autonomy === null): ?>
                            <span class="auto-pill auto-none" title="Stock inconnu">—</span>
                        <?php elseif ($r['avg_day'] <= 0): ?>
                            <span class="auto-pill auto-none" title="Aucune vente sur la période">∞</span>
                        <?php else: ?>
                            <span class="auto-pill <?= reorder_autonomy_class($autonomy, true) ?>"
                                  title="<?= $autonomy ?> jour(s) d'ouverture avant rupture">
                                <?= $autonomy ?> j
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= (int) $r['need'] ?></td>
                    <td class="num to-order-cell">
                        <?php if ((int) $r['to_order'] > 0): ?>
                            <strong style="color:var(--primary)" title="<?= !$hasStock ? 'Stock jamais compté : le besoin complet est proposé' : 'Besoin − stock théorique' ?>"><?= (int) $r['to_order'] ?></strong>
                        <?php else: ?>
                            <span class="muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="num cost-line-cell">
                        <?php if ($r['order_cost'] !== null && (int) $r['to_order'] > 0): ?>
                            <strong><?= e(formatPrice((float) $r['order_cost'])) ?></strong>
                            <span class="cost-sub"><?= e(formatPrice((float) $r['unit_cost'], 3)) ?> / u</span>
                        <?php elseif ($r['unit_cost'] === null): ?>
                            <span class="muted" title="Coût de revient non saisi">—</span>
                        <?php else: ?>
                            <span class="muted">0 €</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($r['state'] ?? '') === 'reorder'): ?>
                            <span class="badge badge-warning">À racheter</span>
                        <?php elseif (($r['state'] ?? '') === 'unknown'): ?>
                            <span class="badge badge-muted">À compter</span>
                        <?php else: ?>
                            <span class="badge badge-success">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="12" class="muted">Aucun produit à analyser sur cette période. Importe d'abord un rapport SumUp.</td></tr>
            <?php endif; ?>
        </tbody>
        <?php if ($rows !== []): ?>
        <tfoot>
            <tr>
                <th colspan="9" style="text-align:right">Total à commander (<?= e($periods[$currentPeriod]['label']) ?>) :</th>
                <th class="num"><strong id="reorder-total" style="color:var(--primary)"><?= (int) $totalToOrder ?></strong></th>
                <th class="num">
                    <strong id="reorder-total-cost" style="color:var(--primary)">≈ <?= e(formatPrice($totalCost)) ?></strong>
                    <?php if ($missingCost > 0): ?>
                        <span class="muted" style="display:block;font-weight:400;font-size:0.72rem;" title="Produits à commander sans coût de revient saisi">
                            +<?= (int) $missingCost ?> sans coût
                        </span>
                    <?php endif; ?>
                </th>
                <th></th>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<p class="card-meta">
    Cafétéria ouverte du lundi au vendredi : les moyennes sont ramenées aux <strong>jours d'ouverture réels</strong> de la période analysée.
    Conso / jour = vendus ÷ jours d'ouverture · Conso / semaine = conso / jour × 5 · Conso / mois = conso / jour × 21,77.
    « À commander » = besoin sur l'horizon de couverture − <strong>stock théorique</strong> (minimum 0 ; un stock négatif majore la commande).
    Stock théorique = dernier comptage + achats − ventes − pertes (mis à jour par <strong>Inventaire</strong>, <strong>Achats</strong> et <strong>Pertes</strong>).
    « Coût ligne » = à commander × coût de revient du lot en cours · le total ≈ prix d'achat du panier (produits sans coût saisi exclus, comptés sous le total).
</p>

<script>
(function () {
    // Barre de filtres : les pastilles/selects soumettent seuls, sauf
    // « Perso. » qui révèle d'abord les bornes de dates.
    var form = document.getElementById('reappro-filters');
    var custom = document.getElementById('ref-custom');
    if (!form) return;

    Array.prototype.forEach.call(form.querySelectorAll('[data-autosubmit]'), function (el) {
        el.addEventListener('change', function () {
            if (el.getAttribute('data-autosubmit') === '1') {
                form.submit();
            }
        });
    });

    Array.prototype.forEach.call(form.querySelectorAll('input[name="ref"]'), function (radio) {
        radio.addEventListener('change', function () {
            if (custom) {
                custom.hidden = radio.value !== 'custom' || !radio.checked;
            }
            if (radio.value === 'custom' && radio.checked) {
                var du = document.getElementById('du');
                if (du) du.focus();
            }
        });
    });

    // Saisie manuelle d'une date → bascule immédiate sur « Perso. »,
    // avec application automatique dès que les deux bornes sont remplies.
    Array.prototype.forEach.call(['du', 'au'], function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('change', function () {
            var radio = form.querySelector('input[name="ref"][value="custom"]');
            if (radio) radio.checked = true;
            if (custom) custom.hidden = false;
            var du = document.getElementById('du');
            var au = document.getElementById('au');
            if (du && au && du.value && au.value) {
                form.submit();
            }
        });
    });
})();
</script>

<script>
(function () {
    var search   = document.getElementById('reorder-search');
    var catSel   = document.getElementById('reorder-cat');
    var stateSel = document.getElementById('reorder-state');
    var sortSel  = document.getElementById('reorder-sort');
    var countEl  = document.getElementById('reorder-count');
    var resetBtn = document.getElementById('reorder-reset');
    var tbody    = document.querySelector('.reorder-table tbody');
    var rows     = Array.prototype.slice.call(document.querySelectorAll('.reorder-table tbody tr[data-name]'));
    var totalEl  = document.getElementById('reorder-total');
    var totalCostEl = document.getElementById('reorder-total-cost');
    var total = rows.length;
    if (!total || !search) return;

    // Libellés d'origine (casse réelle) pour la surbrillance.
    rows.forEach(function (tr) {
        var strong = tr.querySelector('td strong');
        tr._nameRaw = strong ? strong.textContent : '';
    });

    // Ligne « aucun résultat ».
    var emptyRow = document.createElement('tr');
    emptyRow.className = 'reorder-empty';
    emptyRow.hidden = true;
    emptyRow.innerHTML = '<td colspan="12" class="muted" style="text-align:center;padding:2.25rem 1rem">Aucun produit ne correspond à ces filtres.</td>';
    if (tbody) tbody.appendChild(emptyRow);

    function norm(s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim(); }
    function num(tr, attr) { return parseFloat(tr.getAttribute(attr)) || 0; }
    function fmtPrice(v) { return v.toFixed(2).replace('.', ',') + ' €'; }
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
    function getParam(name) {
        var m = new RegExp('[?&]' + name + '=([^&#]*)').exec(window.location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
    }

    // Restaure les filtres depuis l'URL (?q=&cat=&etat=&tri=).
    if (search.value === '') search.value = getParam('q');
    if (catSel && getParam('cat')) { catSel.value = getParam('cat'); if (catSel.value !== getParam('cat')) catSel.value = ''; }
    if (stateSel && getParam('etat')) stateSel.value = getParam('etat');
    if (sortSel && getParam('tri')) sortSel.value = getParam('tri');

    // Remplit le filtre catégorie avec les catégories présentes.
    if (catSel) {
        var cats = {};
        rows.forEach(function (tr) { cats[tr.getAttribute('data-cat') || 'sans-categorie'] = true; });
        Object.keys(cats).sort().forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c;
            opt.textContent = c === 'sans-categorie' ? '(sans catégorie)' : c;
            catSel.appendChild(opt);
        });
    }

    function recomputeTotal() {
        var sum = 0, cost = 0;
        rows.forEach(function (tr) {
            if (tr.style.display === 'none') return;
            var toOrder = parseInt(tr.getAttribute('data-toorder'), 10) || 0;
            sum += toOrder;
            var unit = parseFloat(tr.getAttribute('data-unitcost'));
            if (!isNaN(unit)) cost += toOrder * unit;
        });
        if (totalEl) totalEl.textContent = String(sum);
        if (totalCostEl) totalCostEl.textContent = '≈ ' + fmtPrice(cost);
    }

    function sortRows() {
        var sort = sortSel ? sortSel.value : 'to_order';
        rows.sort(function (a, b) {
            var an, bn;
            switch (sort) {
                case 'name':       return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                case 'name-desc':  return b.getAttribute('data-name').localeCompare(a.getAttribute('data-name'));
                case 'cat':        return (a.getAttribute('data-cat') || '').localeCompare(b.getAttribute('data-cat') || '');
                case 'qty-desc':   return num(b, 'data-qty') - num(a, 'data-qty');
                case 'month-desc': return num(b, 'data-month') - num(a, 'data-month');
                case 'need-desc':  return num(b, 'data-need') - num(a, 'data-need');
                case 'stock-asc':
                    if (a.getAttribute('data-hasstock') !== b.getAttribute('data-hasstock')) {
                        return a.getAttribute('data-hasstock') === '1' ? -1 : 1;
                    }
                    return num(a, 'data-stock') - num(b, 'data-stock');
                case 'stock-desc':
                    if (a.getAttribute('data-hasstock') !== b.getAttribute('data-hasstock')) {
                        return a.getAttribute('data-hasstock') === '1' ? -1 : 1;
                    }
                    return num(b, 'data-stock') - num(a, 'data-stock');
                case 'autonomy-asc':
                    an = num(a, 'data-autonomy'); bn = num(b, 'data-autonomy');
                    return an - bn;
                default:           return num(b, 'data-toorder') - num(a, 'data-toorder');
            }
        });
        if (tbody) {
            var frag = document.createDocumentFragment();
            rows.forEach(function (tr) { frag.appendChild(tr); });
            tbody.appendChild(frag);
        }
    }

    // Surligne dans le nom les mots recherchés (recherche insensible à la casse).
    function highlight(tr, tokens) {
        var strong = tr.querySelector('td strong');
        if (!strong) return;
        var raw = tr._nameRaw;
        if (!tokens.length) { strong.textContent = raw; return; }
        var lower = raw.toLowerCase();
        var html = '', i = 0;
        while (i < raw.length) {
            var hit = -1, hitLen = 0;
            tokens.forEach(function (t) {
                if (t.length < 2) return;
                var pos = lower.indexOf(t, i);
                if (pos !== -1 && (hit === -1 || pos < hit)) { hit = pos; hitLen = t.length; }
            });
            if (hit === -1) { html += esc(raw.slice(i)); break; }
            html += esc(raw.slice(i, hit)) + '<mark>' + esc(raw.substr(hit, hitLen)) + '</mark>';
            i = hit + hitLen;
        }
        strong.innerHTML = html;
    }

    function apply() {
        var q = norm(search.value);
        var cat = catSel ? catSel.value : '';
        var state = stateSel ? stateSel.value : '';
        var tokens = q ? q.split(/\s+/) : [];
        var shown = 0;
        rows.forEach(function (tr) {
            var hay = norm(tr.getAttribute('data-name') + ' ' + (tr.getAttribute('data-cat') || ''));
            var okSearch = !tokens.length || tokens.every(function (t) { return hay.indexOf(t) !== -1; });
            var okCat = cat === '' || tr.getAttribute('data-cat') === cat;
            var okState = state === '' || tr.getAttribute('data-state') === state;
            var visible = okSearch && okCat && okState;
            tr.style.display = visible ? '' : 'none';
            if (visible) { shown++; highlight(tr, tokens); }
            else { var strong = tr.querySelector('td strong'); if (strong) strong.textContent = tr._nameRaw; }
        });
        if (emptyRow) emptyRow.hidden = shown > 0;
        if (countEl) countEl.textContent = shown + ' / ' + total + ' produit' + (total > 1 ? 's' : '');
        if (resetBtn) resetBtn.hidden = (search.value.trim() === '' && cat === '' && state === '');
        sortRows();
        recomputeTotal();

        // Synchronise l'URL (filtres partageables / rechargements).
        if (window.URLSearchParams && window.history && window.history.replaceState) {
            var sp = new URLSearchParams(window.location.search);
            [['q', search.value.trim()], ['cat', cat], ['etat', state], ['tri', (sortSel && sortSel.value !== 'to_order') ? sortSel.value : '']]
                .forEach(function (p) { if (p[1]) { sp.set(p[0], p[1]); } else { sp.delete(p[0]); } });
            var qs = sp.toString();
            window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
        }

        // Miroirs cachés dans le formulaire de période (GET) : les filtres
        // survivent au changement de période.
        [['f-q', search.value.trim()], ['f-cat', cat], ['f-etat', state], ['f-tri', sortSel ? sortSel.value : '']]
            .forEach(function (p) { var el = document.getElementById(p[0]); if (el) el.value = p[1]; });
    }

    var debounce = null;
    search.addEventListener('input', function () {
        if (debounce) clearTimeout(debounce);
        debounce = setTimeout(apply, 120);
    });
    if (catSel) catSel.addEventListener('change', apply);
    if (stateSel) stateSel.addEventListener('change', apply);
    if (sortSel) sortSel.addEventListener('change', apply);
    if (resetBtn) resetBtn.addEventListener('click', function () {
        search.value = '';
        if (catSel) catSel.value = '';
        if (stateSel) stateSel.value = '';
        if (sortSel) sortSel.value = 'to_order';
        apply();
        search.focus();
    });
    apply();
})();
</script>
