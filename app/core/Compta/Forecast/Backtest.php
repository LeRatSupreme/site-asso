<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 5 : backtest à origine glissante et sélection de modèle.
 *
 * Pour chaque jour d'historique d'indice i >= Hyper::BACKTEST_START − 1
 * (0-based, jours triés par ordre chronologique), on réajuste le modèle
 * sur les jours [0 .. i−1] (poids atypiques RECALCULÉS sur le
 * sous-ensemble) et on prévoit le jour i :
 *
 *  - Main : expectedCa + intervalle par Monte Carlo (rng Mt19937 seedé
 *    par ($seed ?? 0) + i) ;
 *  - B1 : CA du même jour de semaine la semaine précédente si observé,
 *    sinon moyenne globale du CA du sous-ensemble ;
 *  - B2 : moyenne des CA des occurrences passées du même jour de
 *    semaine (repli : moyenne globale) ;
 *  - B3 : moyenne des CA des 7 derniers jours ouverts.
 *
 * Le modèle principal n'est retenu que si WAPE_main < WAPE_B2
 * (strict ; à égalité la baseline B2 gagne). Toutes les méthodes sont
 * statiques et pures.
 *
 * INTERVALLES PAR MÉTHODE (correctif du 21/10/2026 — l'ancienne
 * convention « demi-largeur MC du main recentrée sur le sélectionné »
 * est supprimée) : chaque méthode est mesurée avec SES propres
 * intervalles —
 *  - modèle principal : intervalle Monte Carlo (quantiles 0.10/0.90 des
 *    tirages NegBin × bootstrap) ;
 *  - B2 et B3 : intervalle empirique déterministe, pred ± q0.80 des
 *    |résidus| PASSÉS de la méthode (rolling, à chaque origine ; aucun
 *    résidu passé → intervalle ponctuel assumé, borne basse clampée à 0).
 * `coverage` = couverture de la méthode SÉLECTIONNÉE avec ses propres
 * intervalles ; `coverage_main` / `coverage_b2` / `coverage_b3` exposent
 * chacune des méthodes (transparence : la couverture 11,6 % de prod du
 * 21/10/2026 venait des intervalles récents dégénérés [b2 ; b2] quand le
 * main prévoyait 0). Les scores conformes et demi-largeurs relatives
 * restent ceux du modèle SÉLECTIONNÉ avec SES intervalles.
 *
 * Robustesse des premières origines : BACKTEST_START = 10 est conservé
 * (choix de mission), et `wape_main_recent` / `wape_b2_recent` exposent
 * le WAPE sur les 20 DERNIERS points pour vérifier que les origines
 * précoces ne pèsent pas artificiellement sur la moyenne.
 */
