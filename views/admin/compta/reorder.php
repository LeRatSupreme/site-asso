<?php

declare(strict_types=1);

/**
 * @var list<array<string,mixed>> $rows
 * @var int $alerts
 */
?>
<div class="admin-actions">
    <p class="muted">
        Consommation = moyenne mobile 3 mois lue depuis les ventes SumUp (rapprochée par nom de produit).
        Autonomie = stock / conso journalière. Tri par urgence (autonomie croissante).
    </p>
</div>

<div class="card surface glass table-wrap">
    <table class="table">
        <thead><tr><th>Produit</th><th>Catégorie</th><th>Stock</th><th>Conso moy./mois</th><th>Autonomie</th><th>Suggestion (1 mois)</th><th>État</th></tr></thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= !empty($r['is_alert']) ? 'row-alert' : '' ?>">
                    <td><strong><?= e((string) $r['name']) ?></strong></td>
                    <td><?= e((string) $r['category']) ?></td>
                    <td><?= e((string) $r['stock']) ?></td>
                    <td><?= e(number_format((float) $r['avg_month'], 1, ',', ' ')) ?></td>
                    <td>
                        <?php if ($r['autonomy'] === null): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <?= e((string) $r['autonomy']) ?> j
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['suggested'] > 0): ?>
                            <strong><?= e((string) $r['suggested']) ?></strong>
                        <?php else: ?>
                            <span class="muted">Stock suffisant</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($r['is_alert'])): ?>
                            <span class="badge badge-warning">À racheter</span>
                        <?php else: ?>
                            <span class="badge badge-success">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="muted">Aucun produit à analyser.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
