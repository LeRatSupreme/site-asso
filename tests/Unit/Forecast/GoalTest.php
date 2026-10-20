<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Goal;
use PHPUnit\Framework\TestCase;

/**
 * Objectif hebdomadaire et horizon glissant : décroissance de la
 * probabilité d'atteinte avec l'objectif, cohérence du manque par jour
 * (0 si attendu >= cible, null si plus aucun jour restant) et cas
 * limites (aucun jour restant).
 */
final class GoalTest extends TestCase
{
    /** @return list<list<float>> 3 jours restants, 300 € par tirage et par jour. */
    private static function tirages(): array
    {
        $jour = [280.0, 300.0, 320.0];

        return [$jour, $jour, $jour];
    }

    public function test_prob_atteinte_decroissante_avec_objectif(): void
    {
        $p100  = Goal::week(self::tirages(), 50.0, 100.0);
        $p950  = Goal::week(self::tirages(), 50.0, 950.0);
        $p1100 = Goal::week(self::tirages(), 50.0, 1100.0);

        self::assertGreaterThanOrEqual($p950['prob_atteinte'], $p100['prob_atteinte']);
        self::assertGreaterThanOrEqual($p1100['prob_atteinte'], $p950['prob_atteinte']);
        self::assertEqualsWithDelta(1.0, $p100['prob_atteinte'], 0.0001, '950 € attendus ≥ 100 €.');
        self::assertEqualsWithDelta(2.0 / 3.0, $p950['prob_atteinte'], 0.0001, 'Tirages totaux [890, 950, 1010] : 2 sur 3 ≥ 950.');
        self::assertEqualsWithDelta(0.0, $p1100['prob_atteinte'], 0.0001, 'Aucun tirage ≥ 1100.');

        $p0 = Goal::week(self::tirages(), 50.0, 0.0);
        self::assertEqualsWithDelta(1.0, $p0['prob_atteinte'], 0.0001);
    }

    public function test_manquant_par_jour_coherent(): void
    {
        // Cumuls : 50 réels + (280+300+320) → tirages totaux [890, 950, 1010].
        $r = Goal::week(self::tirages(), 50.0, 500.0);

        self::assertSame(3, $r['jours_restants']);
        self::assertEqualsWithDelta(950.0, $r['attendu'], 0.0001, '50 réels + 3 × 300.');
        self::assertEqualsWithDelta(0.0, $r['manquant_par_jour'], 0.0001, 'Attendu 950 ≥ cible 500 → manque nul.');
        self::assertEqualsWithDelta(1.0, $r['prob_atteinte'], 0.0001);

        $r2 = Goal::week(self::tirages(), 50.0, 1100.0);
        self::assertEqualsWithDelta((1100.0 - 950.0) / 3.0, $r2['manquant_par_jour'], 0.0001, 'Attendu < cible → manque réparti sur les jours restants.');
        self::assertEqualsWithDelta(0.0, $r2['prob_atteinte'], 0.0001, 'Max des tirages 1010 < 1100.');
    }

    public function test_aucun_jour_restant(): void
    {
        $atteint = Goal::week([], 60.0, 50.0);
        self::assertEqualsWithDelta(1.0, $atteint['prob_atteinte'], 0.0001);
        self::assertEqualsWithDelta(0.0, $atteint['manquant_par_jour'], 0.0001);
        self::assertSame(0, $atteint['jours_restants']);
        self::assertEqualsWithDelta(60.0, $atteint['attendu'], 0.0001);

        $rate = Goal::week([], 40.0, 50.0);
        self::assertEqualsWithDelta(0.0, $rate['prob_atteinte'], 0.0001);
        self::assertNull($rate['manquant_par_jour'], 'Plus aucun jour pour rattraper → null.');
    }

    public function test_horizon(): void
    {
        $vide = Goal::horizon([], 40.0);
        self::assertEqualsWithDelta(40.0, $vide['attendu'], 0.0001);
        self::assertEqualsWithDelta(40.0, $vide['lo'], 0.0001);
        self::assertEqualsWithDelta(40.0, $vide['hi'], 0.0001);

        $h = Goal::horizon([[10.0, 20.0]], 5.0);
        self::assertEqualsWithDelta(20.0, $h['attendu'], 0.0001, '(5+10 + 5+20)/2.');
        self::assertEqualsWithDelta(24.0, $h['hi'], 0.0001, 'Quantile 0.90 de [15, 25].');
        self::assertEqualsWithDelta(16.0, $h['lo'], 0.0001, 'Quantile 0.10 de [15, 25].');
    }

    /**
     * Contrat d'UNITÉ de prob_atteinte : une FRACTION dans [0, 1]
     * (P(total ≥ objectif)), jamais un pourcentage 0–100. Les vues
     * (carte « Objectif », chip kiosque) multiplient par 100 pour
     * l'affichage — régression du bug « 0 % » d'octobre 2026.
     */
    public function test_prob_atteinte_toujours_une_fraction_0_1(): void
    {
        // Attendu 950 € pour un objectif intermédiaire : probabilité strictement entre 0 et 1.
        $r = Goal::week(self::tirages(), 50.0, 900.0);
        self::assertGreaterThanOrEqual(0.0, $r['prob_atteinte']);
        self::assertLessThanOrEqual(1.0, $r['prob_atteinte']);
        self::assertGreaterThan(0.0, $r['prob_atteinte'], 'Objectif 900 : les tirages 950 et 1010 l\'atteignent.');
        self::assertLessThan(1.0, $r['prob_atteinte'], 'Objectif 900 : le tirage 890 ne l\'atteint pas.');

        // Bornes et cas limite : la fraction reste dans [0, 1].
        foreach ([500.0, 950.0, 1100.0] as $objectif) {
            $sem = Goal::week(self::tirages(), 50.0, $objectif);
            self::assertGreaterThanOrEqual(0.0, $sem['prob_atteinte']);
            self::assertLessThanOrEqual(1.0, $sem['prob_atteinte']);
        }
    }
}
