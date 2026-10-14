<?php

declare(strict_types=1);

use App\Models\CafeteriaOrder;
use App\Models\OrderWorkflow;

/**
 * @var list<array<string,mixed>> $orders
 * @var list<string> $statuses
 */

require AEIC_VIEWS . '/partials/order_status_labels.php';
?>
<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Date</th><th>Client</th><th>Montant</th><th>Statut</th><th>Changer le statut</th></tr></thead>
        <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="5" class="card-meta">Aucune commande.</td></tr>
            <?php else: ?>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= e(formatDateTime((string) ($o['created_at'] ?? ''))) ?></td>
                        <td><?= e(trim(($o['prenom'] ?? '') . ' ' . ($o['nom'] ?? 'Comptoir'))) ?></td>
                        <td><strong><?= e(formatPrice($o['total'] ?? 0)) ?></strong></td>
                        <td><span class="badge badge-muted"><?= e($orderStatusLabels[(string) ($o['status'] ?? '')] ?? (string) ($o['status'] ?? '')) ?></span></td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/cafeteria/commandes/' . rawurlencode((string) $o['id']) . '/status')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <?php
                                // Options limitées aux transitions valides depuis le
                                // statut courant (la validation serveur revalide) :
                                // statut inconnu => liste complète (repli).
                                $current = (string) ($o['status'] ?? '');
                                $known = in_array($current, $statuses, true);
                                ?>
                                <select name="status" onchange="this.form.submit()">
                                    <?php foreach ($statuses as $s): ?>
                                        <?php if ($known && $s !== $current && !OrderWorkflow::canTransition($current, $s)) {
                                            continue;
                                        } ?>
                                        <option value="<?= e($s) ?>" <?= $current === $s ? 'selected' : '' ?>><?= e($orderStatusLabels[$s] ?? $s) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
