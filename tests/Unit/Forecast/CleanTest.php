<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Clean;
use App\Core\Compta\Forecast\DayCurrent;
use PHPUnit\Framework\TestCase;

/**
 * Nettoyage du dataset : jours de semaine ouverts habituels (couverture
 * calendaire, jour courant exclu), jours atypiques (z robuste / MAD) et
 * séparation passé / jour en cours.
 */
final class CleanTest extends TestCase
{
    /**
     * Grille de référence : 2026-09-07 est un LUNDI. Les semaines
     * lun–ven s'étendent de 07/09 à 02/10 ; le samedi ajouté est le
     * 03/10 et le « jour courant » le dimanche 04/10.
     */
    public function test_open_weekdays_samedi_exclu(): void
    {
        $days = self::semaines(4);
        $days['2026-10-03'] = new DayCurrent('2026-10-03', 100.0, 50, [], array_fill(0, 50, 2.0)); // 1 seul samedi

        $open = Clean::openWeekdays($days, '2026-10-04');

        self::assertSame([0, 1, 2, 3, 4], $open, 'Lun–ven inclus (4 occurrences ≥ ceil(0.5×4) = 2) ; samedi exclu (1 < 2).');
    }

    public function test_open_weekdays_jour_courant_non_compte(): void
    {
        $days = self::semaines(4);
        $days['2026-10-03'] = new DayCurrent('2026-10-03', 100.0, 50, [], array_fill(0, 50, 2.0));
        $days['2026-10-04'] = new DayCurrent('2026-10-04', 999.0, 999, [], []); // dimanche « aujourd'hui »

        self::assertSame([0, 1, 2, 3, 4], Clean::openWeekdays($days, '2026-10-04'), 'Le jour courant ne compte ni dans la couverture ni dans les effectifs.');

        // Même dataset, aujourd'hui = samedi : le samedi du jour courant
        // n'est pas compté → aucune occurrence passée → toujours exclu.
        self::assertSame([0, 1, 2, 3, 4], Clean::openWeekdays($days, '2026-10-03'));
    }

    public function test_open_weekdays_vide(): void
    {
        self::assertSame([], Clean::openWeekdays([], '2026-10-04'), 'Dataset vide → aucun dow.');
        self::assertSame([], Clean::openWeekdays(['2026-10-04' => new DayCurrent('2026-10-04', 10.0, 5)], '2026-10-04'), 'Aucun jour passé → aucun dow.');
    }

    public function test_atypical_weights_ca_10x(): void
    {
        // 21 jours à CA variant 90/100/110 (MAD non nulle) + un jour à
        // 1000 € (≈ 10× la médiane) → z robuste > Z_ATYPICAL → poids 0.3.
        $days = [];
        $atypiqueKey = null;
        $d = new \DateTimeImmutable('2026-09-07');
        for ($i = 0; $i < 21; $i++) {
            $key = $d->format('Y-m-d');
            $ca = [90.0, 100.0, 110.0][$i % 3];
            $days[$key] = new DayCurrent($key, $ca, 50, [], array_fill(0, 50, 2.0));
            $d = $d->modify('+1 day');
        }
        $atypiqueKey = '2026-09-20'; // remplace un jour « normal »
        $days[$atypiqueKey] = new DayCurrent($atypiqueKey, 1000.0, 500, [], array_fill(0, 500, 2.0));

        $weights = Clean::atypicalWeights($days);

        self::assertEqualsWithDelta(0.3, $weights[$atypiqueKey], 0.0, 'CA 10× la médiane → poids W_ATYPICAL.');
        self::assertEqualsWithDelta(1.0, $weights['2026-09-07'], 0.0, 'Jour normal → poids 1.0.');
        self::assertCount(21, $weights, 'Un poids par jour fourni.');
    }

    public function test_atypical_weights_mad_nulle_personne_atypique(): void
    {
        $days = self::semaines(4); // tous les CA identiques

        $weights = Clean::atypicalWeights($days);

        self::assertCount(20, $weights);
        foreach ($weights as $w) {
            self::assertEqualsWithDelta(1.0, $w, 0.0, 'MAD nulle → z = 0 → personne atypique.');
        }
    }

    public function test_split_past(): void
    {
        $days = [
            '2026-10-01' => new DayCurrent('2026-10-01', 100.0, 50),
            '2026-10-02' => new DayCurrent('2026-10-02', 110.0, 55),
            '2026-10-03' => new DayCurrent('2026-10-03', 120.0, 60),
        ];

        $split = Clean::splitPast($days, '2026-10-02');

        self::assertSame(['2026-10-01'], array_keys($split['past']), 'Strictement avant todayKey.');
        self::assertSame('2026-10-02', $split['today']?->date, 'Jour en cours isolé.');
        self::assertArrayNotHasKey('2026-10-03', $split['past'], 'Le futur fourni est ignoré.');

        $vide = Clean::splitPast($days, '2026-09-30');
        self::assertSame([], $vide['past']);
        self::assertNull($vide['today']);
    }

    /**
     * $n semaines lun–ven complètes à partir du lundi 07/09/2026,
     * CA 100 €, 50 transactions, panier 2.0.
     *
     * @return array<string,DayCurrent>
     */
    private static function semaines(int $n): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07');
        while (count($days) < 5 * $n) {
            if ((int) $d->format('N') - 1 <= 4) {
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, 100.0, 50, [], array_fill(0, 50, 2.0));
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }
}
