<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 4 (suite) : calibration conforme des intervalles de prévision.
 *
 * Les résidus de backtest |y − pred| / max(pred, 1) (« scores ») sont
 * comparés aux demi-largeurs relatives (hi − lo) / 2 / max(pred, 1)
 * produites par Monte Carlo. Le facteur obtenu élargit (ou rétrécit)
 * les intervalles futurs autour de la prévision centrale :
 *
 *     [pred − f × half, pred + f × half]   avec half = (hi − lo) / 2
 *
 * Trop peu de points ( < Hyper::CONFORMAL_MIN ) → pas de calibration
 * (facteur null, intervalles Monte Carlo conservés tels quels).
 */
final class Conformal
{
    /**
     * Facteur de calibration conforme.
     *
     * f = quantile(scores, 0.90) / mean(relativeHalfWidths), borné à
     * [Hyper::CONFORMAL_F_MIN, Hyper::CONFORMAL_F_MAX]. Null si moins
     * de Hyper::CONFORMAL_MIN scores, ou si la demi-largeur relative
     * moyenne est nulle (division impossible).
     *
     * @param list<float|int> $scores
     * @param list<float|int> $relativeHalfWidths
     */
    public static function widthFactor(array $scores, array $relativeHalfWidths): ?float
    {
        if (count($scores) < Hyper::CONFORMAL_MIN) {
            return null;
        }
        $meanRel = Stats::mean($relativeHalfWidths);
        if ($meanRel <= 0.0) {
            return null;
        }
        $f = Stats::quantile($scores, 0.90) / $meanRel;

        return min(Hyper::CONFORMAL_F_MAX, max(Hyper::CONFORMAL_F_MIN, $f));
    }

    /**
     * Applique le facteur autour de la prévision centrale $pred.
     * Facteur null → intervalles inchangés. $factor <= 0 est traité
     * comme une absence de calibration (intervalles inchangés).
     *
     * @return array{lo:float,hi:float}
     */
    public static function apply(?float $factor, float $pred, float $lo, float $hi): array
    {
        if ($factor === null || $factor <= 0.0) {
            return ['lo' => $lo, 'hi' => $hi];
        }
        $half = ($hi - $lo) / 2.0;

        return ['lo' => $pred - $factor * $half, 'hi' => $pred + $factor * $half];
    }
}
