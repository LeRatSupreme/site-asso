<?php

declare(strict_types=1);

/**
 * Section « Dépenses » (niveau 2, <details> fermé) — partiel partagé entre
 * le dashboard compta (/admin/compta) et l'accueil admin (/admin, variante
 * compta). Les données viennent du payload Dashboard\Data.
 *
 * Attend en scope : $expenseTtc, $expenseCount.
 *
 * @var float $expenseTtc
 * @var int $expenseCount
 */
?>
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
