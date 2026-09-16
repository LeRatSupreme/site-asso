<?php

declare(strict_types=1);

use App\Models\Loss;

/**
 * @var array<string,mixed> $user
 * @var array{preset:string,from:?string,to:?string} $period
 * @var array<string,string> $periodOptions
 * @var list<array<string,mixed>> $rows
 * @var array{qty:int,value:float} $agg
 * @var list<array{reason:string,qty:int,value:float}> $byReason
 * @var float $value30
 * @var list<string> $products
 */

// Libellés français des motifs (ordre = Loss::REASONS).
$reasonLabels = [
    'CASSE'  => 'Casse',
    'PERIME' => 'Périmé',
    'VOL'    => 'Vol',
    'OFFERT' => 'Offert',
    'ERREUR' => 'Erreur de saisie',
    'DIVERS' => 'Divers',
];

// Motifs « chauds » : rouge pour vol/casse, orange pour périmé, neutre sinon.
$reasonBadges = [
    'VOL'    => 'badge badge-danger',
    'CASSE'  => 'badge badge-danger',
    'PERIME' => 'badge badge-warning',
];

$topReason = $byReason[0] ?? null;
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité</p>
        <h1 class="page-title">Pertes</h1>
        <p class="muted">Casse, périmé, vol, offert... Chaque perte est <strong>valorisée au coût</strong> et <strong>déduite du stock théorique</strong> : elle explique un écart d'inventaire au lieu de le laisser mystérieux.</p>
    </div>
</div>

<div class="admin-actions">
    <?php require AEIC_VIEWS . '/admin/compta/_period_bar.php'; ?>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Valeur des pertes</p>
        <p class="kpi-value <?= $agg['value'] > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice($agg['value'])) ?></p>
        <p class="kpi-sub"><?= (int) $agg['qty'] ?> unité<?= (int) $agg['qty'] > 1 ? 's' : '' ?> perdue<?= (int) $agg['qty'] > 1 ? 's' : '' ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Pertes 30 jours</p>
        <p class="kpi-value <?= $value30 > 0 ? 'is-negative' : '' ?>"><?= e(formatPrice($value30)) ?></p>
        <p class="kpi-sub">fenêtre glissante 30 jours</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Top motif</p>
        <?php if ($topReason !== null): ?>
            <p class="kpi-value"><?= e($reasonLabels[$topReason['reason']] ?? $topReason['reason']) ?></p>
            <p class="kpi-sub"><?= e(formatPrice($topReason['value'])) ?> sur la période</p>
        <?php else: ?>
            <p class="kpi-value">—</p>
            <p class="kpi-sub">sur la période</p>
        <?php endif; ?>
    </div>
</div>

<div class="compta-grid">
    <section class="card surface glass">
        <h2 class="card-title">Enregistrer une perte</h2>
        <form method="post" action="<?= e(url('/admin/compta/pertes/save')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label for="lost_at">Date</label>
                <input type="date" id="lost_at" name="lost_at" value="<?= e(date('Y-m-d')) ?>" required>
            </div>

            <div class="field">
                <label for="product_key">Produit</label>
                <input type="text" id="product_key" name="product_key" list="loss-products"
                    placeholder="ex: Coca 33cl" autocomplete="off" required>
                <datalist id="loss-products">
                    <?php foreach ($products as $p): ?>
                        <option value="<?= e($p) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <p class="field-help">Choisis un nom existant (mêmes noms que dans les ventes) pour déduire le bon stock théorique.</p>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="quantity">Quantité</label>
                    <input type="number" id="quantity" name="quantity" value="1" min="1" step="1" required>
                </div>
                <div class="field">
                    <label for="reason">Motif</label>
                    <select id="reason" name="reason">
                        <?php foreach (Loss::REASONS as $r): ?>
                            <option value="<?= e($r) ?>"><?= e($reasonLabels[$r] ?? $r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="note">Note <span class="muted">(optionnel)</span></label>
                <input type="text" id="note" name="note" placeholder="ex: bouteille cassée en réserve">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer la perte</button>
                <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler</button>
            </div>
        </form>
    </section>

    <section class="card surface glass table-wrap">
        <h2 class="card-title">Par motif</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Motif</th>
                    <th class="th-num">Unités</th>
                    <th class="th-num">Valeur</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byReason as $b): ?>
                    <tr>
                        <td><span class="<?= e($reasonBadges[$b['reason']] ?? 'badge') ?>"><?= e($reasonLabels[$b['reason']] ?? $b['reason']) ?></span></td>
                        <td class="num"><?= (int) $b['qty'] ?></td>
                        <td class="num"><strong><?= e(formatPrice((float) $b['value'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byReason === []): ?>
                    <tr><td colspan="3" class="muted">Aucune perte sur cette période.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Journal des pertes</h2>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Produit</th>
                <th class="th-num">Qté</th>
                <th>Motif</th>
                <th class="th-num">Coût unit.</th>
                <th class="th-num">Valeur</th>
                <th>Note</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e(formatDate((string) $r['lost_at'])) ?></td>
                    <td><strong><?= e((string) $r['product_key']) ?></strong></td>
                    <td class="num"><?= (int) $r['quantity'] ?></td>
                    <td><span class="<?= e($reasonBadges[(string) $r['reason']] ?? 'badge') ?>"><?= e($reasonLabels[(string) $r['reason']] ?? (string) $r['reason']) ?></span></td>
                    <td class="num"><?= e(formatPrice((float) $r['unit_cost'], 3)) ?></td>
                    <td class="num"><strong><?= e(formatPrice((float) $r['value'])) ?></strong></td>
                    <td><?= e((string) ($r['note'] ?? '') !== '' ? (string) $r['note'] : '—') ?></td>
                    <td class="row-actions">
                        <form method="post" action="<?= e(url('/admin/compta/pertes/' . rawurlencode((string) $r['id']) . '/delete')) ?>"
                              data-confirm="Supprimer cette perte ? Le stock théorique sera recalculé.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer">🗑</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="muted">Aucune perte sur cette période.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

