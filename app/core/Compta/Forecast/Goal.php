<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 6 : agrégation des lois prédictives des jours restants —
 * objectif hebdomadaire (probabilité d'atteinte, manque et rythme
 * requis par jour) et horizon glissant (attendu + intervalle 80 %).
 *
 * Convention : $remainingDaySamples est une liste de distributions
 * (une par jour restant) ; le total du cumul est la somme ligne à
 * ligne (MonteCarlo::sumSamples), multipliée chemin par chemin par le
 * CHOC DE NIVEAU COMMUN $facteurNiveau[i] ~ Gamma(8, 1/8) (moyenne 1,
 * σ ≈ 35 % — même prior que la mise à jour horaire), à laquelle
 * s'ajoute le déjà-réel $actualSoFar (sans incertitude). Le choc
 * représente le niveau commun d'une journée (météo, affluence
 * générale…) : il ne se MOYENNISE PAS quand on somme 30 jours — sans
 * lui, la fourchette de l'horizon 30 j serait absurdement étroite
 * (±7 %) alors que l'incertitude de niveau domine. Les événements
 * planifiés, s'il y en a, ont déjà été ajoutés comme constantes aux
 * tirages par l'appelant.
 */
final class Goal
{
    /**
     * Suivi de l'objectif hebdomadaire.
     *
     * manquant_par_jour = max(0, target − attendu) / max(1, jours_restants)
     * (0.0 si attendu >= target ; null si jours_restants = 0 ET
     * attendu < target — plus aucun jour pour rattraper).
     * requis_par_jour = max(0, target − reel) / max(1, jours_restants) :
     * le RYTHME journalier pour viser la cible (0.0 si reel >= target),
     * indépendant de l'attendu du modèle (affiché en vue même quand le
     * modèle prévoit d'atteindre l'objectif).
     * Distribution vide → attendu = lo = hi = actualSoFar et
     * prob = (actualSoFar >= target ? 1.0 : 0.0).
     *
     * @param list<list<float|int>> $remainingDaySamples
     * @param list<float|int>       $facteurNiveau      Un choc de niveau par chemin (défaut : aucun).
     *
     * @return array{objectif:float,reel:float,attendu:float,lo:float,hi:float,prob_atteinte:float,manquant_par_jour:?float,requis_par_jour:float,jours_restants:int}
     */
    public static function week(array $remainingDaySamples, float $actualSoFar, float $target, array $facteurNiveau = []): array
    {
        $joursRestants = count($remainingDaySamples);
        $requis = $actualSoFar < $target
            ? max(0.0, $target - $actualSoFar) / max(1, $joursRestants)
            : 0.0;
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
                'requis_par_jour'   => $requis,
                'jours_restants'    => 0,
            ];
        }

        $totals = MonteCarlo::sumSamples($remainingDaySamples);
        foreach ($totals as $i => &$t) {
            $t = $t * (float) ($facteurNiveau[$i] ?? 1.0) + $actualSoFar;
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
            'requis_par_jour'   => $requis,
            'jours_restants'    => $joursRestants,
        ];
    }

    /**
     * Horizon glissant (ex. 30 jours) : attendu et intervalle 80 %.
     * Le choc de niveau commun ($facteurNiveau, cf. docblock de classe)
     * est appliqué chemin par chemin AVANT d'ajouter $actualSoFar —
     * c'est lui qui donne à la fourchette 30 j une largeur honnête.
     * Distribution vide → tout vaut $actualSoFar.
     *
     * @param list<list<float|int>> $remainingDaySamples
     * @param list<float|int>       $facteurNiveau      Un choc de niveau par chemin (défaut : aucun).
     *
     * @return array{attendu:float,lo:float,hi:float}
     */
    public static function horizon(array $remainingDaySamples, float $actualSoFar, array $facteurNiveau = []): array
    {
        if ($remainingDaySamples === []) {
            return ['attendu' => $actualSoFar, 'lo' => $actualSoFar, 'hi' => $actualSoFar];
        }

        $totals = MonteCarlo::sumSamples($remainingDaySamples);
        foreach ($totals as $i => &$t) {
            $t = $t * (float) ($facteurNiveau[$i] ?? 1.0) + $actualSoFar;
        }
        unset($t);

        return [
            'attendu' => Stats::mean($totals),
            'lo'      => Stats::quantile($totals, 0.10),
            'hi'      => Stats::quantile($totals, 0.90),
        ];
    }
}
