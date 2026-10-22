<?php

declare(strict_types=1);

/**
 * Encart « Base de calcul » — STRICTEMENT identique sur la page
 * Réapprovisionnement et la Liste de courses (versions admin et kiosque,
 * lot 5 : « même présentation, même source »).
 *
 * Les valeurs viennent du contrôleur ($basis, assemblé depuis les points
 * d'entrée partagés ReapproDemande / Dashboard\Data) : aucun calcul ni
 * libellé métier n'est produit ici.
 *
 * @var array{ventes:string,couverture:string,source:string} $basis
 */

if (!isset($basis) || !is_array($basis)) {
    return;
}
?>
<p class="shop-basis">
    Base de calcul — Ventes analysées : <strong><?= e((string) ($basis['ventes'] ?? '')) ?></strong>
    · Couverture visée : <strong><?= e((string) ($basis['couverture'] ?? '')) ?></strong>
    · Source : <strong><?= e((string) ($basis['source'] ?? '')) ?></strong>.
</p>
