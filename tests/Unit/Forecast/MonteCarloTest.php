<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Conformal;
use App\Core\Compta\Forecast\DemandModel;
use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\Hyper;
use App\Core\Compta\Forecast\MonteCarlo;
use PHPUnit\Framework\TestCase;

/**
 * Monte Carlo (loi prédictive), résumés de distributions et calibration
 * conforme. Déterminisme vérifié via Mt19937 seedé ; les tirages zero
 * attendus pour un modèle sans données sont également couverts, tout
 * comme les cas Conformal ( < 15 points → null, clamp [0.5, 3.0] ).
 */
final class MonteCarloTest extends TestCase
{
    public function test_summarize_quantiles_type7(): void
    {
        $s = MonteCarlo::summarize([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);

        self::assertEqualsWithDelta(5.5, $s['mean'], 0.0001);
        self::assertEqualsWithDelta(1.9, $s['lo'], 0.0001, 'Quantile 0.10 : h = 9 × 0.1 = 0.9.');
        self::assertEqualsWithDelta(9.1, $s['hi'], 0.0001, 'Quantile 0.90 : h = 9 × 0.9 = 8.1.');
    }

    public function test_summarize_vide(): void
    {
        self::assertSame(['mean' => 0.0, 'lo' => 0.0, 'hi' => 0.0], MonteCarlo::summarize([]));
    }

    public function test_sum_samples_longueurs_differentes(): void
    {
        $sum = MonteCarlo::sumSamples([[1.0, 2.0, 3.0], [10.0, 20.0]]);

        self::assertSame([11.0, 22.0], $sum, 'Alignement sur la longueur minimale.');
        self::assertSame([], MonteCarlo::sumSamples([]));
    }

    public function test_prob_at_least(): void
    {
        self::assertEqualsWithDelta(0.5, MonteCarlo::probAtLeast([1, 2, 3, 4], 3.0), 0.0001);
        self::assertEqualsWithDelta(1.0, MonteCarlo::probAtLeast([5, 6], 0.0), 0.0001);
        self::assertEqualsWithDelta(0.0, MonteCarlo::probAtLeast([], 10.0), 0.0001);
    }

    public function test_day_samples_deterministe_et_zero_sans_donnees(): void
    {
        $m = DemandModel::fit(self::serie(), [], Hyper::K_POOL);
        $date = new \DateTimeImmutable('2026-09-18', new \DateTimeZone(Hyper::TZ));

        $a = MonteCarlo::daySamples($m, $date, new \Random\Randomizer(new \Random\Engine\Mt19937(11)), 2000);
        $b = MonteCarlo::daySamples($m, $date, new \Random\Randomizer(new \Random\Engine\Mt19937(11)), 2000);

        self::assertCount(2000, $a);
        self::assertSame($a, $b, 'Même graine → tirages identiques.');
        $moy = MonteCarlo::summarize($a);
        self::assertEqualsWithDelta(100.0, $moy['mean'], 10.0, '50 transactions × panier 2.0 ≈ 100 €.');

        // Modèle sans données : expectedN <= 0 → tirages nuls.
        $vide = DemandModel::fit([], [], Hyper::K_POOL);
        $zeros = MonteCarlo::daySamples($vide, $date, new \Random\Randomizer(new \Random\Engine\Mt19937(11)), 5);
        self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0], $zeros);
        self::assertSame([], MonteCarlo::daySamples($m, $date, new \Random\Randomizer(new \Random\Engine\Mt19937(1)), 0));
    }

    public function test_conformal_moins_de_15_points_null(): void
    {
        $scores = array_fill(0, 14, 1.0);
        $rhw = array_fill(0, 14, 0.1);

        self::assertNull(Conformal::widthFactor($scores, $rhw), '14 < CONFORMAL_MIN → pas de calibration.');
        self::assertNotNull(Conformal::widthFactor(array_fill(0, 15, 1.0), array_fill(0, 15, 0.1)));
    }

    public function test_conformal_facteur_borne(): void
    {
        // f = 1.0 / 0.1 = 10 → clamp à CONFORMAL_F_MAX = 3.0.
        self::assertEqualsWithDelta(3.0, Conformal::widthFactor(array_fill(0, 20, 1.0), array_fill(0, 20, 0.1)), 0.0001);
        // f = 0.02 / 0.5 = 0.04 → clamp à CONFORMAL_F_MIN = 0.5.
        self::assertEqualsWithDelta(0.5, Conformal::widthFactor(array_fill(0, 20, 0.02), array_fill(0, 20, 0.5)), 0.0001);
        // Demi-largeur relative moyenne nulle → null (division impossible).
        self::assertNull(Conformal::widthFactor(array_fill(0, 20, 1.0), array_fill(0, 20, 0.0)));
    }

    public function test_conformal_apply_autour_de_pred(): void
    {
        self::assertSame(['lo' => 90.0, 'hi' => 110.0], Conformal::apply(null, 100.0, 90.0, 110.0));
        $c = Conformal::apply(2.0, 100.0, 90.0, 110.0);
        self::assertEqualsWithDelta(80.0, $c['lo'], 0.0001);
        self::assertEqualsWithDelta(120.0, $c['hi'], 0.0001);
    }

    /**
     * 10 jours ouverts (lun–ven × 2), 50 transactions/jour, panier 2.0.
     *
     * @return array<string,DayCurrent>
     */
    private static function serie(): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07'); // lundi
        while (count($days) < 10) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, 100.0, 50, self::horaire(50), array_fill(0, 50, 2.0));
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }

    /**
     * Répartition horaire 8h→17h (10 heures équi-réparties).
     *
     * @return array<int,int>
     */
    private static function horaire(int $n): array
    {
        $h = array_fill(0, 24, 0);
        $base = intdiv($n, 10);
        $reste = $n - $base * 10;
        for ($i = 0; $i < 10; $i++) {
            $h[8 + $i] = $base + ($i < $reste ? 1 : 0);
        }

        return $h;
    }
}
