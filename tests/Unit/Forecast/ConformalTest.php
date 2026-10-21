<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Conformal;
use App\Core\Compta\Forecast\Hyper;
use PHPUnit\Framework\TestCase;

/**
 * Calibration conforme : facteur borné [CONFORMAL_F_MIN, F_MAX] et
 * borne basse JAMAIS négative (un CA ne peut pas être négatif — régression
 * « Hier : −45,53 € à 202,50 € » observée en prod le 21/10/2026).
 */
final class ConformalTest extends TestCase
{
    public function test_facteur_borne_dans_les_limites(): void
    {
        // Scores serrés, demi-largeurs larges → facteur < 1 (plancher 0,5).
        $petit = Conformal::widthFactor(array_fill(0, 30, 0.01), array_fill(0, 30, 0.5));
        self::assertNotNull($petit);
        self::assertEqualsWithDelta(Hyper::CONFORMAL_F_MIN, $petit, 0.0);

        // Gros résidus (B2 sur jours bas), demi-largeurs étroites →
        // facteur saturé au plafond 3 (cas prod : q90 1,15 / mean_hw 0,13).
        $grand = Conformal::widthFactor(array_fill(0, 30, 1.2), array_fill(0, 30, 0.1));
        self::assertNotNull($grand);
        self::assertEqualsWithDelta(Hyper::CONFORMAL_F_MAX, $grand, 0.0);

        // Trop peu de scores → pas de calibration.
        self::assertNull(Conformal::widthFactor([0.5], [0.2]));
        // Demi-largeur relative moyenne nulle → null (division impossible).
        self::assertNull(Conformal::widthFactor(array_fill(0, 30, 0.5), array_fill(0, 30, 0.0)));
    }

    public function test_borne_basse_jamais_negative_meme_facteur_3(): void
    {
        // Factorisation ×3 sur une faible prévision : le lo brut passerait
        // à 50 − 3×20 = −10 → il DOIT être clampé à 0 (jamais −10).
        $cal = Conformal::apply(3.0, 50.0, 30.0, 70.0);
        self::assertEqualsWithDelta(0.0, $cal['lo'], 0.0, 'Borne basse clampée à 0 (CA négatif impossible).');
        self::assertEqualsWithDelta(110.0, $cal['hi'], 0.0, 'La borne haute suit la même élargissement (50 + 3×20).');

        // Factorisation ×3 sur une prévision haute : lo reste positif.
        $cal2 = Conformal::apply(3.0, 100.0, 80.0, 120.0);
        self::assertEqualsWithDelta(40.0, $cal2['lo'], 0.0);
        self::assertEqualsWithDelta(160.0, $cal2['hi'], 0.0);

        // Sans calibration : lo fourni négatif (impossible via MC mais
        // défensif) → clampé quand même.
        $cal3 = Conformal::apply(null, 50.0, -5.0, 90.0);
        self::assertEqualsWithDelta(0.0, $cal3['lo'], 0.0);
        self::assertEqualsWithDelta(90.0, $cal3['hi'], 0.0);

        // Facteur <= 0 traité comme absence de calibration (clamp inclus).
        $cal4 = Conformal::apply(0.0, 50.0, -5.0, 90.0);
        self::assertEqualsWithDelta(0.0, $cal4['lo'], 0.0);
    }
}
