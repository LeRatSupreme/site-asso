<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\ComptaCalc;
use PHPUnit\Framework\TestCase;

/**
 * Fenêtres de dates du kiosque admin : « 7 derniers jours », « semaine
 * actuelle » et « mois en cours ». Garantit notamment que « Ce mois »
 * commence bien le 1er du mois (heure de Paris) et que « Semaine
 * actuelle » commence le lundi, même quand la semaine glissante couvre
 * les mêmes dates (ex. le 7 du mois : J-6 = 1er).
 */
final class ComptaWindowsTest extends TestCase
{
    private function paris(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Paris'));
    }

    public function test_le_mois_commence_le_premier_du_mois(): void
    {
        $w = ComptaCalc::monthToDateWindow($this->paris('2026-10-07 14:00:00'));

        self::assertSame('2026-10-01', $w['from'], 'Le mois d\'octobre commence le 1er octobre.');
        self::assertSame('2026-10-07', $w['to']);
    }

    public function test_le_1er_du_mois_la_fenetre_est_un_jour(): void
    {
        $w = ComptaCalc::monthToDateWindow($this->paris('2026-01-01 09:30:00'));

        self::assertSame('2026-01-01', $w['from']);
        self::assertSame('2026-01-01', $w['to']);
    }

    public function test_mois_de_fevrier_bissextile(): void
    {
        $w = ComptaCalc::monthToDateWindow($this->paris('2028-02-29 12:00:00'));

        self::assertSame('2028-02-01', $w['from']);
        self::assertSame('2028-02-29', $w['to']);
    }

    public function test_7_derniers_jours_couvre_j6_a_j(): void
    {
        $w = ComptaCalc::last7DaysWindow($this->paris('2026-10-07 08:00:00'));

        self::assertSame('2026-10-01', $w['from'], 'J-6 du 7 octobre = 1er octobre.');
        self::assertSame('2026-10-07', $w['to']);
    }

    public function test_semaine_actuelle_commence_le_lundi(): void
    {
        // Mercredi 7 octobre 2026 → lundi 5 octobre.
        $w = ComptaCalc::currentWeekWindow($this->paris('2026-10-07 08:00:00'));

        self::assertSame('2026-10-05', $w['from'], 'La semaine actuelle démarre le lundi.');
        self::assertSame('2026-10-07', $w['to']);
    }

    public function test_semaine_actuelle_le_dimanche_reste_sur_la_meme_semaine(): void
    {
        // Dimanche 11 octobre 2026 → lundi 5 octobre (semaine ISO lundi→dimanche).
        $w = ComptaCalc::currentWeekWindow($this->paris('2026-10-11 20:00:00'));

        self::assertSame('2026-10-05', $w['from']);
        self::assertSame('2026-10-11', $w['to']);
    }

    public function test_semaine_actuelle_le_lundi_fenetre_d_un_jour(): void
    {
        // Lundi 5 octobre 2026 : la semaine vient de commencer.
        $w = ComptaCalc::currentWeekWindow($this->paris('2026-10-05 09:00:00'));

        self::assertSame('2026-10-05', $w['from']);
        self::assertSame('2026-10-05', $w['to']);
    }

    public function test_semaine_actuelle_instable_utc_bascule_au_jour_paris(): void
    {
        // 22 h UTC le 6 oct = 0 h Paris le 7 oct (mercredi) → lundi 5.
        $utc = new \DateTimeImmutable('2026-10-06 22:00:00', new \DateTimeZone('UTC'));

        $w = ComptaCalc::currentWeekWindow($utc);

        self::assertSame('2026-10-05', $w['from']);
        self::assertSame('2026-10-07', $w['to']);
    }

    public function test_7_derniers_jours_et_mois_divergent_apres_le_7(): void
    {
        $w7 = ComptaCalc::last7DaysWindow($this->paris('2026-10-10 08:00:00'));
        $wM = ComptaCalc::monthToDateWindow($this->paris('2026-10-10 08:00:00'));

        self::assertSame('2026-10-04', $w7['from'], 'La semaine glissante avance.');
        self::assertSame('2026-10-01', $wM['from'], 'Le mois reste ancré au 1er.');
        self::assertNotSame($w7['from'], $wM['from']);
    }

    public function test_un_instant_utc_en_debut_de_nuit_paris_bascule_le_jour(): void
    {
        // 22 h UTC le 6 oct = 0 h Paris le 7 oct : la fenêtre doit être
        // celle du 7 octobre (heure de Paris, pas UTC).
        $utc = new \DateTimeImmutable('2026-10-06 22:00:00', new \DateTimeZone('UTC'));

        $wM = ComptaCalc::monthToDateWindow($utc);
        $w7 = ComptaCalc::last7DaysWindow($utc);

        self::assertSame('2026-10-07', $wM['to']);
        self::assertSame('2026-10-01', $wM['from']);
        self::assertSame('2026-10-01', $w7['from']);
    }

    public function test_sans_argument_la_fenetre_utilise_aujourdhui_paris(): void
    {
        $expectedTo = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');

        $wM = ComptaCalc::monthToDateWindow();
        $w7 = ComptaCalc::last7DaysWindow();

        self::assertSame($expectedTo, $wM['to']);
        self::assertSame($expectedTo, $w7['to']);
    }
}
