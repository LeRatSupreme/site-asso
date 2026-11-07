<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Clean;
use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\DemandModel;
use App\Core\Compta\Forecast\ForecastDataset;
use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\Hyper;
use PHPUnit\Framework\TestCase;

/**
 * FIX horizon 30 j — le « mois » est une fenêtre de 30 jours CALENDAIRES
 * (aujourd'hui restant + les jours ouverts des 29 jours suivants), PAS
 * 30 jours ouverts (l'ancienne boucle couvrait ≈ 42 dates : dump prod
 * 21/10 → 01/12, mois30 = 2 924 € au lieu de ~1 871 €). Dataset fixe
 * avec dow + plage creuse connue : span exactement 30 jours calendaires,
 * ensemble des jours ouverts inclus sans un de plus, comptes creuse, et
 * somme ≈ Σ prévisions (± tolérance Monte Carlo).
 */
final class HorizonMois30Test extends TestCase
{
    private const MOTIF = [70, 60, 65, 75, 90]; // N par jour : lun..ven

    /**
     * Série plate lun–ven (panier 1,25), 28 jours ouverts finissant
     * mercredi 14/10 — aujourd'hui de référence : lundi 19/10.
     *
     * @return array<string,DayCurrent>
     */
    private static function serie(int $jours): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07'); // lundi
        while (count($days) < $jours) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $n = self::MOTIF[$dow];
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, self::horaire($n), array_fill(0, $n, 1.25));
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }

    /**
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

    private static function paris(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone(Hyper::TZ));
    }

    public function test_span_30_jours_calendaires_comptes_et_somme(): void
    {
        $now  = self::paris('2026-10-19 23:59'); // lundi, ouvert habituel
        $opts = ['seed' => 42, 'draws' => 3000];
        // Plage creuse 26/10 → 08/11 : 10 jours ouverts dedans
        // (26-30/10 + 02-06/11), bornes incluses, facteur ×0,8.
        $slow = ['facteur' => 0.8, 'ranges' => [['start' => '2026-10-26', 'end' => '2026-11-08']]];

        $r = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), $now, '23:59', $opts + ['slow_periods' => $slow]);
        $m = $r['mois30'];

        // Span EXACTEMENT 30 jours calendaires : 19/10 → 17/11 (29 jours
        // d'écart). L'ancien comportement serait allé jusqu'au 29/11.
        self::assertSame('2026-10-19', (string) $m['debut']);
        self::assertSame('2026-11-17', (string) $m['fin']);
        self::assertSame(
            29,
            (int) (new \DateTimeImmutable((string) $m['debut']))->diff(new \DateTimeImmutable((string) $m['fin']))->format('%a'),
            'debut → fin : exactement 30 jours calendaires.'
        );

        // Ensemble des jours ouverts inclus, pas un de plus : aujourd'hui
        // (lundi) + lun-ven des 29 jours suivants = 22 occurrences
        // (20-23/10, 26-30/10, 02-06/11, 09-13/11, 16-17/11). L'ancienne
        // boucle aurait retourné 30 journées de vente.
        self::assertSame(22, (int) $m['jours_ouverts']);
        // Dont 10 en période creuse.
        self::assertSame(10, (int) $m['jours_creuse']);

        // Somme ≈ Σ des attendus du modèle (×0,8 sur les dates creuses) +
        // restant du jour ≈ 0 à 23h59 — tolérance Monte Carlo 3 % (les
        // 3 000 tirages et le choc de niveau commun Gamma(8, 1/8), de
        // moyenne exactement 1, convergent très en dessous).
        $days  = self::serie(28);
        $model = DemandModel::fit($days, Clean::atypicalWeights($days), Hyper::K_POOL);
        $somme = 0.0;
        for ($d = 1; $d <= 29; $d++) {
            $date = $now->modify('+' . $d . ' days');
            if ((int) $date->format('N') - 1 > 4) {
                continue; // sam/dim fermés
            }
            $key = $date->format('Y-m-d');
            $f   = ($key >= '2026-10-26' && $key <= '2026-11-08') ? 0.8 : 1.0;
            $somme += $model->expectedCa($date) * $f;
        }
        self::assertGreaterThan(0.0, $somme);
        self::assertEqualsWithDelta($somme, (float) $m['attendu'], $somme * 0.03, 'mois30.attendu ≈ Σ des jours ouverts des 30 jours calendaires (± MC).');
        self::assertGreaterThan(0.0, (float) $m['lo']);
        self::assertGreaterThan((float) $m['lo'], (float) $m['hi']);
    }

    /**
     * Sans plage creuse, l'attendu reste ≈ 22 jours ouverts × niveau :
     * l'ancien comportement (30 jours ouverts) aurait été ~36 % plus haut.
     */
    public function test_huit_jours_fermes_ne_sont_plus_comptes(): void
    {
        $now = self::paris('2026-10-19 23:59');
        $r = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), $now, '23:59', ['seed' => 42, 'draws' => 3000]);
        $m = $r['mois30'];

        self::assertSame('2026-10-19', (string) $m['debut']);
        self::assertSame('2026-11-17', (string) $m['fin']);
        self::assertSame(22, (int) $m['jours_ouverts'], 'Aucun samedi/dimanche dans la fenêtre.');
        self::assertSame(0, (int) $m['jours_creuse']);

        // Barème indépendant du modèle : occurrences futures par dow dans
        // la fenêtre (Mon×4, Tue×5, Wed×4, Thu×4, Fri×4) × motif × 1,25
        // = 1 500 transactions × 1,25 € = 1 875 € (± MC).
        $attendu = (4 * 70 + 5 * 60 + 4 * 65 + 4 * 75 + 4 * 90) * 1.25;
        self::assertEqualsWithDelta($attendu, (float) $m['attendu'], $attendu * 0.03, '22 jours ouverts × niveau, jours fermés exclus.');
    }

    /**
     * Aujourd'hui FERMÉ : la fenêtre reste 30 jours calendaires, aucune
     * occurrence du jour fermé dans les comptes.
     */
    public function test_aujourdhui_ferme_fenetre_inchangee(): void
    {
        $now = self::paris('2026-10-17 12:00'); // samedi, non ouvert
        $r = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), $now, '12:00', ['seed' => 42, 'draws' => 300]);
        $m = $r['mois30'];

        self::assertSame('2026-10-17', (string) $m['debut']);
        self::assertSame('2026-11-15', (string) $m['fin']);
        self::assertSame(20, (int) $m['jours_ouverts'], 'Samedi compté fermé : lun-ven du 19-23/10, 26-30/10, 02-06/11 et 09-13/11.');
        self::assertSame(0, (int) $m['jours_creuse']);
    }
}
