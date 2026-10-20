<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Statistique produit (flux courant) sur la fenêtre analysée, pour le
 * bénéfice et les suggestions de réapprovisionnement.
 */
final class ProductStat
{
    /**
     * @param string $key         Clé produit stable (identifiant catalogue).
     * @param string $label       Libellé affichable.
     * @param string $category    Catégorie produit.
     * @param int    $qty         Unités vendues sur la fenêtre.
     * @param float  $ca          CA du produit sur la fenêtre.
     * @param ?float $unitPrice   Prix unitaire médian observé ; null si inconnu.
     * @param ?float $unitCost    Coût unitaire courant ; null si aucun lot connu.
     * @param bool   $costSuspect Coût null, coût <= 0, ou prix < coût.
     * @param ?int   $stock       Stock actuel ; null = inconnu ou non géré (infini).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $category,
        public readonly int $qty,
        public readonly float $ca,
        public readonly ?float $unitPrice,
        public readonly ?float $unitCost,
        public readonly bool $costSuspect,
        public readonly ?int $stock,
    ) {
    }
}
