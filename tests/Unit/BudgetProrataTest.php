<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\ComptaCalc;
use PHPUnit\Framework\TestCase;

/**
 * Tests des helpers de découpage de période et de prorata budgétaire
 * ajoutés à ComptaCalc pour la page Budgets : mois couverts par une
 * plage de jours, et part du budget mensuel que couvre une période
 * donnée (le « prévu » ajusté au prorata des jours).
 */
final class BudgetProrataTest extends TestCase
{
    // -------------------------------------------------------------
    //  ComptaCalc::monthsCoveredBy()
    // -------------------------------------------------------------

    public function test_une_journee_couvre_un_seul_mois(): void
    {
        self::assertSame(
            [[2026, 9]],
            ComptaCalc::monthsCoveredBy('2026-09-30', '2026-09-30'),
            'Du 30/09 au 30/09 : le budget compare au mois de septembre entier.'
        );
    }

    public function test_plage_courante_couvre_les_mois_touches(): void
    {
        self::assertSame(
            [[2026, 8], [2026, 9], [2026, 10]],
            ComptaCalc::monthsCoveredBy('2026-08-15', '2026-10-02')
        );
    }

    public function test_frontiere_d_annee(): void
    {
        self::assertSame(
            [[2025, 12], [2026, 1]],
            ComptaCalc::monthsCoveredBy('2025-12-31', '2026-01-01')
        );
    }

    public function test_plafond_garde_les_24_derniers_mois(): void
    {
        $months = ComptaCalc::monthsCoveredBy('2024-01-01', '2026-09-30');

        self::assertCount(24, $months, 'Période trop large : plafonnée aux 24 derniers mois.');
        self::assertSame([2024, 10], $months[0], 'Les mois les plus anciens sont retirés.');
        self::assertSame([2026, 9], $months[23]);
    }

    // -------------------------------------------------------------
    //  ComptaCalc::monthBudgetFactor()
    // -------------------------------------------------------------

    public function test_mois_entier_dans_la_periode_facteur_1(): void
    {
        self::assertSame(1.0, ComptaCalc::monthBudgetFactor('2026-09-01', '2026-09-01', '2026-09-30'));
        self::assertSame(1.0, ComptaCalc::monthBudgetFactor('2026-09-01', '2026-08-15', '2026-09-30'));
    }

    public function test_un_jour_en_septembre_vaut_un_trentieme(): void
    {
        self::assertEqualsWithDelta(
            1 / 30,
            ComptaCalc::monthBudgetFactor('2026-09-01', '2026-09-30', '2026-09-30'),
            1e-12,
            'Une période d’un jour compare à 1/30 du budget de septembre.'
        );
    }

    public function test_mois_partiellement_couvert_au_milieu(): void
    {
        // Du 15 au 30 septembre = 16 jours couverts sur 30.
        self::assertEqualsWithDelta(
            16 / 30,
            ComptaCalc::monthBudgetFactor('2026-09-01', '2026-09-15', '2026-09-30'),
            1e-12
        );
    }

    public function test_periode_a_cheval_sur_deux_mois(): void
    {
        // Du 15/09 au 05/10 : 16 jours en septembre (30 j), 5 en octobre (31 j).
        self::assertEqualsWithDelta(
            16 / 30,
            ComptaCalc::monthBudgetFactor('2026-09-01', '2026-09-15', '2026-10-05'),
            1e-12
        );
        self::assertEqualsWithDelta(
            5 / 31,
            ComptaCalc::monthBudgetFactor('2026-10-01', '2026-09-15', '2026-10-05'),
            1e-12
        );
    }

    public function test_fevrier_bissextile_sur_29_jours(): void
    {
        self::assertSame(1.0, ComptaCalc::monthBudgetFactor('2024-02-01', '2024-02-01', '2024-02-29'));
        self::assertEqualsWithDelta(
            10 / 29,
            ComptaCalc::monthBudgetFactor('2024-02-01', '2024-02-20', '2024-02-29'),
            1e-12
        );
    }

    public function test_periode_hors_mois_facteur_zero(): void
    {
        self::assertSame(0.0, ComptaCalc::monthBudgetFactor('2026-09-01', '2026-10-01', '2026-10-31'), 'Période après le mois.');
        self::assertSame(0.0, ComptaCalc::monthBudgetFactor('2026-09-01', '2026-08-01', '2026-08-31'), 'Période avant le mois.');
    }

    public function test_periode_inversee_facteur_zero(): void
    {
        // Défense : des bornes inversées ne produisent aucun chevauchement
        // exploitable (le contrôleur les échange en amont, le helper ne
        // doit jamais renvoyer un facteur fantaisiste).
        self::assertSame(0.0, ComptaCalc::monthBudgetFactor('2026-09-01', '2026-09-10', '2026-09-05'));
    }
}