final class Backtest
{
    /**
     * Exécute le backtest.
     *
     * Opts : seed (?int), draws (int, défaut Hyper::N_DRAWS — réduisez-le
     * en contexte de test pour respecter un budget temps), k (float).
     *
     * Semaines hebdo : semaines calendaires lun–dim COMPLÈTES, c.-à-d.
     * que chaque jour de semaine ouvert habituel du fit FINAL (sur tout
     * l'historique) y est observé. wape_weekly = Σ|erreur semaine| /
     * Σ CA semaine ; n_points_weekly = nombre de semaines complètes.
     *
     * NB : la pinball loss utilise les quantiles des tirages du modèle
     * principal (seule loi prédictive disponible) quelle que soit la
     * sélection ; les intervalles rapportés dans details sont ceux de la
     * méthode sélectionnée (MC pour le main, empiriques pour B2), et
     * pred_main / pred_b2 / lo_main / hi_main / lo_b2 / hi_b2 y sont
     * exposés pour chaque méthode (transparence du diagnostic).
     *
     * @param array<string,DayCurrent> $days Jours ouverts observés (tous passés, journée complète).
     * @param array<string,mixed>      $opts
     *
     * @return array{insufficient:bool,n_points:int,use_main_model:bool,selection_note:?string,wape:?float,wape_b2:?float,wape_main:?float,wape_main_recent:?float,wape_b2_recent:?float,n_points_recent:int,bias:?float,mase:?float,coverage:?float,coverage_main:?float,coverage_b2:?float,coverage_b3:?float,pinball:?float,wape_weekly:?float,n_points_weekly:int,scores:list<float>,relative_half_widths:list<float>,details:list<array{date:string,y:float,pred:float,lo:float,hi:float,model:string,pred_main:float,pred_b2:float,lo_main:float,hi_main:float,lo_b2:float,hi_b2:float}>}
     */
    public static function run(array $days, array $opts = []): array
    {
        $seed  = isset($opts['seed']) ? (int) $opts['seed'] : null;
        $draws = max(1, (int) ($opts['draws'] ?? Hyper::N_DRAWS));
        $k     = (float) ($opts['k'] ?? Hyper::K_POOL);

        // Tri chronologique interne (clés 'Y-m-d' triables lexicographiquement).
        $sorted = [];
        foreach ($days as $day) {
            if ($day instanceof DayCurrent) {
                $sorted[$day->date] = $day;
            }
        }
        ksort($sorted);
        $n = count($sorted);
        if ($n < Hyper::BACKTEST_START) {
            return self::emptyResult();
        }

        $keys  = array_keys($sorted);
        $dates = [];
        foreach ($keys as $key) {
            $dates[$key] = new \DateTimeImmutable($key);
        }

        // Points de backtest, un par jour d'indice i >= BACKTEST_START − 1.
        $points = [];
        for ($i = Hyper::BACKTEST_START - 1; $i < $n; $i++) {
            $targetKey = $keys[$i];
            $subset    = [];
            for ($j = 0; $j < $i; $j++) {
                $subset[$keys[$j]] = $sorted[$keys[$j]];
            }
            $target   = $sorted[$targetKey];
            $targetDt = $dates[$targetKey];

            $weights = Clean::atypicalWeights($subset);
            $m       = DemandModel::fit($subset, $weights, $k);

            $rng     = new \Random\Randomizer(new \Random\Engine\Mt19937(($seed ?? 0) + $i));
            $samples = MonteCarlo::daySamples($m, $targetDt, $rng, $draws);
            $s       = MonteCarlo::summarize($samples);

            $globalMean = self::meanCa($subset);
            $dow        = (int) $targetDt->format('N') - 1;

            // B1 : même dow la semaine précédente si observé, sinon moyenne globale.
            $prevKey = $targetDt->modify('-7 days')->format('Y-m-d');
            $b1      = isset($subset[$prevKey]) ? $subset[$prevKey]->ca : $globalMean;

            // B2 : moyenne des occurrences passées du même dow (repli moyenne globale).
            $dowCas = [];
            foreach ($subset as $key => $d) {
                if ((int) $dates[$key]->format('N') - 1 === $dow) {
                    $dowCas[] = $d->ca;
                }
            }
            $b2 = $dowCas === [] ? $globalMean : Stats::mean($dowCas);

            // B3 : moyenne des 7 derniers jours ouverts.
            $last = array_slice($subset, -7, null, true);
            $b3   = self::meanCa($last);

            $points[] = [
                'date'      => $targetKey,
                'y'         => $target->ca,
                'pred_main' => $m->expectedCa($targetDt),
                'lo'        => $s['lo'],
                'hi'        => $s['hi'],
                'samples'   => $samples,
                'b1'        => $b1,
                'b2'        => $b2,
                'b3'        => $b3,
            ];
        }

        // Métriques par modèle.
        $sumY = 0.0;
        $sumAbs = ['main' => 0.0, 'b1' => 0.0, 'b2' => 0.0, 'b3' => 0.0];
        $sumDiff = ['main' => 0.0, 'b2' => 0.0];
        foreach ($points as $p) {
            $y = $p['y'];
            $sumY += $y;
            $sumAbs['main'] += abs($y - $p['pred_main']);
            $sumAbs['b1']   += abs($y - $p['b1']);
            $sumAbs['b2']   += abs($y - $p['b2']);
            $sumAbs['b3']   += abs($y - $p['b3']);
            $sumDiff['main'] += $y - $p['pred_main'];
            $sumDiff['b2']   += $y - $p['b2'];
        }

        $wapeMain = $sumY > 0.0 ? $sumAbs['main'] / $sumY : 0.0;
        $wapeB2   = $sumY > 0.0 ? $sumAbs['b2'] / $sumY : 0.0;
        $useMain  = $wapeMain < $wapeB2;

        $wapeSel = $useMain ? $wapeMain : $wapeB2;
        $maeSel  = $useMain ? $sumAbs['main'] / count($points) : $sumAbs['b2'] / count($points);
        $maeB1   = $sumAbs['b1'] / count($points);
        $mase    = $maeB1 > 0.0 ? $maeSel / $maeB1 : null;
        $bias    = $sumY > 0.0 ? ($useMain ? $sumDiff['main'] : $sumDiff['b2']) / $sumY : null;

        // Intervalles PAR MÉTHODE (cf. docblock de classe) : le modèle
        // sélectionné rapporte SES propres bornes ; chaque méthode est
        // comptée avec les siennes. Demi-largeur relative et scores
        // conformes : méthode sélectionnée.
        $scores   = [];
        $relHalf  = [];
        $hits     = 0;
        $hitsMain = 0;
        $hitsB2   = 0;
        $hitsB3   = 0;
        $details  = [];
        $pinSum   = 0.0;
        $pinCount = 0;
        $taus     = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9];
        $pastB2   = [];
        $pastB3   = [];
        foreach ($points as $p) {
            $pred = $useMain ? $p['pred_main'] : $p['b2'];

            // Modèle principal : intervalle MC (quantiles des tirages).
            $loMain = $p['lo'];
            $hiMain = $p['hi'];
            // B2 / B3 : intervalle empirique rolling, pred ± q0.80 des
            // |résidus| passés de la méthode (déterministe ; aucun résidu
            // passé → intervalle ponctuel assumé).
            $q80B2 = $pastB2 === [] ? 0.0 : Stats::quantile($pastB2, 0.80);
            $loB2  = max(0.0, $p['b2'] - $q80B2);
            $hiB2  = $p['b2'] + $q80B2;
            $q80B3 = $pastB3 === [] ? 0.0 : Stats::quantile($pastB3, 0.80);
            $loB3  = max(0.0, $p['b3'] - $q80B3);
            $hiB3  = $p['b3'] + $q80B3;

            if ($useMain) {
                $lo = $loMain;
                $hi = $hiMain;
            } else {
                $lo = $loB2;
                $hi = $hiB2;
            }
            if ($p['y'] >= $lo && $p['y'] <= $hi) {
                $hits++;
            }
            if ($p['y'] >= $loMain && $p['y'] <= $hiMain) {
                $hitsMain++;
            }
            if ($p['y'] >= $loB2 && $p['y'] <= $hiB2) {
                $hitsB2++;
            }
            if ($p['y'] >= $loB3 && $p['y'] <= $hiB3) {
                $hitsB3++;
            }
            $denom    = max($pred, 1.0);
            $scores[] = abs($p['y'] - $pred) / $denom;
            $relHalf[] = ($hi - $lo) / 2.0 / $denom;

            foreach ($taus as $tau) {
                $q = Stats::quantile($p['samples'], $tau);
                $pinSum += $p['y'] >= $q ? $tau * ($p['y'] - $q) : (1.0 - $tau) * ($q - $p['y']);
                $pinCount++;
            }

            $details[] = [
                'date'      => $p['date'],
                'y'         => $p['y'],
                'pred'      => $pred,
                'lo'        => $lo,
                'hi'        => $hi,
                'model'     => $useMain ? 'negbin_dow_damped' : 'baseline_B2',
                'pred_main' => $p['pred_main'],
                'pred_b2'   => $p['b2'],
                'lo_main'   => $loMain,
                'hi_main'   => $hiMain,
                'lo_b2'     => $loB2,
                'hi_b2'     => $hiB2,
            ];
            $pastB2[] = abs($p['y'] - $p['b2']);
            $pastB3[] = abs($p['y'] - $p['b3']);
        }
        $coverage     = $hits / count($points);
        $coverageMain = $hitsMain / count($points);
        $coverageB2   = $hitsB2 / count($points);
        $coverageB3   = $hitsB3 / count($points);
        $pinball  = $pinCount > 0 ? $pinSum / $pinCount : null;

