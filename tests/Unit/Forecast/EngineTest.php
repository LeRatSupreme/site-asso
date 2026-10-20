<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Clean;
use App\Core\Compta\Forecast\ForecastDataset;
use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\DayCurrent;
use PHPUnit\Framework\TestCase;

/**
 * Moteur complet (étape 9) sur séries synthétiques déterministes :
 *  - série plate de 28 jours ouverts (lun–ven × 4, panier 1.25) → somme
 *    des 5 prochains jours ouverts à ±5 % de 5 × 1.25 × 72 = 450 € ;
 *  - jour atypique ca ×10 → poids 0.3 et prévision stable (< 10 %) ;
 *  - 4 jours seulement → confiance « insuffisant », écart/intervalle null ;
 *  - nowHour = 23 → projection_fin_journee == reel_a_h ;
 *  - dataset vide → aucune exception, nulls cohérents.
 */
final class EngineTest extends TestCase
{
    private const MOTIF = [70, 60, 65, 75, 90]; // N par jour : lun..ven

    public function test_serie_plate_28_jours_somme_prochains_jours(): void
    {
        $r = ForecastEngine::forecast(
            new ForecastDataset(self::serie(28)),
            self::paris('2026-10-19 23:59'), // lundi suivant la série (qui se termine un mercredi)
            '23:59',
            ['seed' => 42, 'draws' => 1000]
        );

        self::assertSame(7, count($r['jours']));
        self::assertSame([true, true, true, true, false, false, true], array_column($r['jours'], 'is_open'), 'Mar..Ven + lun suivant ouverts ; sam/dim fermés.');

        $somme = 0.0;
        foreach ($r['jours'] as $j) {
            if ($j['is_open']) {
                $somme += $j['ca'];
            }
        }
        self::assertEqualsWithDelta(450.0, $somme, 450.0 * 0.05, 'Série plate (72 transactions/j en moyenne) → 5 × 1.25 × 72.');

        self::assertNotSame('insuffisant', $r['confiance']);
        self::assertSame(28, $r['n_jours']);
        self::assertNotNull($r['prevision_jour']);
        self::assertNotNull($r['intervalle_80']);
        self::assertContains($r['modele'], ['negbin_dow_damped', 'baseline_B2']);
        self::assertNotNull($r['semaine']);
        self::assertSame(4, $r['semaine']['jours_restants'], 'Lundi 19/10 hors dataset (série finie le 14) : restent mar..ven.');
        self::assertGreaterThan(0.0, $r['mois30']['attendu']);
        self::assertCount(7, $r['jours_semaine']);
        self::assertSame('Lun', $r['jours_semaine'][0]['label']);
        self::assertSame('Dim', $r['jours_semaine'][6]['label']);
        self::assertNotNull($r['meilleur_jour'], 'Vendredi = meilleur jour (90 transactions).');
        self::assertSame(4, $r['meilleur_jour']['dow']);
    }

    public function test_jour_atypique_poids_0_3_prevision_stable(): void
    {
        $base = self::serie(28);
        $variante = self::serie(28);
        $atypique = $variante['2026-09-16']; // mercredi semaine 2
        $variante['2026-09-16'] = new DayCurrent(
            '2026-09-16',
            $atypique->ca * 10.0,
            $atypique->n * 10,
            array_map(static fn (int $v): int => $v * 10, $atypique->hourlyN),
            array_merge($atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets, $atypique->baskets)
        );

        $poids = Clean::atypicalWeights($variante);
        self::assertEqualsWithDelta(0.3, $poids['2026-09-16'], 0.0, 'Jour atypique ca ×10 → poids W_ATYPICAL.');
        self::assertEqualsWithDelta(1.0, $poids['2026-09-09'], 0.0, 'Jour normal → poids 1.0.');

        $rBase = ForecastEngine::forecast(new ForecastDataset($base), self::paris('2026-10-19 23:59'), '23:59', ['seed' => 42, 'draws' => 1000]);
        $rVar = ForecastEngine::forecast(new ForecastDataset($variante), self::paris('2026-10-19 23:59'), '23:59', ['seed' => 42, 'draws' => 1000]);

        self::assertContains('2026-09-16', $rVar['anomalies']['jours_atypiques']);

        $sommeBase = 0.0;
        $sommeVar = 0.0;
        foreach ($rBase['jours'] as $j) {
            if ($j['is_open']) {
                $sommeBase += $j['ca'];
            }
        }
        foreach ($rVar['jours'] as $j) {
            if ($j['is_open']) {
                $sommeVar += $j['ca'];
            }
        }
        self::assertGreaterThan(0.0, $sommeBase);
        self::assertLessThan(0.10, abs($sommeVar - $sommeBase) / $sommeBase, 'Le poids 0.3 borne l\'impact du jour atypique sur la prévision.');
    }

