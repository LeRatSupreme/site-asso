<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Contrats de données du cœur de prévision (pur, sans accès DB).
 *
 * Le dataset est construit en amont par la couche Analytics : seuls les
 * jours OUVERTS y figurent (un jour fermé est simplement absent, jamais
 * compté comme CA = 0), et le flux « Événement » / « Divers » est déjà
 * exclu ainsi que les prix <= 0.
 *
 * @phpstan-type Audit array{ventes_zero?:int,doublons_ignores?:int,produits_cout_suspect?:list<string>}
 */
final class ForecastDataset
{
    /**
     * @param array<string,DayCurrent> $days     'Y-m-d' => DayCurrent — jours ouverts uniquement,
     *                                           aujourd'hui INCLUS en jour partiel.
     * @param list<ProductStat>        $products Statistiques produits (optionnel pour PARTIE 1).
     * @param Audit                    $audit    Compteurs d'audit de la construction du dataset.
     */
    public function __construct(
        public readonly array $days,
        public readonly array $products = [],
        public readonly array $audit = [],
    ) {
    }
}
