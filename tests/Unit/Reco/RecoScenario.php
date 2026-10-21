<?php

declare(strict_types=1);

namespace Tests\Unit\Reco;

use App\Core\Compta\Reco\Basket;
use App\Core\Compta\Reco\RecoDataset;
use App\Core\Compta\Reco\RecoProduct;

/**
 * Fabrique d'objets purs pour les tests du cœur Reco (utilitaire
 * statique, PAS une classe de test, aucune DB).
 */
final class RecoScenario
{
    /** Produit par défaut : P1, 100 u/30 j, 200 €/30 j, prix 2 €, coût 1 € (marge 50 %). */
    public static function produit(
        string $key = 'P1',
        string $label = 'Produit 1',
        string $category = 'Boissons',
        float $qty30 = 100.0,
        float $ca30 = 200.0,
        ?float $prix = 2.0,
        ?float $cout = 1.0,
        ?string $coutRaison = null,
        ?int $stock = null,
        float $unitesPrevues7j = 50.0,
        float $prevLo = 40.0,
        float $prevHi = 60.0
    ): RecoProduct {
        return new RecoProduct($key, $label, $category, $qty30, $ca30, $prix, $cout, $coutRaison, $stock, $unitesPrevues7j, $prevLo, $prevHi);
    }

    /**
     * @param list<array{key:string,montant:float,qty:int}> $lignes
     */
    public static function panier(string $date = '2026-10-20', array $lignes = [['key' => 'P1', 'montant' => 2.0, 'qty' => 1]]): Basket
    {
        return new Basket($date, $lignes);
    }

    /**
     * RecoDataset par défaut : fenêtre de 30 jours ouverts, forecast
     * minimal, historique et paramètres vides.
     *
     * @param list<RecoProduct> $produits
     * @param list<Basket>      $paniers
     * @param array<string,float> $qtyPrec
     * @param array<string,mixed>|null $forecast
     * @param array{actifs:list<string>,refus:list<array{fingerprint:string,date:string}>,appliquees:list<array{fingerprint:string,produits:list<string>}>}|array{} $historique
     * @param array<string,mixed> $parametres
     */
    public static function dataset(
        array $produits = [],
        array $paniers = [],
        array $qtyPrec = [],
        float $facteurCreuse = 1.0,
        bool $periodeCreuseActive = false,
        int $joursOuvertsFenetre = 30,
        ?array $forecast = null,
        array $historique = [],
        array $parametres = []
    ): RecoDataset {
        return new RecoDataset(
            $produits,
            $paniers,
            $qtyPrec,
            $facteurCreuse,
            $periodeCreuseActive,
            $joursOuvertsFenetre,
            $forecast ?? self::forecastMinimal(),
            $historique,
            $parametres
        );
    }

    /**
     * Sortie ForecastEngine minimale : seules les clés consommées par la
     * fondation sont peuplées (horaire.s cumulatif 24 h), le reste vide.
     */
    public static function forecastMinimal(): array
    {
        $s = [];
        for ($h = 0; $h < 24; $h++) {
            $s[] = ($h + 1) / 24.0;
        }

        return [
            'horaire'   => ['s' => $s],
            'semaine'   => [
                'objectif'          => 500.0,
                'reel'              => 0.0,
                'attendu'           => 400.0,
                'lo'                => 300.0,
                'hi'                => 500.0,
                'prob_atteinte'     => 0.4,
                'manquant_par_jour' => 14.3,
                'requis_par_jour'   => 71.4,
                'jours_restants'    => 7,
            ],
            'produits'   => [],
            'anomalies'  => [],
        ];
    }
}
