<?php

declare(strict_types=1);

/**
 * Dashboard Comptabilité — 3 niveaux pour non-spécialistes.
 *
 *  NIVEAU 1 : verdict, 4 cartes, bloc « À faire » (cœur Dashboard pur).
 *  NIVEAU 2 : sections détaillées repliées (<details>, fermées par défaut).
 *  NIVEAU 3 : liens vers les outils experts. Aucune donnée supprimée :
 *  les sections historiques sont réorganisées dans les niveaux 2/3.
 *
 * @var array<string,mixed> $user
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 * @var array{ca:float,profit:float,qty:int,ca_products:float} $agg
 * @var array<string,float> $split
 * @var int $txCount  Nombre de transactions distinctes sur la période.
 * @var float $cardFee  Frais SumUp estimés sur les ventes carte de la période.
 * @var float $cardNet  Net estimé des ventes carte après frais.
 * @var string $feeRate Taux de commission formaté (ex. « 1,75 »).
 * @var list<array<string,mixed>> $top
 * @var list<array<string,mixed>> $byCategory
 * @var int $reorderAlerts
 * @var float $margin
 * @var float $expenseTtc
 * @var int $expenseCount
 * @var float $netResult
 * @var float $noCostCa
 * @var list<array<string,mixed>> $vat  Ventilation TVA par taux (rate, ht, vat, ttc).
 * @var float $vatTotal
 * @var list<array<string,mixed>> $lossLeaders
 * @var list<array<string,mixed>> $invGaps
 * @var ?string $lastImport
 * @var ?int $daysSinceImport
 * @var array{phrase:string, code:string, objectif_phrase:?string} $verdict
 * @var list<array{cle:string, label:string, valeur:string, valeur_num:float, repere:?string, repere_code:string, aide:string, ligne2:?string}> $cartes
 * @var list<array{cle:string, urgence:int, texte:string, lien:string, urgence_label:string}> $afaire
 * @var bool $periode_complete
 * @var bool $precedent_complet
 * @var ?string $prev_from
 * @var ?string $prev_to
 * @var ?float $objectif
 */

// Part du CA produits sans coût de revient connu (fiabilité du bénéfice).
$noCostPct = $agg['ca_products'] > 0 ? $noCostCa / $agg['ca_products'] * 100 : 0;

// Panneau « Suivi et alertes techniques » (niveau 2) : chaque entrée est un
// niveau + un HTML sûr (les parties dynamiques passent par e(), les liens
// par url()).
$alerts = [];

if ($daysSinceImport === null) {
    $alerts[] = ['level' => 'warning', 'html' => 'Aucun import SumUp pour l\'instant.'];
} elseif ($daysSinceImport >= 14) {
    $alerts[] = [
        'level' => 'danger',
        'html'  => sprintf(
            'Dernier import SumUp il y a %d jours (%s)',
            $daysSinceImport,
            e(formatDate($lastImport))
        ),
    ];
} else {
    $alerts[] = ['level' => 'ok', 'html' => 'Dernier import SumUp : ' . e(formatDateTime($lastImport))];
}

foreach (array_slice($lossLeaders, 0, 5) as $l) {
    $alerts[] = [
        'level' => 'danger',
        'html'  => sprintf(
            '%s : vendu %.2f € contre un coût de %.2f € %s',
            e((string) $l['product']),
            (float) $l['avg_price'],
            (float) $l['cost'],
            '<a href="' . e(url('/admin/compta/produits')) . '">voir les produits →</a>'
        ),
    ];
}

if ($invGaps !== []) {
    // Lien inventaire réservé au groupe Système (Fondateur) ; les rôles
    // compta (TRESORERIE notamment) reçoivent 403 sur la page.
    $invLink = \App\Core\Permissions::isSystemAdmin()
        ? ' <a href="' . e(url('/admin/compta/inventaire')) . '">voir l\'inventaire →</a>'
        : '';
    $alerts[] = [
        'level' => 'warning',
        'html'  => sprintf(
            '%d écart(s) d\'inventaire sur 30 j (pertes/casses ?)%s',
            count($invGaps),
            $invLink
        ),
    ];
}

