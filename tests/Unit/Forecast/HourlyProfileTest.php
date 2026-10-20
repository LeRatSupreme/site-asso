<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\HourlyProfile;
use App\Core\Compta\Forecast\Hyper;
use PHPUnit\Framework\TestCase;

/**
 * Profil horaire cumulé : monotonie, S(23) = 1, dow inconnu uniforme ;
 * projection restant-du-jour : cas de fermeture exact, « trop tôt »,
 * valeur manuelle de θ et dégradé expectedN ≤ 0.
 */
final class HourlyProfileTest extends TestCase
{
    public function test_profils_cumules_valides(): void
    {
        $profile = HourlyProfile::fit(self::serie(2), Hyper::K_POOL);

        foreach ([0, 1, 2, 3, 4] as $dow) {
            $s = $profile->cumulative($dow);
            self::assertCount(24, $s, '24 entrées.');
            self::assertEqualsWithDelta(1.0, $s[23], 1.0e-12, 'S(23) = 1.');
            self::assertLessThan(0.05, $s[0], 'S(0) petit (ventes 8h→17h).');
            for ($h = 1; $h < 24; $h++) {
                self::assertGreaterThanOrEqual($s[$h - 1], $s[$h], 'Cumul non-décroissant.');
            }
        }
    }

    public function test_dow_inconnu_profil_uniforme(): void
    {
        $profile = HourlyProfile::fit(self::serie(2), Hyper::K_POOL);

        $s = $profile->cumulative(5); // samedi jamais observé
        self::assertCount(24, $s);
        for ($h = 0; $h < 24; $h++) {
            self::assertEqualsWithDelta(($h + 1) / 24.0, $s[$h], 1.0e-12, 'Dow inconnu → S(h) = (h+1)/24.');
        }
        self::assertCount(24, $profile->cumulative(99), 'Dow hors bornes → uniforme aussi.');
    }

    public function test_project_fermeture_exacte(): void
    {
        $profile = HourlyProfile::fit(self::serie(2), Hyper::K_POOL);
        $model = ['n' => 75.0];

        $p = $profile->project(
            $model['n'],
            3, // jeudi, dow connu
            self::horaire(75), // journée complète observée
            23,
            Hyper::A_PRIOR
        );

        self::assertSame(['theta', 'n_final', 'attendu_a_h', 's_h', 'trop_tot', 'n_obs'], array_keys($p), 'Forme exacte du retour.');
        self::assertSame(75, $p['n_obs']);
        self::assertEqualsWithDelta(75.0, $p['n_final'], 0.0, 'À la fermeture (S = 1) : n_final = N(h) exactement.');
        self::assertFalse($p['trop_tot'], 'S(23) = 1 ≥ S_MIN.');
        self::assertEqualsWithDelta(1.0, $p['s_h'], 1.0e-12);
    }

    public function test_project_trop_tot(): void
    {
        $profile = HourlyProfile::fit(self::serie(2), Hyper::K_POOL);

        // Ventes 8h→17h : à 7h rien n'est encore écoulé (S ≈ 0 < 0.10) :
        // le reste de journée est projeté presque en entier.
        $p = $profile->project(62.0, 2, [], 7, Hyper::A_PRIOR);

        self::assertTrue($p['trop_tot'], 'S_h < 0.10 → trop_tot.');
        self::assertSame(0, $p['n_obs']);
        self::assertEqualsWithDelta($p['theta'] * 62.0 * (1.0 - $p['s_h']), $p['n_final'], 1.0e-12, 'n_final = θ × expectedN × (1 − S_h) avec N(h) = 0.');
        self::assertGreaterThan(0.0, $p['n_final']);
    }

    public function test_project_theta_manuel(): void
    {
        $profile = HourlyProfile::fit(self::serie(2), Hyper::K_POOL);

        // Dow inconnu (6) → S(h) = (h+1)/24 ; nowHour = 11 → S_h = 0.5.
        // θ = (8 + 0) / (8 + 62 × 0.5) = 8/39 ≈ 0.2051 ;
        // n_final = 0 + θ × 62 × 0.5 ≈ 6.36.
        $p = $profile->project(62.0, 6, [], 11, 8.0);

        self::assertEqualsWithDelta(8.0 / 39.0, $p['theta'], 0.0001);
        self::assertEqualsWithDelta((8.0 / 39.0) * 62.0 * 0.5, $p['n_final'], 0.01);
        self::assertEqualsWithDelta(31.0, $p['attendu_a_h'], 0.0001, 'attendu_a_h = expectedN × S_h.');
        self::assertEqualsWithDelta(0.5, $p['s_h'], 1.0e-12);
        self::assertFalse($p['trop_tot'], 'S_h = 0.5 ≥ S_MIN.');
        self::assertSame(0, $p['n_obs']);
    }

    public function test_project_expected_n_nul(): void
    {
        $profile = HourlyProfile::fit(self::serie(2), Hyper::K_POOL);

        $p = $profile->project(0.0, 0, self::horaire(30), 12, Hyper::A_PRIOR);

        self::assertEqualsWithDelta(1.0, $p['theta'], 0.0, 'Dégradé : θ = 1.0.');
        self::assertEqualsWithDelta(15.0, $p['n_final'], 0.0, 'Dégradé : n_final = N(h) (heures 8..12 → 5 h × 3).');
        self::assertTrue($p['trop_tot'], 'Dégradé : trop_tot.');
        self::assertSame(15, $p['n_obs']);

        $neg = $profile->project(-5.0, 0, [], 12, Hyper::A_PRIOR);
        self::assertTrue($neg['trop_tot']);
        self::assertEqualsWithDelta(0.0, $neg['n_final'], 0.0);
    }

    public function test_fit_sans_donnees(): void
    {
        $profile = HourlyProfile::fit([], Hyper::K_POOL);

        $s = $profile->cumulative(0);
        self::assertCount(24, $s, 'Dataset vide → profil uniforme sur tout dow.');
        self::assertEqualsWithDelta(1.0 / 24.0, $s[0], 1.0e-12);
        self::assertEqualsWithDelta(1.0, $s[23], 1.0e-12);
    }

    /**
     * $n semaines lun–ven : N = [70,60,65,75,90] selon le dow, panier
     * 1.25, ventes réparties 8h→17h.
     *
     * @return array<string,DayCurrent>
     */
    private static function serie(int $semaines): array
    {
        $motif = [70, 60, 65, 75, 90];
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07'); // lundi
        while (count($days) < 5 * $semaines) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $n = $motif[$dow];
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, self::horaire($n), array_fill(0, $n, 1.25));
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
