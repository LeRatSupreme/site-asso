<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 6 : agrégation des lois prédictives des jours restants —
 * objectif hebdomadaire (probabilité d'atteinte, manque par jour) et
 * horizon glissant (attendu + intervalle 80 %).
 *
 * Convention : $remainingDaySamples est une liste de distributions
 * (une par jour restant) ; le total du cumul est la somme ligne à
 * ligne (MonteCarlo::sumSamples) à laquelle s'ajoute le déjà-réel
 * $actualSoFar (sans incertitude). Les événements planifiés, s'il y
 * en a, ont déjà été ajoutés comme constantes aux tirages par l'appelant.
 */
final class Goal
{
    /**
     * Suivi de l'objectif hebdomadaire.
     *
     * manquant_par_jour = max(0, target − attendu) / max(1, jours_restants)
     * (0.0 si attendu >= target ; null si jours_restants = 0 ET
     * attendu < target — plus aucun jour pour rattraper).
     * Distribution vide → attendu = lo = hi = actualSoFar et
     * prob = (actualSoFar >= target ? 1.0 : 0.0).
     *
     * @param list<list<float|int>> $remainingDaySamples
     *
     * @return array{objectif:float,reel:float,attendu:float,lo:float,hi:float,prob_atteinte:float,manquant_par_jour:?float,jours_restants:int}
     */
    public static function week(array $remainingDaySamples, float $actualSoFar, float $target): array
    {
        $joursRestants = count($remainingDaySamples);
        if ($joursRestants === 0) {
            $atteint = $actualSoFar >= $target;

            return [
                'objectif'          => $target,
                'reel'              => $actualSoFar,
                'attendu'           => $actualSoFar,
                'lo'                => $actualSoFar,
                'hi'                => $actualSoFar,
                'prob_atteinte'     => $atteint ? 1.0 : 0.0,
                'manquant_par_jour' => $atteint ? 0.0 : null,
                'jours_restants'    => 0,
            ];
        }

        $totals = MonteCarlo::sumSamples($remainingDaySamples);
        foreach ($totals as &$t) {
            $t += $actualSoFar;
        }
        unset($t);

        $attendu = Stats::mean($totals);
        $manquant = $attendu >= $target
            ? 0.0
            : max(0.0, $target - $attendu) / max(1, $joursRestants);

        return [
            'objectif'          => $target,
            'reel'              => $actualSoFar,
            'attendu'           => $attendu,
            'lo'                => Stats::quantile($totals, 0.10),
            'hi'                => Stats::quantile($totals, 0.90),
            'prob_atteinte'     => MonteCarlo::probAtLeast($totals, $target),
            'manquant_par_jour' => $manquant,
            'jours_restants'    => $joursRestants,
        ];
    }

    /**
     * Horizon glissant (ex. 30 jours) : attendu et intervalle 80 %.
     * Distribution vide → tout vaut $actualSoFar.
     *
     * @param list<list<float|int>> $remainingDaySamples
     *
     * @return array{attendu:float,lo:float,hi:float}
     */
    public static function horizon(array $remainingDaySamples, float $actualSoFar): array
    {
        if ($remainingDaySamples === []) {
            return ['attendu' => $actualSoFar, 'lo' => $actualSoFar, 'hi' => $actualSoFar];
        }

        $totals = MonteCarlo::sumSamples($remainingDaySamples);
        foreach ($totals as &$t) {
            $t += $actualSoFar;
        }
        unset($t);

        return [
            'attendu' => Stats::mean($totals),
            'lo'      => Stats::quantile($totals, 0.10),
            'hi'      => Stats::quantile($totals, 0.90),
        ];
    }
}