if ($reorderAlerts > 0) {
    $alerts[] = [
        'level' => 'warning',
        'html'  => sprintf(
            '%d produit(s) à racheter %s',
            $reorderAlerts,
            '<a href="' . e(url('/admin/compta/reappro')) . '">voir le réappro →</a>'
        ),
    ];
}

// Couleurs du verdict et des repères (codes du cœur Dashboard).
$verdictBadges = [
    'ok'        => 'badge-success',
    'attention' => 'badge-warning',
    'alerte'    => 'badge-danger',
    'neutre'    => 'badge-muted',
];
$repereBadges = [
    'ok'        => 'badge-success',
    'attention' => 'badge-warning',
    'alerte'    => 'badge-danger',
    'neutre'    => 'badge-muted',
];

// Lignes de TVA non nulles (les lignes à 0 € sont masquées, niveau 2).
$vatLines = array_values(array_filter(
    $vat,
    static fn(array $v): bool => abs((float) ($v['vat'] ?? 0)) > 0.005
));
?>
<style>
/* Local au dashboard compta (house pattern : style de la vue). */
.dashboard-verdict { padding: 1.15rem 1.35rem; }
.dashboard-verdict-phrase { margin: 0; font-size: 1.18rem; font-weight: 600; display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; }
.dashboard-verdict-alerte .dashboard-verdict-phrase { color: var(--accent-danger, #ef4444); }
.dashboard-verdict-attention .dashboard-verdict-phrase { color: var(--accent-warning, #f59e0b); }
.dashboard-verdict-ok .dashboard-verdict-phrase { color: var(--accent-success, #22c55e); }
.dashboard-verdict-objectif { margin: 0.45rem 0 0; font-size: 0.95rem; }
.aide-mark { display: inline-block; border: 1px solid currentColor; border-radius: 50%; width: 1em; height: 1em; line-height: 1; text-align: center; font-size: 0.75em; opacity: 0.65; vertical-align: super; margin-left: 0.15rem; }
.kpi-repere { margin: 0.35rem 0 0; }
.kpi-repere-none { color: var(--muted); }
.afaire-list { list-style: none; margin: 0; padding: 0; }
.afaire-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.55rem 0; border-top: 1px solid rgba(128, 128, 128, 0.15); flex-wrap: wrap; }
.afaire-item:first-child { border-top: 0; }
.afaire-item .afaire-texte { flex: 1 1 auto; min-width: 200px; }
</style>

<div class="admin-actions">
    <a class="btn btn-primary" href="<?= e(url('/admin/compta/import')) ?>">Importer un CSV</a>
</div>
<p class="muted">Les encaissements proviennent des rapports CSV SumUp importés manuellement — aucun appel API temps réel. Importe un nouveau rapport pour actualiser.</p>

<div class="admin-actions">
    <?php require AEIC_VIEWS . '/admin/compta/_period_bar.php'; ?>
</div>

<!-- ══════════════════════════ NIVEAU 1 ══════════════════════════ -->

<?php $vBadge = $verdictBadges[$verdict['code']] ?? 'badge-muted'; ?>
<div class="card surface glass dashboard-verdict dashboard-verdict-<?= e($verdict['code']) ?>">
    <p class="dashboard-verdict-phrase">
        <span class="badge <?= e($vBadge) ?>"><?= [
            'ok'        => 'Ça va',
            'attention' => 'Attention',
            'alerte'    => 'Urgent',
            'neutre'    => 'Info',
        ][$verdict['code']] ?? 'Info' ?></span>
        <?= e($verdict['phrase']) ?>
    </p>
    <?php if (!empty($verdict['objectif_phrase'])): ?>
        <p class="dashboard-verdict-objectif muted"
           title="Objectif calculé au prorata : objectif hebdomadaire × jours de la période ÷ 7 (voir Paramètres)."><?= e($verdict['objectif_phrase']) ?></p>
    <?php endif; ?>
</div>

<div class="compta-kpis">
    <?php foreach ($cartes as $c): ?>
        <div class="card surface glass kpi">
            <p class="kpi-label">
                <span title="<?= e($c['aide']) ?>"><?= e($c['label']) ?> <span class="aide-mark">?</span></span>
            </p>
            <p class="kpi-value <?= (float) $c['valeur_num'] >= 0 ? '' : 'is-negative' ?>"><?= e($c['valeur']) ?></p>
            <?php if ($c['cle'] === 'profit' && !empty($c['ligne2'])): ?>
                <p class="kpi-sub"><?= e($c['ligne2']) ?></p>
            <?php endif; ?>
            <p class="kpi-repere">
                <?php if ($c['repere'] !== null && $c['repere'] !== ''): ?>
                    <span class="badge <?= e($repereBadges[$c['repere_code']] ?? 'badge-muted') ?>"><?= e($c['repere']) ?></span>
                <?php else: ?>
                    <span class="kpi-repere-none">—</span>
                <?php endif; ?>
            </p>
        </div>
    <?php endforeach; ?>
</div>

<div class="card surface glass">
    <h2 class="card-title" title="Les points qui méritent une action, du plus urgent au moins urgent.">À faire <span class="aide-mark">?</span></h2>
    <?php if ($afaire !== []): ?>
        <ul class="afaire-list">
            <?php foreach ($afaire as $item): ?>
                <li class="afaire-item">
                    <span class="badge <?= (int) $item['urgence'] >= 3 ? 'badge-danger' : ((int) $item['urgence'] === 2 ? 'badge-warning' : 'badge-muted') ?>">
                        <?= e($item['urgence_label']) ?>
                    </span>
                    <span class="afaire-texte"><?= e($item['texte']) ?></span>
                    <a href="<?= e($item['lien']) ?>" class="btn btn-outline btn-sm">Ouvrir →</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted">Rien à faire : tout est à jour.</p>
    <?php endif; ?>
</div>

<!-- ══════════════════════════ NIVEAU 2 ══════════════════════════ -->

<details class="card surface glass">
    <summary>💳 Carte et liquide</summary>
    <ul class="split-list">
        <li>
            <span>Carte</span>
            <strong><?= e(formatPrice($split['CARTE'] ?? 0)) ?></strong>
        </li>
        <li>
            <span>Liquide</span>
            <strong><?= e(formatPrice($split['LIQUIDE'] ?? 0)) ?></strong>
        </li>
    </ul>
    <?php
    $total = ($split['CARTE'] ?? 0) + ($split['LIQUIDE'] ?? 0);
    $cardPct = $total > 0 ? round(($split['CARTE'] ?? 0) / $total * 100) : 0;
    ?>
    <div class="split-bar" title="Carte vs Liquide">
        <div class="split-bar-card" style="width:<?= e((string) $cardPct) ?>%"></div>
    </div>
    <p class="muted"><?= e((string) $txCount) ?> transaction<?= $txCount > 1 ? 's' : '' ?> sur la période</p>
    <?php if (($split['CARTE'] ?? 0) > 0): ?>
        <p class="muted">
            <span title="Commission estimée de SumUp sur les paiements par carte : taux × ventes carte. Estimation, jamais prélevée ici.">
                Frais SumUp estimés ≈ <strong>−<?= e(formatPrice($cardFee)) ?></strong>
                (<?= e($feeRate) ?> %) · net carte ≈ <strong><?= e(formatPrice($cardNet)) ?></strong> <span class="aide-mark">?</span>
            </span>
        </p>
    <?php endif; ?>
    <p class="muted">
        <span title="TVA collectée sur les ventes de la période, ventilée par taux (20 %, 10 %, 5,5 %…) — à reverser à l'État.">
            TVA collectée sur la période : <strong><?= e(formatPrice($vatTotal)) ?></strong> <span class="aide-mark">?</span>
        </span>
    </p>
    <?php if ($vatLines !== []): ?>
        <table class="table">
            <thead><tr><th>Taux</th><th>HT</th><th>TVA</th><th>TTC</th></tr></thead>
            <tbody>
                <?php foreach ($vatLines as $v): ?>
                    <tr>
                        <td><?= e((string) $v['rate']) ?> %</td>
                        <td><?= e(formatPrice($v['ht'])) ?></td>
                        <td><?= e(formatPrice($v['vat'])) ?></td>
                        <td><?= e(formatPrice($v['ttc'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p class="muted">Pas de TVA collectée sur la période.</p>
    <?php endif; ?>
</details>

<details class="card surface glass">
    <summary>🏷️ Par catégorie</summary>
    <table class="table">
        <thead><tr><th>Catégorie</th><th>CA</th><th>Bénéfice</th></tr></thead>
        <tbody>
            <?php foreach ($byCategory as $c): ?>
                <tr>
                    <td><?= e($c['category']) ?></td>
                    <td><?= e(formatPrice($c['ca'])) ?></td>
                    <td><?= e(formatPrice($c['profit'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($byCategory === []): ?>
                <tr><td colspan="3" class="muted">Aucune vente sur cette période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</details>

<details class="card surface glass">
    <summary>🏆 Top produits</summary>
    <table class="table">
        <thead><tr><th>Produit</th><th>Qté</th><th>CA</th></tr></thead>
        <tbody>
            <?php foreach ($top as $t): ?>
                <tr>
                    <td><strong><?= e($t['label']) ?></strong></td>
                    <td><?= e((string) $t['qty']) ?></td>
                    <td><?= e(formatPrice($t['ca'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($top === []): ?>
                <tr><td colspan="3" class="muted">Aucune vente sur cette période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</details>

<details class="card surface glass">
    <summary>🧾 Dépenses</summary>
    <p>
        <span title="Vos charges saisies (FRAIS, MATERIEL…). Les achats de stock ne sont PAS ici : ils sont déjà déduits de la marge.">
            Total des dépenses sur la période : <strong><?= e(formatPrice($expenseTtc)) ?></strong>
            (<?= e((string) $expenseCount) ?> dépense<?= $expenseCount > 1 ? 's' : '' ?>) <span class="aide-mark">?</span>
        </span>
    </p>
    <p><a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/depenses')) ?>">Gérer les dépenses →</a></p>
</details>

<details class="card surface glass">
    <summary>🛠️ Suivi et alertes techniques
        <span class="aide-mark" title="Chiffres et contrôles de fiabilité : fiabilité des coûts, marge détaillée, imports, alertes réappro.">?</span>
    </summary>
    <p class="muted">
        CA produits identifiés : <strong><?= e(formatPrice($agg['ca_products'])) ?></strong> (hors montants personnalisés)
        · marge : <strong><?= e(number_format($margin, 1, ',', ' ')) ?> %</strong>
        · CA sans coût : <strong><?= e(number_format($noCostPct, 1, ',', ' ')) ?> %</strong><?php if (\App\Core\Permissions::isSystemAdmin()): ?> — <a href="<?= e(url('/admin/compta/couts')) ?>">compléter →</a><?php endif; ?>
        · résultat net : <strong><?= e(formatPrice($netResult)) ?></strong>
        <span title="La marge des ventes moins les dépenses saisies : le « résultat » que la cafétéria a réellement gagné sur la période."><span class="aide-mark">?</span></span>
    </p>
    <?php if ($alerts !== []): ?>
        <ul class="alert-list">
            <?php foreach ($alerts as $a): ?>
                <li class="alert-item alert-<?= e($a['level']) ?>"><?= $a['html'] ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted">Tout est à jour</p>
    <?php endif; ?>
</details>

<!-- ══════════════════════════ NIVEAU 3 ══════════════════════════ -->

<div class="admin-actions">
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/analytics')) ?>">Analytics</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/ledger')) ?>">Livre comptable</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/annuel')) ?>">Bilan annuel</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/budgets')) ?>">Budgets</a>
    <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/achats')) ?>">Achats &amp; stock</a>
    <?php if (\App\Core\Permissions::isSystemAdmin()): ?>
        <a class="btn btn-outline btn-sm" href="<?= e(url('/admin/compta/inventaire')) ?>">Inventaire</a>
    <?php endif; ?>
</div>
