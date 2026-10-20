<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Stats;
use PHPUnit\Framework\TestCase;

/**
 * Outils statistiques purs : moyenne tronquée, quantile type 7, PAVA
 * exacts sur jeux à la main ; pool() et lissage de Dirichlet ; samplers
 * déterministes (\Random\Randomizer + Mt19937 seedé) validés par
 * moments empiriques. logGammaFactorial est privé : il est testé
 * indirectement via poissonSample(50) (branche d'inversion de Kemp qui
 * l'utilise comme ancre).
 */
final class StatsTest extends TestCase
{
    public function test_trimmed_mean(): void
    {
        self::assertEqualsWithDelta(0.0, Stats::trimmedMean([], 0.1), 0.0, 'Vide → 0.0');
        self::assertEqualsWithDelta(5.5, Stats::trimmedMean([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 0.1), 0.0001, 'Coupe 1 de chaque côté → moyenne de 2..9.');
        self::assertEqualsWithDelta(2.0, Stats::trimmedMean([3, 1, 100, 2, 0], 0.2), 0.0001, 'Non trié : coupe min/max, moyenne de 1,2,3.');
        self::assertEqualsWithDelta(2.0, Stats::trimmedMean([1, 2, 3], 0.5), 0.0001, '2 × floor(n×p) ≥ n → garde au moins 1 élément (la médiane).');
        self::assertEqualsWithDelta(42.0, Stats::trimmedMean([42], 0.1), 0.0, 'Un seul élément → lui-même.');
    }

    public function test_quantile_type7_et_mediane(): void
    {
        self::assertEqualsWithDelta(0.0, Stats::quantile([], 0.5), 0.0, 'Vide → 0.0');
        self::assertEqualsWithDelta(1.9, Stats::quantile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 0.1), 0.0001, 'h = 9 × 0.1 = 0.9 → interpolation.');
        self::assertEqualsWithDelta(9.1, Stats::quantile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 0.9), 0.0001);
        self::assertEqualsWithDelta(1.0, Stats::quantile([1, 2, 3], 0.0), 0.0, 'q = 0 → minimum.');
        self::assertEqualsWithDelta(3.0, Stats::quantile([1, 2, 3], 1.0), 0.0, 'q = 1 → maximum.');
        self::assertEqualsWithDelta(7.5, Stats::quantile([10, 5], 0.5), 0.0, 'Position exacte (h = 0.5, interpolation).');
        self::assertEqualsWithDelta(2.0, Stats::median([3, 1, 2]), 0.0);
        self::assertEqualsWithDelta(2.5, Stats::median([1, 2, 3, 4]), 0.0);
    }

    public function test_isotonic_pava_decroissante_plateaux_croissants(): void
    {
        self::assertSame([], Stats::isotonic([]), 'Vide → vide.');
        self::assertSame([3.0, 3.0, 3.0, 3.0, 3.0], Stats::isotonic([5, 4, 3, 2, 1]), 'Strictement décroissante → plateau unique (moyenne conservée).');
        self::assertSame([1.0, 2.0, 2.0, 2.0], Stats::isotonic([1, 3, 2, 1]), 'Pic puis chute : suffixe moyené.');
        self::assertSame([1.0, 2.0, 3.0], Stats::isotonic([1, 2, 3]), 'Déjà croissante → inchangée.');

        $out = Stats::isotonic([4.0, 3.0, 3.0, 1.0, 1.0, 0.0]);
        self::assertCount(6, $out);
        for ($i = 1; $i < 6; $i++) {
            self::assertGreaterThanOrEqual($out[$i - 1], $out[$i], 'Non-décroissant.');
        }
        self::assertEqualsWithDelta(array_sum([4, 3, 3, 1, 1, 0]) / 6.0, array_sum($out) / 6.0, 1.0e-12, 'Moyenne conservée.');
    }

    public function test_pool(): void
    {
        self::assertEqualsWithDelta(50.0, Stats::pool(100.0, 0, 50.0, 2.0), 0.0, 'nJ = 0 → moyenne globale.');
        self::assertEqualsWithDelta(50.0, Stats::pool(100.0, 0, 50.0, 0.0), 0.0, 'Dénominateur <= 0 → moyenne globale.');
        self::assertEqualsWithDelta(100100.0 / 1002.0, Stats::pool(100.0, 1000, 50.0, 2.0), 1.0e-9, 'nJ grand → proche du local : (100000 + 100) / 1002.');
        self::assertEqualsWithDelta(4.0 / 3.0, Stats::pool(2.0, 1, 1.0, 2.0), 0.0001, '(1×2 + 2×1) / 3.');
    }

    public function test_dirichlet_smooth_somme_un(): void
    {
        self::assertSame([], Stats::dirichletSmooth([], 1.0), 'Counts vides → vide.');

        $g = Stats::dirichletSmooth(array_fill(0, 24, 0), 1.0);
        self::assertCount(24, $g);
        self::assertEqualsWithDelta(1.0, array_sum($g), 1.0e-12, 'Somme = 1.');
        foreach ($g as $v) {
            self::assertEqualsWithDelta(1.0 / 24.0, $v, 1.0e-12, 'Cases nulles : lissage uniforme.');
        }

        $s = Stats::dirichletSmooth([10 => 3.0, 14 => 1.0], 1.0);
        self::assertEqualsWithDelta(1.0, array_sum($s), 1.0e-12);
        self::assertEqualsWithDelta(4.0 / 6.0, $s[10], 1.0e-12, '(3+1) / (4+2).');
        self::assertEqualsWithDelta(2.0 / 6.0, $s[14], 1.0e-12);
    }

    public function test_samplers_deterministes(): void
    {
        $a = [];
        $rngA = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        for ($i = 0; $i < 5; $i++) {
            $a[] = Stats::poissonSample(50.0, $rngA);
        }
        $b = [];
        $rngB = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        for ($i = 0; $i < 5; $i++) {
            $b[] = Stats::poissonSample(50.0, $rngB);
        }
        self::assertSame($a, $b, 'Même graine → tirages identiques.');
    }

    public function test_poisson_5_branche_knuth(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        $n = 20000;
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum += Stats::poissonSample(5.0, $rng);
        }

        self::assertEqualsWithDelta(5.0, $sum / $n, 0.1, 'Moyenne empirique ≈ λ (branche Knuth, λ < 30).');
    }

    public function test_poisson_50_moyenne_et_variance(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        $n = 50000;
        $sum = 0.0;
        $sum2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) Stats::poissonSample(50.0, $rng);
            $sum += $x;
            $sum2 += $x * $x;
        }
        $mean = $sum / $n;
        $var  = $sum2 / $n - $mean * $mean;

        self::assertEqualsWithDelta(50.0, $mean, 0.5, 'Moyenne ≈ λ (λ ≥ 30 : inversion de Kemp, ancre logGammaFactorial).');
        self::assertEqualsWithDelta(50.0, $var, 50.0 * 0.15, 'Variance ≈ λ ± 15 %.');
    }

    public function test_neg_bin_62_10_moyenne_et_variance(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        $n = 50000;
        $sum = 0.0;
        $sum2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) Stats::negBinSample(62.0, 10.0, $rng);
            $sum += $x;
            $sum2 += $x * $x;
        }
        $mean = $sum / $n;
        $var  = $sum2 / $n - $mean * $mean;

        self::assertEqualsWithDelta(62.0, $mean, 62.0 * 0.01, 'Moyenne ≈ mean ± 1 %.');
        self::assertEqualsWithDelta(62.0 + 62.0 * 62.0 / 10.0, $var, 446.4 * 0.20, 'Variance ≈ mean + mean²/r = 446.4 ± 20 %.');
    }

    public function test_gamma_3_2_moyenne(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        $n = 50000;
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += Stats::gammaSample(3.0, 2.0, $rng);
        }

        self::assertEqualsWithDelta(6.0, $sum / $n, 6.0 * 0.05, 'Moyenne shape × scale = 6 ± 5 %.');
    }

    public function test_cas_degrades_samplers(): void
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(1));

        self::assertSame(0, Stats::poissonSample(0.0, $rng), 'λ <= 0 → 0.');
        self::assertSame(0, Stats::poissonSample(-3.0, $rng));
        self::assertEqualsWithDelta(0.0, Stats::gammaSample(0.0, 2.0, $rng), 0.0, 'shape <= 0 → 0.0.');
        self::assertEqualsWithDelta(0.0, Stats::gammaSample(3.0, 0.0, $rng), 0.0, 'scale <= 0 → 0.0.');
        self::assertSame(0, Stats::negBinSample(0.0, 10.0, $rng), 'mean <= 0 → 0.');
        $direct = Stats::negBinSample(62.0, 0.0, $rng);
        self::assertGreaterThanOrEqual(30, $direct, 'r <= 0 → Poisson(62) direct (loi limite documentée).');
        self::assertLessThanOrEqual(95, $direct);
    }
}
