<?php

declare(strict_types=1);

/**
 * Section « Par catégorie » (niveau 2, <details> fermé) — partiel partagé
 * entre le dashboard compta (/admin/compta) et l'accueil admin (/admin,
 * variante compta). Les données viennent du payload Dashboard\Data.
 *
 * Attend en scope : $byCategory.
 *
 * @var list<array<string,mixed>> $byCategory
 */
?>
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