    public function test_quatre_jours_confiance_insuffisante(): void
    {
        $jours = array_slice(self::serie(28), 0, 4, true); // lun..ven semaine 1 : 4 premiers jours
        $r = ForecastEngine::forecast(
            new ForecastDataset($jours),
            self::paris('2026-09-11 12:00'), // vendredi, non ouvert habituel
            '12:00',
            ['seed' => 42, 'draws' => 300]
        );

        self::assertSame('insuffisant', $r['confiance']);
        self::assertNull($r['ecart_vs_attendu']);
        self::assertNull($r['dans_intervalle']);
        self::assertNull($r['prevision_jour'], 'Vendredi non ouvert habituel → pas de prévision.');
        self::assertTrue($r['anomalies']['donnees_insuffisantes']);
        self::assertContains('Données insuffisantes (< 7 jours ouverts) : aucun écart en pourcentage n\'est affiché.', $r['notes']);
    }

    public function test_projection_fin_journee_egal_reel_a_23h(): void
    {
        $r = ForecastEngine::forecast(
            new ForecastDataset(self::serie(28)),
            self::paris('2026-10-02 23:59'), // dernier vendredi du dataset (aujourd'hui)
            '23:59',
            ['seed' => 42, 'draws' => 300]
        );

        self::assertSame(23, $r['horaire']['now_hour']);
        self::assertEqualsWithDelta($r['horaire']['reel_a_h'], $r['projection_fin_journee'], 0.01, 'À 23h tout le projété est du réel.');
        self::assertEqualsWithDelta(112.5, $r['horaire']['reel_a_h'], 0.01, '90 transactions × panier 1.25.');
        self::assertNotNull($r['hier'], 'Hier (jeudi) est dans le dataset.');
        self::assertEqualsWithDelta(75.0 * 1.25, $r['hier']['reel'], 0.0001, 'Jeudi : 75 × 1.25.');
        self::assertEqualsWithDelta(112.5, $r['projection_fin_journee'], 0.01);
    }

    public function test_evenements_additionnes_sans_incertitude(): void
    {
        $opts = ['seed' => 42, 'draws' => 500];
        $rSans = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts);
        $rAvec = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts + ['events' => ['2026-10-20' => 50.0]]);

        $caSans = null;
        $caAvec = null;
        foreach ($rSans['jours'] as $j) {
            if ($j['date'] === '2026-10-20') {
                $caSans = $j['ca'];
            }
        }
        foreach ($rAvec['jours'] as $j) {
            if ($j['date'] === '2026-10-20') {
                $caAvec = $j['ca'];
            }
        }
        self::assertNotNull($caSans);
        self::assertEqualsWithDelta(50.0, $caAvec - $caSans, 1.0e-9, 'Événement ajouté comme constante.');
        self::assertEqualsWithDelta(50.0, $rAvec['jours'][0]['evenement'], 0.0);
        self::assertContains('1 événement(s) planifié(s) ajouté(s) aux prévisions.', $rAvec['notes']);
        self::assertNotContains('1 événement(s) planifié(s) ajouté(s) aux prévisions.', $rSans['notes']);
    }

    public function test_determinisme_meme_graine(): void
    {
        $opts = ['seed' => 7, 'draws' => 200];
        $a = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts);
        $b = ForecastEngine::forecast(new ForecastDataset(self::serie(28)), self::paris('2026-10-19 23:59'), '23:59', $opts);

        self::assertSame($a, $b, 'Même graine → sortie moteur strictement identique.');
    }

    public function test_dataset_vide_sans_erreur(): void
    {
        $r = ForecastEngine::forecast(new ForecastDataset([]), self::paris('2026-10-05 12:00'), '12:00', ['seed' => 1, 'draws' => 100]);

        self::assertNull($r['prevision_jour']);
        self::assertSame('insuffisant', $r['confiance']);
        self::assertNull($r['ecart_vs_attendu']);
        self::assertNull($r['dans_intervalle']);
        self::assertNull($r['meilleur_jour']);
        self::assertSame(0, $r['n_jours']);
        self::assertCount(7, $r['jours_semaine']);
        self::assertCount(7, $r['jours']);
        self::assertTrue($r['anomalies']['donnees_insuffisantes']);
    }

    /**
     * Série synthétique plate : $jours jours ouverts consécutifs lun–ven,
     * N selon le motif [70,60,65,75,90], panier constant 1.25, horaire 8h→17h.
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
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Paris'));
    }
}
