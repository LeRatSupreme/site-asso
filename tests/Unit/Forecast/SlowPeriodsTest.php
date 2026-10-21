<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\SlowPeriods;
use PHPUnit\Framework\TestCase;

/**
 * Périodes creuses saisies à la main : analyse du format
 * « facteur;AAAA-MM-JJ/AAAA-MM-JJ;… », validation stricte (facteur ∈
 * [0,1 ; 1], dates existantes, début ≤ fin, 10 plages max), facteur par
 * date (bornes inclusives), plages actives et libellés.
 */
final class SlowPeriodsTest extends TestCase
{
    public function test_parse_valide_simple_et_multiple(): void
    {
        $simple = SlowPeriods::parse('0.8;2026-10-17/2026-11-02');
        self::assertNotNull($simple);
        self::assertEqualsWithDelta(0.8, $simple['facteur'], 0.0);
        self::assertSame([['start' => '2026-10-17', 'end' => '2026-11-02']], $simple['ranges']);

        $multiple = SlowPeriods::parse('0.75;2026-10-17/2026-11-02;2026-12-19/2027-01-04');
        self::assertNotNull($multiple);
        self::assertCount(2, $multiple['ranges']);
        self::assertSame('2027-01-04', $multiple['ranges'][1]['end']);

        // Virgule décimale acceptée (saisie FR).
        $virgule = SlowPeriods::parse('0,9;2026-10-17/2026-11-02');
        self::assertNotNull($virgule);
        self::assertEqualsWithDelta(0.9, $virgule['facteur'], 0.0);
    }

    public function test_parse_vide_config_neutre(): void
    {
        $vide = SlowPeriods::parse('');
        self::assertNotNull($vide);
        self::assertEqualsWithDelta(1.0, $vide['facteur'], 0.0);
        self::assertSame([], $vide['ranges']);
    }

    public function test_parse_invalide(): void
    {
        // Facteur hors bornes.
        self::assertNull(SlowPeriods::parse('1.5;2026-10-17/2026-11-02'));
        self::assertNull(SlowPeriods::parse('0.05;2026-10-17/2026-11-02'));
        // Facteur non numérique, plages manquantes.
        self::assertNull(SlowPeriods::parse('creuse;2026-10-17/2026-11-02'));
        self::assertNull(SlowPeriods::parse('0.8'));
        // Fragments mal formés.
        self::assertNull(SlowPeriods::parse('0.8;2026-10-17'));
        self::assertNull(SlowPeriods::parse('0.8;17/10/2026-02/11/2026'));
        // Date inexistante (roulis refusé) ou début > fin.
        self::assertNull(SlowPeriods::parse('0.8;2026-02-30/2026-03-05'));
        self::assertNull(SlowPeriods::parse('0.8;2026-11-02/2026-10-17'));
        // Trop de plages (11).
        self::assertNull(SlowPeriods::parse('0.8;' . implode(';', array_map(
            static fn (int $i): string => sprintf('2027-%02d-01/2027-%02d-28', max(1, $i), max(1, $i)),
            range(1, 11)
        ))));
    }

    public function test_facteur_par_date_bornes_inclusives(): void
    {
        $slow = SlowPeriods::parse('0.8;2026-10-17/2026-11-02');
        $tz = new \DateTimeZone('Europe/Paris');

        self::assertEqualsWithDelta(1.0, SlowPeriods::facteurPour($slow, new \DateTimeImmutable('2026-10-16', $tz)), 0.0, 'La veille : hors plage.');
        self::assertEqualsWithDelta(0.8, SlowPeriods::facteurPour($slow, new \DateTimeImmutable('2026-10-17', $tz)), 0.0, 'Premier jour : inclus.');
        self::assertEqualsWithDelta(0.8, SlowPeriods::facteurPour($slow, new \DateTimeImmutable('2026-10-25 14:30', $tz)), 0.0);
        self::assertEqualsWithDelta(0.8, SlowPeriods::facteurPour($slow, new \DateTimeImmutable('2026-11-02', $tz)), 0.0, 'Dernier jour : inclus.');
        self::assertEqualsWithDelta(1.0, SlowPeriods::facteurPour($slow, new \DateTimeImmutable('2026-11-03', $tz)), 0.0, 'Le lendemain : hors plage.');

        // Config nulle ou sans plage → jamais d'ajustement.
        self::assertEqualsWithDelta(1.0, SlowPeriods::facteurPour(null, new \DateTimeImmutable('2026-10-20', $tz)), 0.0);
        self::assertEqualsWithDelta(1.0, SlowPeriods::facteurPour(SlowPeriods::parse(''), new \DateTimeImmutable('2026-10-20', $tz)), 0.0);
    }

