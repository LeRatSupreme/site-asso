<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Outils statistiques purs du cœur de prévision : moyennes, quantiles,
 * rétrécissement (partial pooling), lissage de Dirichlet, régression
 * isotonique (PAVA) et samplers Monte Carlo (Gamma, Poisson, binomiale
 * négative) sur \Random\Randomizer (PHP 8.2+).
 *
 * Toutes les méthodes sont statiques et pures ; les entrées sont
 * validées et les tableaux vides produisent des valeurs neutres
 * documentées (jamais d'exception).
 */
final class Stats
{
    /** Garde anti log(0) dans les samplers. */
    private const TINY = 1.0e-300;

    /**
     * Moyenne arithmétique. Tableau vide → 0.0.
     *
     * @param list<float|int> $xs
     */
    public static function mean(array $xs): float
    {
        $n = count($xs);
        if ($n === 0) {
            return 0.0;
        }

        return array_sum($xs) / $n;
    }

    /**
     * Moyenne pondérée sur tableaux associatifs À MEMES CLÉS.
     * Les poids <= 0 sont ignorés (leur valeur ne compte pas) ;
     * somme de poids nulle ou entrées vides → 0.0.
     *
     * @param array<float|int> $xs valeur => ...
     * @param array<float|int> $ws clé => poids
     */
    public static function weightedMean(array $xs, array $ws): float
    {
        $sum  = 0.0;
        $wsum = 0.0;
        foreach ($xs as $key => $x) {
            $w = (float) ($ws[$key] ?? 0.0);
            if ($w <= 0.0) {
                continue;
            }
            $sum  += (float) $x * $w;
            $wsum += $w;
        }

        return $wsum > 0.0 ? $sum / $wsum : 0.0;
    }

    /**
     * Moyenne tronquée : retire floor(n × p) éléments de CHAQUE côté
     * après tri croissant, puis moyenne du centre. Garde toujours au
     * moins 1 élément (si 2 × floor(n×p) >= n, on retranche juste ce
     * qu'il faut). Vide → 0.0.
     *
     * @param list<float|int> $xs
     */
    public static function trimmedMean(array $xs, float $p = Hyper::TRIM): float
    {
        $n = count($xs);
        if ($n === 0) {
            return 0.0;
        }
        sort($xs);
        $cut = (int) floor($n * max(0.0, $p));
        if (2 * $cut >= $n) {
            $cut = intdiv($n - 1, 2); // garde au moins 1 élément
        }

        return self::mean(array_slice($xs, $cut, $n - 2 * $cut));
    }

    /**
     * Quantile linéaire (type 7, comme R/Excel) : position h = (n−1)×q,
     * interpolation entre les deux valeurs encadrantes. Vide → 0.0.
     * q est borné à [0, 1].
     *
     * @param list<float|int> $xs
     */
    public static function quantile(array $xs, float $q): float
    {
        $n = count($xs);
        if ($n === 0) {
            return 0.0;
        }
        sort($xs);
        $q  = min(1.0, max(0.0, $q));
        $h  = ($n - 1) * $q;
        $lo = (int) floor($h);
        $hi = min($lo + 1, $n - 1);

        return (float) $xs[$lo] + ($h - $lo) * ((float) $xs[$hi] - (float) $xs[$lo]);
    }

    /**
     * Médiane = quantile 0.5. Vide → 0.0.
     *
     * @param list<float|int> $xs
     */
    public static function median(array $xs): float
    {
        return self::quantile($xs, 0.5);
    }

    /**
     * MAD normalisée : 1.4826 × médiane(|x − médiane|), pour être
     * comparable à un écart-type sous normalité. Vide → 0.0.
     *
     * @param list<float|int> $xs
     */
    public static function madScaled(array $xs): float
    {
        if (count($xs) === 0) {
            return 0.0;
        }
        $med = self::median($xs);
        $dev = [];
        foreach ($xs as $x) {
            $dev[] = abs((float) $x - $med);
        }

        return 1.4826 * self::median($dev);
    }

    /**
     * Z-score robuste : (x − med) / madS. madS == 0 → 0.0 (pas de
     * signal de dispersion, on ne déclare rien d'atypique).
     */
    public static function robustZ(float $x, float $med, float $madS): float
    {
        return $madS == 0.0 ? 0.0 : ($x - $med) / $madS;
    }

    /**
     * Rétrécissement partial pooling (k = Hyper::K_POOL en mission) :
     * (nJ × meanJ + k × meanG) / (nJ + k).
     *
     * nJ = 0 → moyenne globale ; nJ grand → proche de la moyenne
     * locale. Dénominateur <= 0 (k <= 0 et nJ = 0) → moyenne globale.
     */
    public static function pool(float $meanJ, int $nJ, float $meanG, float $k = Hyper::K_POOL): float
    {
        $denom = $nJ + $k;

        return $denom <= 0.0 ? $meanG : ($nJ * $meanJ + $k * $meanG) / $denom;
    }

    /**
     * Lissage de Dirichlet additif : (c_h + α) / (Σc + α × K) sur les
     * MÊMES clés (K = nb de cases). Somme du résultat = 1. Counts
     * vides → tableau vide.
     *
     * @param array<int|string,float|int> $counts [case => compte] >= 0
     * @return array<int|string,float>
     */
    public static function dirichletSmooth(array $counts, float $alpha): array
    {
        $k = count($counts);
        if ($k === 0) {
            return [];
        }
        $total = array_sum($counts);
        $denom = $total + $alpha * $k;
        $out   = [];
        foreach ($counts as $key => $c) {
            $out[$key] = $denom > 0.0 ? ((float) $c + $alpha) / $denom : 0.0;
        }

        return $out;
    }

    /**
     * Régression isotonique NON-DÉCROISSANTE par PAVA (pool adjacent
     * violators), poids égaux : remplace chaque suffixe décroissant par
     * sa moyenne. Retourne un tableau de MÊME TAILLE (clés 0..n−1),
     * non-décroissant, et de moyenne identique à l'entrée. Vide → vide.
     *
     * @param list<float|int> $ys
     * @return list<float>
     */
    public static function isotonic(array $ys): array
    {
        if (count($ys) === 0) {
            return [];
        }

        // Piles de blocs : valeur moyenne du bloc + taille (poids).
        $vals = [];
        $wts  = [];
        foreach ($ys as $y) {
            $vals[] = (float) $y;
            $wts[]  = 1;
            $i      = count($vals) - 1;
            while ($i > 0 && $vals[$i - 1] > $vals[$i]) {
                $w = $wts[$i - 1] + $wts[$i];
                $vals[$i - 1] = ($vals[$i - 1] * $wts[$i - 1] + $vals[$i] * $wts[$i]) / $w;
                $wts[$i - 1]  = $w;
                array_pop($vals);
                array_pop($wts);
                $i--;
            }
        }

        // Détensionne les blocs (poids entiers : 1 observation chacun au départ).
        $out = [];
        foreach ($vals as $bi => $v) {
            for ($j = 0; $j < $wts[$bi]; $j++) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * Tirage Gamma(shape, scale) par Marsaglia-Tsang (2000).
     * shape < 1 : boost Stuart — gamma(shape+1) × U^(1/shape).
     * shape <= 0 ou scale <= 0 → 0.0 (cas dégradé documenté).
     * Moyenne shape × scale, variance shape × scale².
     */
    public static function gammaSample(float $shape, float $scale, \Random\Randomizer $rng): float
    {
        if ($shape <= 0.0 || $scale <= 0.0) {
            return 0.0;
        }
        if ($shape < 1.0) {
            $u = max(self::uniform01($rng), self::TINY);

            return self::gammaSample($shape + 1.0, $scale, $rng) * pow($u, 1.0 / $shape);
        }
        $d = $shape - 1.0 / 3.0;
        $c = 1.0 / sqrt(9.0 * $d);
        for (;;) {
            $x = self::normalSample($rng);
            $v = pow(1.0 + $c * $x, 3.0);
            if ($v <= 0.0) {
                continue;
            }
            $u  = self::uniform01($rng);
            $x2 = $x * $x;
            // Test de squeeze puis test complet (ln u).
            if ($u < 1.0 - 0.0331 * $x2 * $x2) {
                return $d * $v * $scale;
            }
            if (log($u) < 0.5 * $x2 + $d * (1.0 - $v + log($v))) {
                return $d * $v * $scale;
            }
        }
    }

    /**
     * Tirage Poisson(λ).
     * λ < 30 : méthode de Knuth (produit d'uniformes jusqu'à exp(−λ)).
     * λ >= 30 : inversion séquentielle de Kemp — la CDF est reconstruite
     * de façon EXACTE autour du mode m = floor(λ) : la masse du mode est
     * ancrée par ln(m!) = lnΓ(m+1) (Lanczos, voir logGammaFactorial) puis
     * les masses voisines suivent les ratios EXACTS
     * p_{k±1} / p_k = λ / (k±1) et (k+1) / λ — sans AUCUNE approximation
     * de Stirling. L'erreur relative (~1e-13) de l'ancre Lanczos est
     * partagée par toutes les masses de la marche : la loi normalisée
     * (seule chose comparée à l'uniforme) est donc exacte. Un seul
     * uniforme est consommé, quel que soit λ.
     * λ <= 0 → 0.
     */
    public static function poissonSample(float $lambda, \Random\Randomizer $rng): int
    {
        if ($lambda <= 0.0) {
            return 0;
        }
        if ($lambda < 30.0) {
            $l = exp(-$lambda);
            $k = 0;
            $p = 1.0;
            do {
                ++$k;
                $p *= self::uniform01($rng);
            } while ($p > $l);

            return $k - 1;
        }

        $m     = (int) floor($lambda);
        $pMode = exp(-$lambda + (float) $m * log($lambda) - self::logGammaFactorial((float) $m));

        // Uniforme dans (0, 1) : u = 0 n'est jamais atteint par la CDF.
        do {
            $u = self::uniform01($rng);
        } while ($u <= 0.0);

        $cum = $pMode;
        if ($u < $cum) {
            return $m;
        }

        // Marche alternée autour du mode : côté droit m+j, côté gauche m−j.
        $pUp = $pMode;
        $pDn = $pMode;
        for ($j = 1; $j <= $m; $j++) {
            $pUp = $pUp * $lambda / (float) ($m + $j);
            $cum += $pUp;
            if ($u < $cum) {
                return $m + $j;
            }
            $pDn = $pDn * (float) ($m - $j + 1) / $lambda;
            $cum += $pDn;
            if ($u < $cum) {
                return $m - $j;
            }
        }

        // Côté gauche épuisé (p_0 inclus) : poursuite du côté droit seul.
        // Les termes décroissent géométriquement (k > λ) : terminaison garantie.
        for ($j = $m + 1;; $j++) {
            $pUp = $pUp * $lambda / (float) ($m + $j);
            $cum += $pUp;
            if ($u < $cum) {
                return $m + $j;
            }
        }
    }

    /**
     * Tirage binomiale négative NegBin(mean, r) par mélange
     * Poisson-Gamma : λ ~ Gamma(r, mean/r) puis Poisson(λ).
     * Variance = mean + mean²/r (surdispersion contrôlée par r).
     * mean <= 0 → 0. r <= 0 ou r >= Hyper::R_MAX → Poisson(mean) direct
     * (limite quasi-Poisson documentée).
     */
    public static function negBinSample(float $mean, float $r, \Random\Randomizer $rng): int
    {
        if ($mean <= 0.0) {
            return 0;
        }
        if ($r <= 0.0 || $r >= Hyper::R_MAX) {
            return self::poissonSample($mean, $rng);
        }

        return self::poissonSample(self::gammaSample($r, $mean / $r, $rng), $rng);
    }

    /**
     * Uniforme [0, 1) du Randomizer (point d'entrée unique des samplers).
     * Implémentation PHP 8.2-compatible déterministe : Randomizer::getFloat
     * n'existe qu'à partir de PHP 8.3 ; on tire donc un entier 53 bits et
     * on divise par 2^53 (2^53 valeurs équiprobables dans [0, 1)).
     */
    private static function uniform01(\Random\Randomizer $rng): float
    {
        return $rng->getInt(0, (1 << 53) - 1) / (float) (1 << 53);
    }

    /** Normal(0, 1) par Box-Muller (un seul des deux tirages conservé). */
    private static function normalSample(\Random\Randomizer $rng): float
    {
        $u1 = max(self::uniform01($rng), self::TINY);
        $u2 = self::uniform01($rng);

        return sqrt(-2.0 * log($u1)) * sin(2.0 * M_PI * $u2);
    }

    /**
     * ln(k!) = lnΓ(k + 1), par l'approximation de Lanczos (g = 7, 9
     * coefficients) — utilisée par poissonSample pour ancrer la marche
     * de Kemp sur λ >= 30.
     *
     * Valeurs de contrôle : lnΓ(5) ≈ 3.178054, lnΓ(10) ≈ 12.801827,
     * lnΓ(0.5) ≈ 0.572365 (erreur relative < 1e-12 sur les entiers).
     */
    private static function logGammaFactorial(float $k): float
    {
        $c = [
            0.99999999999980993,
            676.5203681218851,
            -1259.1392167224028,
            771.32342877765313,
            -176.61502916214059,
            12.507343278686905,
            -0.13857109526572012,
            9.9843695780195716e-6,
            1.5056327351493116e-7,
        ];
        $x    = $k + 1.0;
        $base = $c[0];
        for ($i = 1; $i < 9; $i++) {
            $base += $c[$i] / ($x - 1.0 + (float) $i);
        }
        $t = $x + 6.5;

        return 0.5 * log(2.0 * M_PI) + ($x - 0.5) * log($t) - $t + log($base);
    }
}