        // WAPE sur les 20 DERNIERS points (robustesse : les origines
        // précoces — sous-ensembles courts — ne doivent pas peser seuls
        // sur la moyenne ; < 20 points → série entière).
        $recent    = array_slice($points, -20);
        $sumYRec   = 0.0;
        $sumMainRec = 0.0;
        $sumB2Rec  = 0.0;
        foreach ($recent as $p) {
            $sumYRec += $p['y'];
            $sumMainRec += abs($p['y'] - $p['pred_main']);
            $sumB2Rec += abs($p['y'] - $p['b2']);
        }
        $wapeMainRecent = $sumYRec > 0.0 ? $sumMainRec / $sumYRec : 0.0;
        $wapeB2Recent   = $sumYRec > 0.0 ? $sumB2Rec / $sumYRec : 0.0;

        // Semaines complètes : tous les dows ouverts habituels du fit final observés.
        $weightsAll = Clean::atypicalWeights($sorted);
        $mf         = DemandModel::fit($sorted, $weightsAll, $k);
        $openDows   = $mf->openWeekdays;

        $groups = [];
        foreach ($points as $p) {
            $groups[self::mondayKey(new \DateTimeImmutable($p['date']))][$p['date']] = $p;
        }
        $sumErrWeekly = 0.0;
        $sumCaWeekly  = 0.0;
        $nWeekly      = 0;
        foreach ($groups as $mondayKey => $group) {
            $complete = true;
            foreach ($openDows as $dow) {
                $key = (new \DateTimeImmutable($mondayKey))
                    ->modify('+' . $dow . ' days')
                    ->format('Y-m-d');
                if (!isset($group[$key])) {
                    $complete = false;
                    break;
                }
            }
            if (!$complete) {
                continue;
            }
            $nWeekly++;
            $sumYw = 0.0;
            $sumPw = 0.0;
            foreach ($group as $p) {
                $sumYw += $p['y'];
                $sumPw += $useMain ? $p['pred_main'] : $p['b2'];
            }
            $sumErrWeekly += abs($sumYw - $sumPw);
            $sumCaWeekly  += $sumYw;
        }
        $wapeWeekly = $nWeekly > 0 && $sumCaWeekly > 0.0 ? $sumErrWeekly / $sumCaWeekly : null;

