<?php

declare(strict_types=1);

/**
 * @var array<string,mixed>       $user
 * @var array<string,mixed>       $event
 * @var array{ca:float,qty:int,avg:float} $stats
 * @var list<array<string,mixed>> $costs
 * @var float                     $costsTotal
 * @var list<array<string,mixed>> $sales
 * @var int|null                  $breakEven
 */

$profit = (float) $stats['ca'] - $costsTotal;
?>
<div class="compta-head">
    <div>
        <p class="eyebrow">Comptabilité → Événements</p>
        <h1 class="page-title"><?= e((string) $event['name']) ?></h1>
        <p class="muted">
            <?php if ((string) $event['date_to'] !== (string) $event['date_from']): ?>
                Du <?= e(formatDate((string) $event['date_from'])) ?> au <?= e(formatDate((string) $event['date_to'])) ?>
            <?php else: ?>
                <?= e(formatDate((string) $event['date_from'])) ?>
            <?php endif; ?>
            — Bénéfice = ventes rattachées − coûts.
        </p>
    </div>
</div>

<div class="compta-kpis">
    <div class="card surface glass kpi">
        <p class="kpi-label">Revenu</p>
        <p class="kpi-value"><?= e(formatPrice((float) $stats['ca'])) ?></p>
        <p class="kpi-sub"><?= (int) $stats['qty'] ?> entrée<?= (int) $stats['qty'] > 1 ? 's' : '' ?> vendue<?= (int) $stats['qty'] > 1 ? 's' : '' ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Prix moyen / entrée</p>
        <p class="kpi-value"><?= e(formatPrice((float) $stats['avg'])) ?></p>
        <p class="kpi-sub">revenu / entrées</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Coûts</p>
        <p class="kpi-value"><?= e(formatPrice($costsTotal)) ?></p>
        <p class="kpi-sub"><?= count($costs) ?> ligne<?= count($costs) > 1 ? 's' : '' ?></p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Bénéfice</p>
        <p class="kpi-value <?= $profit >= 0 ? 'is-positive' : 'is-negative' ?>"><?= e(formatPrice($profit)) ?></p>
        <p class="kpi-sub">ventes rattachées − coûts</p>
    </div>
    <div class="card surface glass kpi">
        <p class="kpi-label">Seuil de rentabilité</p>
        <p class="kpi-value">
            <?php if ($breakEven !== null): ?>
                <?= e(sprintf('%d entrées', $breakEven)) ?><?= (int) $stats['qty'] >= $breakEven ? ' ' : ' ' ?>
            <?php else: ?>
                —
            <?php endif; ?>
        </p>
        <p class="kpi-sub">coûts / prix moyen</p>
    </div>
</div>

<div class="compta-grid">
    <section class="card surface glass">
        <h2 class="card-title">Ajouter un coût</h2>
        <form method="post" action="<?= e(url('/admin/compta/evenements/' . rawurlencode((string) $event['id']) . '/couts/save')) ?>">
            <?= csrf_field() ?>

            <div class="field">
                <label for="spent_at">Date</label>
                <input type="date" id="spent_at" name="spent_at" value="<?= e(date('Y-m-d')) ?>" required>
            </div>

            <div class="field">
                <label for="label">Libellé</label>
                <input type="text" id="label" name="label" placeholder="ex : Déco, materiel location" required>
            </div>

            <div class="field">
                <label for="amount">Montant TTC (€)</label>
                <input type="text" id="amount" name="amount" placeholder="ex : 25,50" inputmode="decimal" required>
                <p class="field-help">Saisie à la française (virgule). Une dépense « Événements » liée est créée automatiquement.</p>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Enregistrer le coût</button>
                <button type="button" class="btn btn-ghost" onclick="if (confirm('Effacer la saisie en cours ?')) this.form.reset();">Annuler</button>
            </div>
        </form>
    </section>

    <section class="card surface glass table-wrap">
        <h2 class="card-title">Coûts de l'événement</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Libellé</th>
                    <th class="th-num">Montant</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($costs as $c): ?>
                    <tr>
                        <td><?= e(formatDate((string) $c['spent_at'])) ?></td>
                        <td><?= e((string) $c['label']) ?></td>
                        <td class="num"><strong><?= e(formatPrice((float) $c['amount_ttc'])) ?></strong></td>
                        <td class="row-actions">
                            <form method="post" action="<?= e(url('/admin/compta/evenements/' . rawurlencode((string) $event['id']) . '/couts/' . rawurlencode((string) $c['id']) . '/delete')) ?>"
                                  data-confirm="Supprimer ce coût (et sa dépense liée) ?" data-preserve-scroll>
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-danger btn-sm" aria-label="Supprimer"></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($costs === []): ?>
                    <tr><td colspan="4" class="muted">Aucun coût saisi.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($costs !== []): ?>
                <tfoot>
                    <tr>
                        <th colspan="2">Total des coûts</th>
                        <th class="num"><?= e(formatPrice($costsTotal)) ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </section>
</div>

<div class="card surface glass table-wrap">
    <h2 class="card-title">Ventes rattachées</h2>
    <p class="muted">Lignes du journal SumUp dont le libellé correspond au nom de l'événement, sur sa fenêtre de dates (200 lignes max).</p>
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Réf.</th>
                <th>Paiement</th>
                <th>Libellé</th>
                <th class="th-num">Qté</th>
                <th class="th-num">Prix TTC</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sales as $s): ?>
                <tr>
                    <td><?= e(formatDateTime((string) $s['sold_at'])) ?></td>
                    <td><?= e((string) ($s['transaction_ref'] ?? '—')) ?></td>
                    <td><span class="badge badge-muted"><?= e((string) ($s['payment_method'] ?? '—')) ?></span></td>
                    <td><?= e((string) (($s['description'] ?? '') !== '' ? $s['description'] : ($s['product_key'] ?? ''))) ?></td>
                    <td class="num"><?= (int) $s['quantity'] ?></td>
                    <td class="num"><strong><?= e(formatPrice((float) $s['price_ttc'])) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($sales === []): ?>
                <tr>
                    <td colspan="6" class="muted">
                        Aucune vente rattachée — vérifie que le nom SumUp correspond exactement
                        (ou configure un alias dans le <a href="<?= e(url('/admin/compta/aliases')) ?>">Mapping libellés</a>).
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
