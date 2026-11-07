<?php

declare(strict_types=1);

/**
 * Section « Top produits » (niveau 2, <details> fermé) — partiel partagé
 * entre le dashboard compta (/admin/compta) et l'accueil admin (/admin,
 * variante compta). Les données viennent du payload Dashboard\Data.
 *
 * Attend en scope : $top.
 *
 * @var list<array<string,mixed>> $top
 */
?>
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
