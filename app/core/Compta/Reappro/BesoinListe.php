<?php

declare(strict_types=1);

namespace App\Core\Compta\Reappro;

/**
 * Besoin de la LISTE DE COURSES (pur, sans accès DB).
 *
 * La liste ne fait QUE : besoin sur N semaines − stock, arrondi au pack
 * — la demande vient EXCLUSIVEMENT du moteur (ForecastEngine, lignes
 * « produits » calibrées sur le CA prévu 7 j), jamais d'une consommation
 * observée recalculée. Une seule source de stock également : la ligne
 * moteur porte déjà le stock théorique résolu via StockBook (même
 * résolution que la carte Réappro Analytics).
 *
 * Règles (exigences du 21/10/2026) :
 *  - besoin(N) = ceil(hi × N/7) — le quantile haut du moteur (sécurité),
 *    mis à l'échelle linéairement de l'horizon 7 j vers N jours. À
 *    N = 7 jours (facteur 1), besoin == safety_demand du moteur, donc
 *    cohérent PAR CONSTRUCTION avec la suggestion du Réappro :
 *    to_order(brut) = max(0, besoin − max(0, stock)) ;
 *  - stock NÉGATIF = anomalie d'inventaire (survente) : il n'est JAMAIS
 *    ajouté au besoin (le stock déduit vaut 0, pas −N) et il est signalé
 *    (flag 'negatif') pour affichage à part ;
 *  - stock NULL = produit sans donnée d'inventaire (« à compter ») :
 *    listé à part, to_order null (besoin complet affiché à titre indicatif) ;
 *  - arrondi au pack d'achat : multiple supérieur (besoin 2, pack 12 → 12),
 *    0/1 = commande à l'unité.
 */
final class BesoinListe
{
    /**
     * Calcule la ligne de besoin d'un produit.
     *
     * @param array<string,mixed> $p        Ligne moteur (clés unites, lo, hi, safety_demand, stock…).
     * @param float               $facteur  Échelle de couverture (jours de couverture ÷ 7 ; 1.0 = 1 semaine = horizon moteur).
     * @param int                 $pack     Taille du pack d'achat (0/1 = à l'unité).
     *
     * @return array{besoin:int,stock:?int,to_order:?int,negatif:bool,prev_semaine:float}
     */
    public static function ligne(array $p, float $facteur = 1.0, int $pack = 0): array
    {
        $facteur = max(0.0, $facteur);
        $hi      = max(0.0, (float) ($p['hi'] ?? 0.0));
        $besoin  = (int) ceil($hi * $facteur);

        $stockBrut = array_key_exists('stock', $p) ? $p['stock'] : null;
        $stock = $stockBrut !== null ? (int) $stockBrut : null;
        $negatif = $stock !== null && $stock < 0;

        if ($stock === null) {
            return [
                'besoin'       => $besoin,
                'stock'        => null,
                'to_order'     => null, // « à compter » : jamais de commande chiffrée
                'negatif'      => false,
                'prev_semaine' => max(0.0, (float) ($p['unites'] ?? 0.0)),
            ];
        }

        // Stock négatif : signalé comme anomalie, NON ajouté au besoin
        // (le stock déduit vaut max(0, stock) — l'ancienne règle besoin −
        // stock majrait la commande de la survente, cf. « Bueno white −5 »
        // de la liste du 21/10/2026).
        $brut = max(0, $besoin - max(0, $stock));
        $pack = max(0, $pack);
        $toOrder = ($pack > 1 && $brut > 0) ? (int) (ceil($brut / $pack) * $pack) : $brut;

        return [
            'besoin'       => $besoin,
            'stock'        => $stock,
            'to_order'     => $toOrder,
            'negatif'      => $negatif,
            'prev_semaine' => max(0.0, (float) ($p['unites'] ?? 0.0)),
        ];
    }
}
