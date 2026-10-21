<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Panier anonymisé d'une vente (agrégat PUR, aucun accès DB) : une
 * date et ses lignes. Sert aux règles de structuration du panier
 * (R6 associations, R8 panier moyen) via RecoContext.
 */
final class Basket
{
    /**
     * @param string                                       $date   Date de la vente, format 'Y-m-d'.
     * @param list<array{key:string,montant:float,qty:int}> $lignes Lignes du panier (montant en €, qty en unités).
     */
    public function __construct(
        public readonly string $date,
        public readonly array $lignes = []
    ) {
    }
}
