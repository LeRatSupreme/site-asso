<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\ForecastDataset;
use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\Hyper;
use PHPUnit\Framework\TestCase;

/**
 * Confiance FINALE conditionnée au backtest (honnêteté) : le plafond
 * « élevée » du modèle (≥ 14 jours ouverts ET dow vu ≥ RARE_DOW fois,
 * DemandModel::confidenceFor) n'est conservé QUE si le backtest est
 * validé — actif (n_points ≥ BACKTEST_START), modèle principal retenu
 * ET couverture ∈ [0,70 ; 0,90] — sinon plafond « moyenne ». « faible »
 * (occ < 3) et « insuffisant » restent inchangés et prioritaires.
 *
 * Contexte prod : backtest actif mais baseline_B2 retenue (WAPE 33 % vs
 * 94 %) et coverage 0,12 → la confiance affichée doit être « moyenne »,
 * plus jamais « élevée » sans backtest validé.
 */
final class ConfianceTest extends TestCase
{
    private const MOTIF = [70, 60, 65, 75, 90]; // N par jour : lun..ven

    /** (a) backtest insuffisant/inactif → max « moyenne ». */
    public function test_backtest_insuffisant_plafonne_a_moyenne(): void
    {
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', false, true, 0.85));
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', false, true, null));
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', false, false, null));
    }

    /** (b) use_main_model = false (baseline B2 retenue) → max « moyenne ». */
    public function test_baseline_retenue_plafonne_a_moyenne(): void
    {
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', true, false, 0.85));
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', true, false, 0.80));
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', true, false, null));
    }

    /** (c) coverage hors [0,70 ; 0,90] → « moyenne » (bornes exclues ici). */
    public function test_couverture_hors_plage_plafonne_a_moyenne(): void
    {
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', true, true, 0.62));
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', true, true, 0.69));
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', true, true, 0.91));
        self::assertSame('moyenne', ForecastEngine::confianceFinale('elevee', true, true, null));
    }

    /** (d) coverage 0,85 + main actif + volume suffisant (base 'elevee') → « elevee ». */
    public function test_backtest_valide_conserve_elevee(): void
    {
        self::assertSame('elevee', ForecastEngine::confianceFinale('elevee', true, true, 0.85));
        self::assertSame('elevee', ForecastEngine::confianceFinale('elevee', true, true, 0.70), 'Borne basse incluse.');
        self::assertSame('elevee', ForecastEngine::confianceFinale('elevee', true, true, 0.90), 'Borne haute incluse.');
    }

    /** (e) occ < 3 → « faible » prioritaire (jamais upgradé par le backtest). */
    public function test_faible_prioritaire(): void
    {
        self::assertSame('faible', ForecastEngine::confianceFinale('faible', true, true, 0.85));
        self::assertSame('faible', ForecastEngine::confianceFinale('faible', false, false, null));
    }

    /** « moyenne » reste « moyenne » quel que soit le backtest. */
    public function test_moyenne_inchangee(): void
    {
        self::assertSame('moyenne', ForecastEngine::confianceFinale('moyenne', true, true, 0.85));
        self::assertSame('moyenne', ForecastEngine::confianceFinale('moyenne', false, false, null));
    }

    /**
     * Niveau moteur (a) : 15 jours ouverts passés (volume ≥ 14, chaque
     * dow vu 3×) → plafond « elevee » du modèle, mais le backtest sur
     * ces 15 jours ne produit que 6 points (< BACKTEST_START = 10) →
     * confiance finale plafonnée à « moyenne ».
     */
    public function test_moteur_backtest_trop_court_plafonne_a_moyenne(): void
    {
        $days = self::serie(16); // 07/09 → 28/09, lun-ven
        $r = ForecastEngine::forecast(
            new ForecastDataset($days),
            new \DateTimeImmutable('2026-09-28 23:59', new \DateTimeZone(Hyper::TZ)), // lundi, vu 3×
            '23:59',
            ['seed' => 42, 'draws' => 200]
        );

        self::assertSame(15, $r['n_jours'], 'Aujourd\'hui (28/09) exclu du fit : 15 jours passés.');
        self::assertSame('moyenne', $r['confiance'], '6 points de backtest < BACKTEST_START → max « moyenne ».');
    }

    /**
     * Niveau moteur (cohérence) : avec backtest complet, la confiance
     * n'est « elevee » que si le payload montre un backtest validé
     * (modèle principal retenu ET coverage ∈ [0,70 ; 0,90]).
     */
    public function test_moteur_confiance_coherente_avec_backtest(): void
    {
        $days = self::serieBruitee(28);
        $r = ForecastEngine::forecast(
            new ForecastDataset($days),
            new \DateTimeImmutable('2026-10-15 23:59', new \DateTimeZone(Hyper::TZ)),
            '23:59',
            ['seed' => 42, 'draws' => 300, 'backtest_days' => $days]
        );

        self::assertSame(28, $r['n_jours']);
        $cov = $r['coverage_backtest'];
        $valide = $r['modele'] !== 'baseline_B2' && $cov !== null && (float) $cov >= 0.70 && (float) $cov <= 0.90;
        if (!$valide) {
            self::assertNotSame('elevee', $r['confiance'], 'Backtest non validé → confiance plafonnée à « moyenne ».');
        } else {
            self::assertSame('elevee', $r['confiance']);
        }
    }

    /** « insuffisant » inchangé : 4 jours ouverts, avant toute règle. */
    public function test_insuffisant_inchange(): void
    {
        $jours = array_slice(self::serie(28), 0, 4, true);
        $r = ForecastEngine::forecast(
            new ForecastDataset($jours),
            new \DateTimeImmutable('2026-09-11 12:00', new \DateTimeZone(Hyper::TZ)),
            '12:00',
            ['seed' => 42, 'draws' => 100]
        );

        self::assertSame('insuffisant', $r['confiance']);
    }

    /**
     * Série plate lun–ven, panier 1,25 (comme EngineTest).
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
     * Série bruitée (zigzag ×1,25 / ×0,8) — surdispersion réaliste.
     *
     * @return array<string,DayCurrent>
     */
    private static function serieBruitee(int $jours): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07'); // lundi
        $i = 0;
        while (count($days) < $jours) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $n = (int) round(self::MOTIF[$dow] * ($i % 2 === 0 ? 1.25 : 0.8));
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, self::horaire($n), array_fill(0, $n, 1.25));
                $i++;
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
}
