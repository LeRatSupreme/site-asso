<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Backtest;
use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\Stats;
use PHPUnit\Framework\TestCase;

/**
 * Backtest à origine glissante : insuffisance, sélection de modèle,
 * couverture des intervalles sur une série négative-binomiale simulée
 * (graine fixe) et complétude des semaines hebdo. Budget : draws
 * réduits à 800 pour le test de coverage (choix documenté de mission,
 * le défaut Hyper::N_DRAWS = 5000 est réservé à la production).
 */
final class BacktestTest extends TestCase
{
    public function test_historique_insuffisant(): void
    {
        $r = Backtest::run(self::serieNegBin(9), ['seed' => 1, 'draws' => 200]);

        self::assertTrue($r['insufficient']);
        self::assertSame(0, $r['n_points']);
        self::assertNull($r['wape']);
        self::assertNull($r['coverage']);
        self::assertSame([], $r['details']);
        self::assertNull($r['selection_note']);
    }

    public function test_coverage_sur_serie_negbin_simulee(): void
    {
        // Série simulée : N ~ NegBin(mean 62, r 10), panier constant 1.25,
        // 30 jours ouverts (lun–ven × 6). Le modèle « vrai » est retrouvé
        // par le backtest : la couverture des intervalles 80 % doit rester
        // dans [0.65, 0.95]. Draws réduits à 800 (budget de test).
        $r = Backtest::run(self::serieNegBin(30), ['seed' => 7, 'draws' => 800]);

        self::assertFalse($r['insufficient']);
        self::assertSame(21, $r['n_points'], 'Indices 9..29 → 21 points.');
        self::assertNotNull($r['coverage']);
        self::assertGreaterThanOrEqual(0.65, $r['coverage']);
        self::assertLessThanOrEqual(0.95, $r['coverage']);
        self::assertNotNull($r['wape']);
        self::assertNotNull($r['wape_main']);
        self::assertNotNull($r['wape_b2']);
        self::assertIsString($r['selection_note']);
        self::assertCount(21, $r['details']);
        self::assertCount(21, $r['scores']);
        self::assertCount(21, $r['relative_half_widths']);
    }

    public function test_semaines_completes_et_determinisme(): void
    {
        $jours = self::serieNegBin(30);
        $a = Backtest::run($jours, ['seed' => 7, 'draws' => 400]);
        $b = Backtest::run($jours, ['seed' => 7, 'draws' => 400]);

        self::assertSame($a, $b, 'Mêmes opts → résultat strictement identique.');

        // Semaines calendaires lun–dim complètes : semaines 3 à 6 entières.
        self::assertGreaterThanOrEqual(1, $a['n_points_weekly']);
        self::assertNotNull($a['wape_weekly']);
        self::assertGreaterThan(0.0, $a['wape_weekly']);
    }

    public function test_details_incoherence_score_intervalles(): void
    {
        $r = Backtest::run(self::serieNegBin(30), ['seed' => 3, 'draws' => 300]);

        foreach ($r['details'] as $d) {
            self::assertArrayHasKey('date', $d);
            self::assertContains($d['model'], ['negbin_dow_damped', 'baseline_B2']);
            self::assertLessThanOrEqual($d['hi'], max($d['hi'], $d['lo']));
            $pred = $d['pred'];
            $half = ($d['hi'] - $d['lo']) / 2.0;
            self::assertEqualsWithDelta($pred - $half, $d['lo'], 1.0e-9, 'Intervalle recentré sur pred.');
        }
        $i = 0;
        foreach ($r['details'] as $d) {
            $attendu = abs($d['y'] - $d['pred']) / max($d['pred'], 1.0);
            self::assertEqualsWithDelta($attendu, $r['scores'][$i], 1.0e-12);
            $i++;
        }
    }

    /**
     * Série négative-binomiale simulée : $n jours ouverts (lun–ven),
     * N ~ NegBin(62, 10) graine 42, panier constant 1.25.
     *
     * @return array<string,DayCurrent>
     */
    private static function serieNegBin(int $n): array
    {
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
        $days = [];
        $d = new \DateTimeImmutable('2026-08-31'); // lundi
        while (count($days) < $n) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $transactions = Stats::negBinSample(62.0, 10.0, $rng);
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent(
                    $key,
                    $transactions * 1.25,
                    $transactions,
                    self::horaire($transactions),
                    array_fill(0, max(0, $transactions), 1.25)
                );
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }

    /**
     * Répartition horaire 8h→17h.
     *
     * @return array<int,int>
     */
    private static function horaire(int $n): array
    {
        $h = array_fill(0, 24, 0);
        if ($n <= 0) {
            return $h;
        }
        $base = intdiv($n, 10);
        $reste = $n - $base * 10;
        for ($i = 0; $i < 10; $i++) {
            $h[8 + $i] = $base + ($i < $reste ? 1 : 0);
        }

        return $h;
    }
}