    public function test_plages_actives_et_libelles(): void
    {
        $slow = SlowPeriods::parse('0.8;2026-10-17/2026-11-02');
        $tz = new \DateTimeZone('Europe/Paris');

        $actives = SlowPeriods::plagesActives($slow, new \DateTimeImmutable('2026-10-20', $tz), new \DateTimeImmutable('2026-11-19', $tz));
        self::assertSame([['start' => '2026-10-17', 'end' => '2026-11-02']], $actives);

        // Plage passée (fin < début de fenêtre) : inactive.
        self::assertSame([], SlowPeriods::plagesActives($slow, new \DateTimeImmutable('2026-11-10', $tz), new \DateTimeImmutable('2026-12-10', $tz)));
        // Config nulle : aucune.
        self::assertSame([], SlowPeriods::plagesActives(null, new \DateTimeImmutable('2026-10-20', $tz), new \DateTimeImmutable('2026-11-19', $tz)));

        self::assertSame('17/10 → 02/11 (×0,8)', SlowPeriods::libellePlage($actives[0], 0.8));
        self::assertSame('17/10 → 02/11 (×0,85)', SlowPeriods::libellePlage($actives[0], 0.85));
    }

    public function test_normalise_roundtrip(): void
    {
        $raw = '0.8;2026-10-17/2026-11-02;2026-12-19/2027-01-04';
        $parsed = SlowPeriods::parse($raw);
        self::assertNotNull($parsed);
        self::assertSame($raw, SlowPeriods::normalise($parsed));

        // Saisie FR virgule → stockage canonique à point.
        $fr = SlowPeriods::parse('0,8;2026-10-17/2026-11-02');
        self::assertNotNull($fr);
        self::assertSame('0.8;2026-10-17/2026-11-02', SlowPeriods::normalise($fr));
    }

    /**
     * Détection de chevauchement plage creuse × fenêtre (note moteur de
     * double-comptage vacances) : bornes inclusives, facteur 1 = jamais
     * vrai, config nulle/vide = jamais vrai.
     */
    public function test_chevauche_fenetre_bornes_inclusives_et_config_neutre(): void
    {
        $slow = SlowPeriods::parse('0.8;2026-10-17/2026-11-02');

        // Chevauchements : partiel, borne début, borne fin, englobant.
        self::assertTrue(SlowPeriods::chevaucheFenetre($slow, '2026-10-20', '2026-10-31'), 'Fenêtre dans la plage.');
        self::assertTrue(SlowPeriods::chevaucheFenetre($slow, '2026-10-01', '2026-10-17'), 'Le premier jour de la plage compte.');
        self::assertTrue(SlowPeriods::chevaucheFenetre($slow, '2026-11-02', '2026-12-01'), 'Le dernier jour de la plage compte.');
        self::assertTrue(SlowPeriods::chevaucheFenetre($slow, '2026-10-01', '2026-12-01'), 'Fenêtre englobant la plage.');

        // Hors plage : fenêtre avant ou après.
        self::assertFalse(SlowPeriods::chevaucheFenetre($slow, '2026-09-01', '2026-10-16'));
        self::assertFalse(SlowPeriods::chevaucheFenetre($slow, '2026-11-03', '2026-12-01'));

        // Facteur 1 = aucune réduction → rien à signaler.
        $neutre = SlowPeriods::parse('1.0;2026-10-17/2026-11-02');
        self::assertNotNull($neutre);
        self::assertFalse(SlowPeriods::chevaucheFenetre($neutre, '2026-10-20', '2026-10-25'));

        // Config nulle ou sans plage.
        self::assertFalse(SlowPeriods::chevaucheFenetre(null, '2026-10-20', '2026-10-25'));
        self::assertFalse(SlowPeriods::chevaucheFenetre(SlowPeriods::parse(''), '2026-10-20', '2026-10-25'));
    }
}
