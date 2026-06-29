<?php

declare(strict_types=1);

use App\Models\OrderWorkflow;

/**
 * Historique des commandes cafétéria.
 *
 * @var list<array<string,mixed>> $orders
 * @var array<string, list<array<string,mixed>>> $itemsByOrder
 */

$statusBadgeClass = [
    OrderWorkflow::PENDING   => 'badge-muted',
    OrderWorkflow::CONFIRMED => 'badge-secondary',
    OrderWorkflow::PREPARING => 'badge-warning',
    OrderWorkflow::READY     => 'badge-success',
    OrderWorkflow::DELIVERED => 'badge-secondary',
    OrderWorkflow::CANCELLED => 'badge-danger',
];
?>
<header class="dash-head">
    <span class="eyebrow">Cafétéria</span>
    <h1 class="page-title">Mes commandes</h1>
</header>

<?php if (empty($orders)): ?>
    <div class="empty-state surface glass">
        <p>Aucune commande pour le moment.</p>
        <a class="btn btn-primary" href="<?= e(url('/eleve/cafeteria')) ?>">Commander à la cafétéria</a>
    </div>
<?php else: ?>
    <div class="orders-list">
        <?php foreach ($orders as $order): ?>
            <?php
            $status = (string) $order['status'];
            $items = $itemsByOrder[(string) $order['id']] ?? [];
            ?>
            <article class="card surface glass">
                <div class="order-head">
                    <div>
                        <span class="card-meta">Commande <?= e(formatDateTime((string) ($order['created_at'] ?? ''))) ?></span>
                    </div>
                    <span class="badge <?= e($statusBadgeClass[$status] ?? 'badge-muted') ?>"><?= e($status) ?></span>
                </div>
                <ul class="list-rows">
                    <?php foreach ($items as $item): ?>
                        <li>
                            <span><?= e((int) $item['quantity']) ?> × <?= e($item['product_name'] ?? 'Produit supprimé') ?></span>
                            <strong><?= e(formatPrice($item['price'] ?? 0)) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="order-total">Total : <strong><?= e(formatPrice($order['total'] ?? 0)) ?></strong></p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
