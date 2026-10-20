<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Une journée OUVERTE observée, flux courant uniquement (événements /
 * divers déjà exclus en amont, prix <= 0 filtrés).
 *
 * Le dataset est construit en amont par la couche Analytics : seuls les
 * jours OUVERTS y figurent (un jour fermé est simplement absent, jamais
 * compté comme CA = 0).
 */
final class DayCurrent
{
    /**
     * @param string        $date     Clé 'Y-m-d' (Europe/Paris).
     * @param float         $ca       Chiffre d'affaires du jour (flux courant).
     * @param int           $n        Nombre de transactions (références distincts).
     * @param array<int,int> $hourlyN [heure => nb transactions] pour 0..23 (éventuellement partiel).
     * @param list<float>   $baskets  Montants par transaction.
     * @param float         $weight   Poids du jour : 1.0, ou Hyper::W_ATYPICAL si atypique
     *                                (z robuste calculé par Clean::atypicalWeights — c'est
     *                                l'appelant qui reporte le poids ici).
     */
    public function __construct(
        public readonly string $date,
        public readonly float $ca,
        public readonly int $n,
        public readonly array $hourlyN = [],
        public readonly array $baskets = [],
        public readonly float $weight = 1.0,
    ) {
    }
}
