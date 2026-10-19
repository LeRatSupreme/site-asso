<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Prévision de CA pure (sans accès DB), consommée par le dashboard
 * Analytics (carte « Prévisions » + badges du jour / d'hier).
 *
 * Principe : moyenne du CA par jour de semaine sur les jours ÉCOULÉS
 * (strictement avant aujourd'hui, heure de Paris) — les jours sans
 * vente comptent pour 0 (l'appelant fournit un CA quotidien
 * zéro-rempli). Un jour de semaine jamais vu retombe sur la moyenne
 * globale. La prévision « pro-rata » du jour en cours répartit la
 * prévision pleine selon les parts horaires réelles du CA.
 */
final class SalesForecast
{
    /** Fuseau de référence : l'asso opère à Paris. */
    public const TZ = 'Europe/Paris';

    /** Abréviations françaises des jours (index 0 = lundi, comme WEEKDAY MySQL). */
    private const DAY_ABBR = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

    /**
     * Prévision sur les $horizon jours à venir (à partir de DEMAIN).
     *
     * @param array<string,float> $dailyCa CA quotidien 'Y-m-d' => ca (zéro-rempli par l'appelant)
     * @return array{
     *     days:list<array{date:string,label:string,value:?float}>,
     *     today:array{forecast_full:?float,forecast_so_far:?float,actual:float,delta_pct:?float},
     *     yesterday:array{forecast_full:?float,actual:float,delta_pct:?float},
     *     reliable:bool,elapsed_days:int,global_avg:?float
     * }
     */
    public static function forecast(array $dailyCa, \DateTimeImmutable $today, int $horizon = 7): array
    {
        $horizon  = max(0, $horizon);
        $today    = $today->setTimezone(new \DateTimeZone(self::TZ));
        $todayKey = $today->format('Y-m-d');

        // Jours écoulés = strictement avant aujourd'hui (comparaison de dates Y-m-d).
        $sums    = array_fill(0, 7, 0.0);
        $counts  = array_fill(0, 7, 0);
        $elapsed = 0;
        $total   = 0.0;

        foreach ($dailyCa as $day => $ca) {
            $day = (string) $day;
            if ($day === '' || $day >= $todayKey) {
                continue;
            }
            try {
                $wd = (int) (new \DateTimeImmutable($day))->format('N') - 1;
            } catch (\Throwable) {
                continue;
            }
            if ($wd < 0 || $wd > 6) {
                continue;
            }
            $sums[$wd] += (float) $ca;
            $counts[$wd]++;
            $elapsed++;
            $total += (float) $ca;
        }

        $globalAvg = $elapsed > 0 ? $total / $elapsed : null;

        // Prévision d'un jour : moyenne de son jour de semaine si vu au
        // moins une fois, sinon moyenne globale (null sans historique).
        $forecastFor = static function (\DateTimeImmutable $date) use ($sums, $counts, $globalAvg): ?float {
            $wd = (int) $date->format('N') - 1;

            return $counts[$wd] >= 1 ? $sums[$wd] / $counts[$wd] : $globalAvg;
        };

        $days = [];
        for ($i = 1; $i <= $horizon; $i++) {
            $d = $today->modify('+' . $i . ' day');
            $days[] = [
                'date'  => $d->format('Y-m-d'),
                'label' => self::dayLabel($d),
                'value' => $forecastFor($d),
            ];
        }

        $todayActual = (float) ($dailyCa[$todayKey] ?? 0.0);
        $todayFull   = $forecastFor($today);
        $todaySoFar  = $todayFull === null ? null : self::proRatedForecast($todayFull, [], $today);

        $yesterday    = $today->modify('-1 day');
        $yActual      = (float) ($dailyCa[$yesterday->format('Y-m-d')] ?? 0.0);
        $yFull        = $forecastFor($yesterday);

        return [
            'days'         => $days,
            'today'        => [
                'forecast_full'   => $todayFull,
                'forecast_so_far' => $todaySoFar,
                'actual'          => $todayActual,
                'delta_pct'       => self::deltaPct($todayActual, $todaySoFar),
            ],
            'yesterday'    => [
                'forecast_full' => $yFull,
                'actual'        => $yActual,
                'delta_pct'     => self::deltaPct($yActual, $yFull),
            ],
            'reliable'     => $elapsed >= 7,
            'elapsed_days' => $elapsed,
            'global_avg'   => $globalAvg,
        ];
    }

    /**
     * Prévision pro-rata : la part de journée déjà écoulée selon la
     * répartition horaire réelle du CA (parts 0..1 par heure). Parts
     * vides ou nulles → répartition linéaire ((heure + 1) / 24).
     *
     * @param array<int,float> $hourlyShares [heure => part 0..1]
     */
    public static function proRatedForecast(float $full, array $hourlyShares, \DateTimeImmutable $now): float
    {
        $hour  = (int) $now->format('G');
        $share = 0.0;

        foreach ($hourlyShares as $h => $s) {
            if ((int) $h < $hour) {
                $share += (float) $s;
            }
        }

        if ($share <= 0) {
            $share = ($hour + 1) / 24;
        }

        return $full * $share;
    }

    /**
     * Libellé court français d'un jour : « Ven 16/10 ».
     */
    public static function dayLabel(\DateTimeImmutable $date): string
    {
        return self::DAY_ABBR[(int) $date->format('N') - 1] . ' ' . $date->format('j/n');
    }

    /**
     * Écart en % réel vs prévision (null si prévision absente ou nulle).
     */
    private static function deltaPct(float $actual, ?float $forecast): ?float
    {
        if ($forecast === null || $forecast <= 0) {
            return null;
        }

        return round((($actual - $forecast) / $forecast) * 100, 1);
    }
}
