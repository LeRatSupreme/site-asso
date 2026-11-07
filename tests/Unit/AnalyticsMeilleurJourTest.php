<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Analytics;
use PHPUnit\Framework\TestCase;

/**
 * FIX B — garde-fou de l'insight « Meilleur jour » (helper pur
 * Analytics::meilleurJourSemaine, l'appelant excluant aujourd'hui) :
 * il faut ≥ 2 JOURS distincts ET ≥ 2 SEMAINES calendaires distinctes
 * avec CA > 0, sinon pas d'insight (une part « 60 % » calculée sur un
 * jour et demi est tautologique).
 */
final class AnalyticsMeilleurJourTest extends TestCase
{
    public function test_deux_semaines_avec_plusieurs_jours_insight_emis(): void
    {
        // Lun 05/10 (80 €), mer 07/10 (30 €), lun 12/10 (60 €) : 2 semaines, 3 jours.
        $r = Analytics::meilleurJourSemaine([
            '2026-10-05' => 80.0,
            '2026-10-07' => 30.0,
            '2026-10-12' => 60.0,
        ]);

        self::assertNotNull($r);
        self::assertSame(0, $r['dow'], 'Lundi : 140 € sur 170 €.');
        self::assertSame('Lundi', $r['label']);
        self::assertEqualsWithDelta(140.0 / 170.0 * 100.0, $r['pct'], 0.0001);
    }

    public function test_une_seule_semaine_pas_d_insight(): void
    {
        // Fenêtre « Semaine actuelle » : lundi + mercredi, une seule semaine.
        self::assertNull(Analytics::meilleurJourSemaine([
            '2026-10-19' => 130.9,
            '2026-10-21' => 60.0,
        ]), 'Une seule semaine calendaire → pas d\'insight (le jour partiel fausserait la part).');
    }

    public function test_un_seul_jour_pas_d_insight(): void
    {
        // 3 semaines mais UN seul jour avec ventes.
        self::assertNull(Analytics::meilleurJourSemaine([
            '2026-09-28' => 50.0,
            '2026-10-05' => 0.0,
            '2026-10-12' => 0.0,
        ]), 'Un seul jour avec CA > 0 → pas d\'insight, même sur 3 semaines.');

        self::assertNull(Analytics::meilleurJourSemaine([
            '2026-09-28' => 90.0,
            '2026-10-05' => 0.0,
            '2026-10-12' => 0.0,
        ]));
    }

    public function test_fenetre_vide_ou_negative_pas_d_insight(): void
    {
        self::assertNull(Analytics::meilleurJourSemaine([]));
        self::assertNull(Analytics::meilleurJourSemaine([
            '2026-10-19' => 0.0,
            '2026-10-20' => 0.0,
        ]), 'Aucun CA positif → pas d\'insight.');
    }

    public function test_samedi_meilleur_jour_sur_plusieurs_semaines(): void
    {
        $r = Analytics::meilleurJourSemaine([
            '2026-10-05' => 50.0, // lundi
            '2026-10-10' => 120.0, // samedi
            '2026-10-12' => 45.0, // lundi
            '2026-10-17' => 110.0, // samedi
        ]);

        self::assertNotNull($r);
        self::assertSame(5, $r['dow'], 'Samedi : 230 € sur 325 €.');
        self::assertSame('Samedi', $r['label']);
        self::assertEqualsWithDelta(230.0 / 325.0 * 100.0, $r['pct'], 0.0001);
    }
}
