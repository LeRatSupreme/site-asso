<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $memberships
 * @var list<string> $seasons
 * @var string $currentSeason
 * @var string $seasonFilter
 * @var array{PAID:int,PENDING:int,EXPIRED:int,total:int} $stats
 * @var string $price
 * @var bool $enabled
 */

$statusBadge = [
    'PAID'    => '<span class="badge badge-success">Payée</span>',
    'PENDING' => '<span class="badge badge-warning">En attente</span>',
    'EXPIRED' => '<span class="badge badge-muted">Expirée</span>',
];
?>
<div class="grid grid-4 stat-cards">
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e((string) $stats['PAID']) ?></span>
        <span class="stat-label">À jour (<?= e($currentSeason) ?>)</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e((string) $stats['PENDING']) ?></span>
        <span class="stat-label">En attente</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e((string) $stats['EXPIRED']) ?></span>
        <span class="stat-label">Expirées</span>
    </div>
    <div class="stat-card surface glass">
        <span class="stat-value"><?= e(formatPrice((float) $price)) ?></span>
        <span class="stat-label">Cotisation</span>
    </div>
</div>

<?php if (!$enabled): ?>
    <p class="card-meta">⚠️ La gestion des adhésions est désactivée dans les paramètres.</p>
<?php endif; ?>

<section class="card surface glass">
    <div class="settings-group-head" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
        <h2 class="card-title">Adhésions</h2>
        <form method="get" action="<?= e(url('/admin/memberships')) ?>" class="inline-form">
            <label for="season-filter" class="card-meta">Saison :</label>
            <select id="season-filter" name="season" onchange="this.form.submit()">
                <option value="">Toutes</option>
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= e($s) ?>" <?= $s === $seasonFilter ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (empty($memberships)): ?>
        <p class="card-meta">Aucune adhésion pour le moment.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nom</th><th>Email</th><th>Saison</th><th>Statut</th>
                        <th>Montant</th><th>Date paiement</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($memberships as $m): ?>
                        <?php
                        $status = (string) ($m['status'] ?? 'PENDING');
                        $isPaid = $status === 'PAID';
                        ?>
                        <tr>
                            <td><strong><?= e(trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''))) ?></strong></td>
                            <td><?= e($m['email'] ?? '') ?></td>
                            <td><?= e((string) ($m['season'] ?? '')) ?></td>
                            <td><?= $statusBadge[$status] ?? e($status) ?></td>
                            <td><?= $isPaid ? e(formatPrice((float) ($m['amount_paid'] ?? 0))) : '—' ?></td>
                            <td><?= $isPaid ? e(formatDateTime((string) ($m['paid_at'] ?? ''))) : '—' ?></td>
                            <td class="row-actions">
                                <?php if (!$isPaid): ?>
                                    <form method="post"
                                          action="<?= e(url('/admin/memberships/' . rawurlencode((string) $m['id']) . '/mark-paid')) ?>"
                                          class="inline-form"
                                          data-confirm="Marquer cette adhésion comme payée ?">
                                        <?= csrf_field() ?>
                        <input type="number" step="0.01" min="0" name="amount"
                               value="<?= e($price) ?>" class="input-sm" style="width:6rem;" aria-label="Montant">
                        <input type="text" name="sumup_ref" placeholder="Réf. SumUp (option)"
                               class="input-sm" style="width:12rem;" aria-label="Référence SumUp">
                                        <button type="submit" class="btn btn-primary btn-sm">Marquer payée</button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge badge-muted">Réf : <?= e((string) ($m['sumup_ref'] ?? '—')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
