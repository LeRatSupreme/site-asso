<?php

declare(strict_types=1);

namespace App\Core\Compta\Forecast;

/**
 * Étape 3 du cœur de prévision : profil horaire cumulé des ventes,
 * ajusté sur les jours ouverts passés.
 *
 * Construction (hyperparamètres fixes de Hyper) :
 *  - comptes horaires globaux G(h) = Σ hourlyN, lissés de façon
 *    Dirichlet : g(h) = (G(h) + α) / (ΣG + 24α) (Stats::dirichletSmooth,
 *    somme = 1 ; α = alphaDir, défaut 1.0) ;
 *  - rétrécissement par jour de semaine (partial pooling d'intensité k) :
 *    c_j(h) = Σ hourlyN des jours du dow j, C_j = Σ_h c_j(h) ;
 *        s_j(h) = (c_j(h) + k × g(h) × C_j) / (C_j + k)
 *    (C_j = 0 → s_j(h) = g(h)) ; le profil est ensuite normalisé :
 *    Σ_h s_j(h) = 1 ;
 *  - cumul S_j(h) puis régression isotonique non-décroissante
 *    (Stats::isotonic), clamp [0, 1] et S(23) forcé à 1 (le déficit
 *    éventuel est porté par le dernier segment).
 *
 * Le dow inconnu (jamais observé) reçoit le profil uniforme :
 * S(h) = (h + 1) / 24.
 */
final class HourlyProfile
{
    /** @var array<int, list<float>> profil cumulé par dow observé (24 entrées, ~0 → 1). */
    private array $cumul;

    /** @param array<int, list<float>> $cumul */
    private function __construct(array $cumul)
    {
        $this->cumul = $cumul;
    }

    /**
     * Ajuste le profil horaire sur les jours ouverts passés.
     *
     * @param array<string, DayCurrent> $days     jours ouverts (hourlyN exploités)
     * @param float                     $k        intensité du rétrécissement par dow
     * @param float                     $alphaDir lissage de Dirichlet global (défaut 1.0)
     */
    public static function fit(array $days, float $k = Hyper::K_POOL, float $alphaDir = 1.0): self
    {
        $global = array_fill(0, 24, 0);
        $counts = [];
        foreach ($days as $day) {
            if (!$day instanceof DayCurrent) {
                continue;
            }
            $dow = (int) (new \DateTimeImmutable($day->date))->format('N') - 1;
            foreach ($day->hourlyN as $h => $count) {
                $h = (int) $h;
                if ($h < 0 || $h >= 24) {
                    continue;
                }
                $c = max(0, (int) $count);
                $global[$h] += $c;
                $counts[$dow][$h] = ($counts[$dow][$h] ?? 0) + $c;
            }
        }

        // Lissage de Dirichlet global (somme = 1, 24 cases).
        $g = Stats::dirichletSmooth($global, $alphaDir);
        if (count($g) !== 24) {
            $g = array_fill(0, 24, 1.0 / 24.0);
        }

        // Profil lissé + rétréci par dow, normalisé, puis cumulé isotonique.
        $cumul = [];
        for ($dow = 0; $dow < 7; $dow++) {
            $c = [];
            for ($h = 0; $h < 24; $h++) {
                $c[$h] = (float) ($counts[$dow][$h] ?? 0);
            }
            $total = array_sum($c);
            if ($total <= 0.0) {
                continue; // dow jamais observé → profil uniforme (cumulative())
            }
            $sum = 0.0;
            $s = [];
            for ($h = 0; $h < 24; $h++) {
                $s[$h] = ($c[$h] + $k * $g[$h] * $total) / ($total + $k);
                $sum += $s[$h];
            }
            if ($sum <= 0.0) {
                continue;
            }
            for ($h = 0; $h < 24; $h++) {
                $s[$h] /= $sum;
            }

            // Cumul, isotonie, clamp [0, 1], S(23) forcé à 1.
            $sArr = [];
            $run = 0.0;
            for ($h = 0; $h < 24; $h++) {
                $run += $s[$h];
                $sArr[$h] = $run;
            }
            $iso = Stats::isotonic($sArr);
            $out = [];
            foreach ($iso as $v) {
                $out[] = min(1.0, max(0.0, (float) $v));
            }
            $out[23] = 1.0; // dernier segment porteur du déficit éventuel
            $cumul[$dow] = $out;
        }

        return new self($cumul);
    }

    /**
     * Profil cumulé du dow : 24 entrées non-décroissantes de ~0 à 1.
     * Dow inconnu → profil uniforme (h + 1) / 24.
     *
     * @return list<float>
     */
    public function cumulative(int $dow): array
    {
        return $this->cumul[$dow] ?? self::uniformProfile();
    }

    /**
     * Projection « restant du jour » : combine le déjà-observé N(h)
     * (heures ≤ nowHour), l'attendu à cette heure expectedN × S_h et le
     * prior Gamma(a, a) — θ = (a + N(h)) / (a + expectedN × S_h) puis
     * n_final = N(h) + θ × expectedN × (1 − S_h).
     *
     * Dégradés : expectedN <= 0 → θ = 1.0, n_final = N(h), trop_tot =
     * true ; dow inconnu → profil uniforme (via cumulative()).
     *
     * @param array<int,int> $observedHourlyN [heure => nb transactions] du jour en cours
     *
     * @return array{theta: float, n_final: float, attendu_a_h: float, s_h: float, trop_tot: bool, n_obs: int}
     */
    public function project(float $expectedN, int $dow, array $observedHourlyN, int $nowHour, float $a = Hyper::A_PRIOR): array
    {
        $nowHour = min(23, max(0, $nowHour));
        $nObs = 0;
        for ($h = 0; $h <= $nowHour; $h++) {
            $nObs += max(0, (int) ($observedHourlyN[$h] ?? 0));
        }

        $sH = (float) ($this->cumulative($dow)[$nowHour] ?? 0.0);
        if ($expectedN <= 0.0) {
            return [
                'theta'       => 1.0,
                'n_final'     => (float) $nObs,
                'attendu_a_h' => 0.0,
                's_h'         => $sH,
                'trop_tot'    => true,
                'n_obs'       => $nObs,
            ];
        }

        $attendu = $expectedN * $sH;
        $denom   = $a + $attendu;
        $theta   = $denom > 0.0 ? ((float) $a + (float) $nObs) / $denom : 1.0;
        $nFinal  = (float) $nObs + $theta * $expectedN * (1.0 - $sH);

        return [
            'theta'       => $theta,
            'n_final'     => $nFinal,
            'attendu_a_h' => $attendu,
            's_h'         => $sH,
            'trop_tot'    => $sH < Hyper::S_MIN,
            'n_obs'       => $nObs,
        ];
    }

    /**
     * Profil uniforme : S(h) = (h + 1) / 24.
     *
     * @return list<float>
     */
    private static function uniformProfile(): array
    {
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = ((float) $h + 1.0) / 24.0;
        }

        return $out;
    }
}
