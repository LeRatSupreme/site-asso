<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 4 du cœur de prévision : loi prédictive Monte Carlo du CA d'une
 * journée.
 *
 * Pour une date donnée, chaque tirage combine :
 *  - un effectif N ~ NegBin(mean = expectedN(date), dispersion = dispersionR)
 *    (surdispersion bornée par Hyper::R_MIN / Hyper::R_MAX côté modèle) ;
 *  - un panier moyen P estimé par bootstrap (moyenne de min(30, |pool|)
 *    paniers tirés AVEC remise) dans le pool de paniers du jour de semaine
 *    si ce pool compte au moins DOW_POOL_MIN paniers, sinon dans le pool
 *    global des derniers jours ouverts ; pool vide → P = expectedP(date)
 *    (valeur du modèle, sans incertitude) ;
 *  - CA = N × P.
 *
 * expectedN(date) <= 0 → tous les tirages sont à zéro (journée sans
 * prévision exploitable). Toutes les méthodes sont statiques et pures ;
 * le déterminisme est assuré par le \Random\Randomizer injecté.
 */
final class MonteCarlo
{
    /** Taille du bootstrap par tirage : min(30, taille du pool). */
    private const BOOTSTRAP_MAX = 30;

    /** Paniers minimum dans le pool du dow pour le préférer au pool global. */
    private const DOW_POOL_MIN = 8;

    /**
     * Loi prédictive du CA d'une journée (un tirage = un CA possible).
     *
     * @param DemandModel         $m     Modèle ajusté (jours passés).
     * @param \DateTimeImmutable  $date  Date prévue (Europe/Paris).
     * @param \Random\Randomizer  $rng   Générateur seedé par l'appelant.
     * @param int                 $draws Nombre de tirages.
     *
     * @return list<float> exactement max(0, $draws) valeurs de CA
     *                     (zéros si expectedN <= 0).
     */
    public static function daySamples(DemandModel $m, \DateTimeImmutable $date, \Random\Randomizer $rng, int $draws = Hyper::N_DRAWS): array
    {
        $draws = max(0, $draws);
        if ($draws === 0) {
            return [];
        }

        $meanN = $m->expectedN($date);
        if ($meanN <= 0.0) {
            return array_fill(0, $draws, 0.0);
        }

        $dow     = (int) $date->format('N') - 1;
        $byDow   = $m->recentBasketsByDow();
        $dowPool = isset($byDow[$dow]) && is_array($byDow[$dow]) ? $byDow[$dow] : [];
        $pool    = count($dowPool) >= self::DOW_POOL_MIN ? $dowPool : $m->recentBaskets();
        $nPool   = count($pool);

        $basketFix = null;
        if ($nPool === 0) {
            $basketFix = $m->expectedP($date);
        }
        $bs = min(self::BOOTSTRAP_MAX, $nPool);

        $out = [];
        for ($d = 0; $d < $draws; $d++) {
            $n = Stats::negBinSample($meanN, $m->dispersionR, $rng);
            if ($basketFix !== null) {
                $out[] = (float) $n * $basketFix;
                continue;
            }
            $sum = 0.0;
            for ($j = 0; $j < $bs; $j++) {
                $sum += (float) $pool[$rng->getInt(0, $nPool - 1)];
            }
            $out[] = (float) $n * ($sum / (float) $bs);
        }

        return $out;
    }

    /**
     * Résumé d'une distribution : moyenne et intervalle central à 80 %
     * (quantiles 0.10 et 0.90). Distribution vide → (0, 0, 0).
     *
     * @param list<float|int> $samples
     *
     * @return array{mean:float,lo:float,hi:float}
     */
    public static function summarize(array $samples): array
    {
        if ($samples === []) {
            return ['mean' => 0.0, 'lo' => 0.0, 'hi' => 0.0];
        }

        return [
            'mean' => Stats::mean($samples),
            'lo'   => Stats::quantile($samples, 0.10),
            'hi'   => Stats::quantile($samples, 0.90),
        ];
    }

    /**
     * Somme ligne à ligne de distributions par jour. Longueurs
     * différentes → alignement sur la plus courte (comportement de
     * mission) ; aucune entrée → tableau vide.
     *
     * @param list<list<float|int>> $perDaySamples
     *
     * @return list<float>
     */
    public static function sumSamples(array $perDaySamples): array
    {
        $lists = array_values($perDaySamples);
        if ($lists === []) {
            return [];
        }

        $len = PHP_INT_MAX;
        foreach ($lists as $l) {
            $len = min($len, count($l));
        }
        if ($len <= 0) {
            return [];
        }

        $out = array_fill(0, $len, 0.0);
        foreach ($lists as $l) {
            $i = 0;
            foreach ($l as $v) {
                if ($i >= $len) {
                    break;
                }
                $out[$i++] += (float) $v;
            }
        }

        return $out;
    }

    /**
     * Probabilité estimée qu'un tirage soit >= $threshold :
     * |{s >= t}| / |samples|. Distribution vide → 0.0.
     *
     * @param list<float|int> $samples
     */
    public static function probAtLeast(array $samples, float $threshold): float
    {
        $n = count($samples);
        if ($n === 0) {
            return 0.0;
        }
        $hits = 0;
        foreach ($samples as $v) {
            if ((float) $v >= $threshold) {
                $hits++;
            }
        }

        return $hits / $n;
    }
}