        $note = $useMain
            ? sprintf('Modèle principal retenu par le backtest (WAPE %.1f %% contre %.1f %% pour la baseline B2).', $wapeMain * 100.0, $wapeB2 * 100.0)
            : sprintf('Baseline B2 retenue par le backtest (WAPE %.1f %% contre %.1f %% pour le modèle principal).', $wapeB2 * 100.0, $wapeMain * 100.0);

        return [
            'insufficient'        => false,
            'n_points'            => count($points),
            'use_main_model'      => $useMain,
            'selection_note'      => $note,
            'wape'                => $wapeSel,
            'wape_b2'             => $wapeB2,
            'wape_main'           => $wapeMain,
            'wape_main_recent'    => $wapeMainRecent,
            'wape_b2_recent'      => $wapeB2Recent,
            'n_points_recent'     => count($recent),
            'bias'                => $bias,
            'mase'                => $mase,
            'coverage'            => $coverage,
            'coverage_main'       => $coverageMain,
            'coverage_b2'         => $coverageB2,
            'coverage_b3'         => $coverageB3,
            'pinball'             => $pinball,
            'wape_weekly'         => $wapeWeekly,
            'n_points_weekly'     => $nWeekly,
            'scores'              => $scores,
            'relative_half_widths' => $relHalf,
            'details'             => $details,
        ];
    }

    /**
     * Résultat « historique insuffisant » ( < Hyper::BACKTEST_START jours).
     *
     * @return array{insufficient:bool,n_points:int,use_main_model:bool,selection_note:null,wape:null,wape_b2:null,wape_main:null,wape_main_recent:null,wape_b2_recent:null,n_points_recent:int,bias:null,mase:null,coverage:null,coverage_main:null,coverage_b2:null,coverage_b3:null,pinball:null,wape_weekly:null,n_points_weekly:int,scores:array{},relative_half_widths:array{},details:array{}}
     */
    private static function emptyResult(): array
    {
        return [
            'insufficient'        => true,
            'n_points'            => 0,
            'use_main_model'      => false,
            'selection_note'      => null,
            'wape'                => null,
            'wape_b2'             => null,
            'wape_main'           => null,
            'wape_main_recent'    => null,
            'wape_b2_recent'      => null,
            'n_points_recent'     => 0,
            'bias'                => null,
            'mase'                => null,
            'coverage'            => null,
            'coverage_main'       => null,
            'coverage_b2'         => null,
            'coverage_b3'         => null,
            'pinball'             => null,
            'wape_weekly'         => null,
            'n_points_weekly'     => 0,
            'scores'              => [],
            'relative_half_widths' => [],
            'details'             => [],
        ];
    }

    /** Moyenne des CA d'un sous-ensemble de jours (vide → 0.0). */
    private static function meanCa(array $subset): float
    {
        $cas = [];
        foreach ($subset as $d) {
            $cas[] = $d->ca;
        }

        return Stats::mean($cas);
    }

    /** Clé 'Y-m-d' du lundi de la semaine calendaire (lun–dim) de $d. */
    private static function mondayKey(\DateTimeImmutable $d): string
    {
        return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
    }
}
