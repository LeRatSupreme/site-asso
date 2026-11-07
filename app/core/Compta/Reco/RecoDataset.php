<?php

declare(strict_types=1);

namespace App\Core\Compta\Reco;

/**
 * Données figées d'une exécution du moteur de recommandations : un
 * instantané PUR (aucun accès DB) construit par l'appelant — produits,
 * paniers, historique des reco (actifs / refus / appliquées) et
 * surcharges de paramètres. RecoContext en dérive les valeurs paresseuses.
 */
final class RecoDataset
{
    /**
     * @param list<RecoProduct> $produits            Catalogue de la fenêtre.
     * @param list<Basket>      $paniers             Paniers de la fenêtre.
     * @param array<string,float> $qtyPrec          Quantités pré-précises par clé (réservé aux règles).
     * @param float               $facteurCreuse    Diviseur de désaisonnalisation (1.0 = neutre).
     * @param bool                $periodeCreuseActive  true = période creuse en cours.
     * @param int                 $joursOuvertsFenetre  Jours ouverts de la fenêtre analysée (0 = non renseigné).
     * @param array<string,mixed> $forecast         Sortie ForecastEngine::forecast() TELLE QUELLE.
     * @param array{actifs:list<string>,refus:list<array{fingerprint:string,date:string}>,appliquees:list<array{fingerprint:string,produits:list<string>}>} $historique Historique des recommandations (défaut : tout vide).
     * @param array<string,mixed> $parametres       Surcharges « reco_<nom> » (voir Hyper::valeur).
     */
    public function __construct(
        public readonly array $produits = [],
        public readonly array $paniers = [],
        public readonly array $qtyPrec = [],
        public readonly float $facteurCreuse = 1.0,
        public readonly bool $periodeCreuseActive = false,
        public readonly int $joursOuvertsFenetre = 0,
        public readonly array $forecast = [],
        public readonly array $historique = ['actifs' => [], 'refus' => [], 'appliquees' => []],
        public readonly array $parametres = []
    ) {
    }
}
