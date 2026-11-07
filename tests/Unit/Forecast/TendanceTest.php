<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Controllers\Admin\AdminAnalyticsController;
use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\DemandModel;
use App\Core\Compta\Forecast\ForecastDataset;
use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\Hyper;
use PHPUnit\Framework\TestCase;

/**
 * PORTE ANTI-BRUIT ET PLAFOND DE LA TENDANCE (Hyper::TREND_MIN_OPEN_DAYS,
 * Hyper::TREND_CAP_DAILY).
 *
 * Cas du propriétaire : avec ~9 jours ouverts, le SES apprenait une
 * « tendance » de −5,26 %/jour PUREMENT fictive (bruit alterné du CA),
 * qui amputait toutes les prévisions. Deux garde-fous :
 *  - < 30 jours ouverts : b forcé à 0 à chaque pas (SES niveau seul) ;
 *  - ≥ 30 jours ouverts : b clampé à ±1 %/jour (échelle log) après
 *    chaque mise à jour.
 *
 * Constructions des séries (documentées, déterministes) :
 *  - bruit alterné ±15 € à rotation hebdomadaire : chaque dow voit le
 *    même sous-ensemble de valeurs → les effets de dow absorbent le
 *    motif et la prévision reste ≈ 100 € ;
 *  - déclin/croissance FORTS appliqués par BLOCS SEMAINES ENTIÈRES :
 *    chaque dow voit le même multiset de facteurs → effets de dow nuls,
 *    la dynamique mesurée est bien celle de la tendance (un déclin de
 *    −8 %/jour strictement par jour rendrait les médianes de dow
 *    différentes de plusieurs % et polluerait le ratio inter-jours).
 */
final class TendanceTest extends TestCase
{
    /** Bruit de CA autour de 100 € (rotation hebdo : [115,85,100,110,90]). */
    private const BRUIT = [115, 85, 100, 110, 90];

    /** Facteurs hebdomadaires d'un DÉCLIN fort : ≈ −8 %/jour × 10 jours (30 j ouverts). */
    private const FACTEURS_DECLIN = [1.0, 1.0, 1.0, 1.0, 0.92 ** 5, 0.92 ** 10];

    /**
     * Idem sur 35 jours ouverts (7 semaines) : le test de sensibilité
     * retire un jour extrême et doit rester ≥ 30 jours ouverts pour que
     * la tendance reste ACTIVE (sinon la porte force b à 0).
     */
    private const FACTEURS_DECLIN_35J = [1.0, 1.0, 1.0, 1.0, 1.0, 0.92 ** 5, 0.92 ** 10];

    /** Facteurs hebdomadaires d'une CROISSANCE douce ≈ +1 %/jour (35 j ouverts). */
    private const FACTEURS_CROISSANCE = [1.01 ** 0, 1.01 ** 5, 1.01 ** 10, 1.01 ** 15, 1.01 ** 20, 1.01 ** 25, 1.01 ** 30];

    /** 20 jours ouverts de bruit : tendance DÉSACTIVÉE, prévision ≈ 100 €. */
    public function test_bruit_20_jours_tendance_nulle_et_prevision_stable(): void
    {
        $serie = self::serieParCABruit();
        $model = DemandModel::fit($serie, [], Hyper::K_POOL);

        self::assertSame(20, $model->openDaysCount(), '20 jours ouverts (< 30).');
        self::assertSame(0.0, $model->lastTrend, 'Bruit ±15 € : tendance forcée à 0 (SES niveau seul).');

        // Prévision au 10e jour ouvert après la fin (2 semaines, même dow) :
        // le niveau ≈ 100 € ± effets de dow absorbés → dans [90 ; 110].
        $fin = new \DateTimeImmutable('2026-10-02'); // vendredi, dernier jour de la série
        $cible = $fin->modify('+14 days'); // 10 jours ouverts plus tard (ven 16/10)
        $prev = $model->expectedCa($cible);

        self::assertGreaterThanOrEqual(90.0, $prev, 'Prévision à 10 jours : pas d\'effondrement par bruit (≥ 90 €).');
        self::assertLessThanOrEqual(110.0, $prev, 'Prévision à 10 jours : pas d\'emballement par bruit (≤ 110 €).');

        // Côté moteur : debug cohérent avec la porte (tendance inactive, % nul).
        $r = ForecastEngine::forecast(
            new ForecastDataset($serie),
            self::paris('2026-10-05 23:59'), // lundi suivant
            '23:59',
            ['seed' => 42, 'draws' => 200]
        );
        self::assertFalse((bool) $r['debug']['tendance_active'], 'Moteur : tendance_active = false sous 30 jours ouverts.');
        self::assertEqualsWithDelta(0.0, (float) $r['debug']['tendance_pct_jour'], 0.0, 'Moteur : % de tendance exactement 0 (b forcé à 0).');
    }

    /** Déclin fort sur ≥ 30 jours ouverts : b plafonné à ±1 %/jour. */
    public function test_plafond_declin_fort_borne_a_un_pour_cent_par_jour(): void
    {
        $model = DemandModel::fit(self::serieParFacteurs(self::FACTEURS_DECLIN, 30), [], Hyper::K_POOL);

        self::assertSame(30, $model->openDaysCount(), '30 jours ouverts (≥ 30) : tendance ACTIVE.');
        self::assertLessThanOrEqual(Hyper::TREND_CAP_DAILY, abs($model->lastTrend), '|b| ≤ 1 %/jour (plafond).');
        self::assertSame(-Hyper::TREND_CAP_DAILY, $model->lastTrend, 'Déclin fort : le plafond bas est la contrainte active (b == −0,01).');

        // Décroissement entre deux jours consécutifs prévus ≤ ~1 % : les
        // effets de dow sont nuls par construction (blocs semaines), le
        // ratio ne mesure que le terme de tendance amorti φ^h.
        $lundi = new \DateTimeImmutable('2026-10-19'); // premier jour ouvert après la série
        $mardi = new \DateTimeImmutable('2026-10-20');
        $caLundi = $model->expectedCa($lundi);
        $caMardi = $model->expectedCa($mardi);

        self::assertGreaterThan(0.0, $caLundi);
        self::assertGreaterThanOrEqual(0.99 - 1.0e-9, $caMardi / $caLundi, 'Jour+2 / jour+1 ≥ 0,99 : plus de déclin de −5 à −8 %/jour extrapolé.');
    }

    /** Retirer le jour le plus haut ou le plus bas ne fabrique pas une tendance. */
    public function test_sensibilite_retrait_des_extremes(): void
    {
        // a) Sous 30 jours ouverts : quel que soit l'extrême retiré, la
        // tendance reste 0 (la porte rend le fit insensible au bruit).
        $serie = self::serieParCABruit();
        [$cleHaute, $cleBasse] = self::clesExtremes($serie);

        $sansHaute = $serie;
        unset($sansHaute[$cleHaute]);
        $sansBasse = $serie;
        unset($sansBasse[$cleBasse]);

        self::assertSame(0.0, DemandModel::fit($sansHaute, [], Hyper::K_POOL)->lastTrend, 'Sans le jour le plus haut : tendance toujours 0.');
        self::assertSame(0.0, DemandModel::fit($sansBasse, [], Hyper::K_POOL)->lastTrend, 'Sans le jour le plus bas : tendance toujours 0.');

        // b) À ≥ 30 jours ouverts avec déclin (série de 35 j : le retrait
        // d'un jour garde le fit AU-DESSUS du seuil d'activation) :
        // retirer l'extrême HAUT ou l'extrême BAS ne change ni le SIGNE
        // de b (toujours négatif) ni le plafond.
        $declin = self::serieParFacteurs(self::FACTEURS_DECLIN_35J, 35);
        [$cleHauteDeclin, $cleBasseDeclin] = self::clesExtremes($declin);

        $declinSansHaute = $declin;
        unset($declinSansHaute[$cleHauteDeclin]);
        $declinSansBasse = $declin;
        unset($declinSansBasse[$cleBasseDeclin]);

        $model = DemandModel::fit($declinSansHaute, [], Hyper::K_POOL);
        self::assertSame(34, $model->openDaysCount(), '34 jours ouverts après retrait : tendance toujours active.');
        self::assertLessThan(0.0, $model->lastTrend, 'Déclin sans le jour le plus haut : le SIGNE de b reste négatif.');
        self::assertLessThanOrEqual(Hyper::TREND_CAP_DAILY, abs($model->lastTrend), 'Plafond toujours respecté.');

        $modelSansBasse = DemandModel::fit($declinSansBasse, [], Hyper::K_POOL);
        self::assertLessThan(0.0, $modelSansBasse->lastTrend, 'Déclin sans le jour le plus bas : le SIGNE de b reste négatif.');
        self::assertLessThanOrEqual(Hyper::TREND_CAP_DAILY, abs($modelSansBasse->lastTrend), 'Plafond toujours respecté.');
    }

    /** Croissance douce ≥ 30 jours : tendance active, plafonnée, prévision croissante. */
    public function test_croissance_douce_tendance_active_et_prevision_croissante(): void
    {
        $model = DemandModel::fit(self::serieParFacteurs(self::FACTEURS_CROISSANCE, 35), [], Hyper::K_POOL);

        self::assertSame(35, $model->openDaysCount());
        self::assertGreaterThan(0.0, $model->lastTrend, 'Croissance douce : tendance active et positive.');
        self::assertLessThanOrEqual(Hyper::TREND_CAP_DAILY, $model->lastTrend, '|b| ≤ 1 %/jour (plafond haut).');

        $lundi = new \DateTimeImmutable('2026-10-26'); // premier jour ouvert après la série (ven 23/10)
        $mardi = new \DateTimeImmutable('2026-10-27');
        self::assertGreaterThan($model->expectedCa($lundi), $model->expectedCa($mardi), 'La prévision croît avec l\'horizon (tendance positive amortie).');
    }

    /** Défaut de la fenêtre d'apprentissage : 30 j (whitelist 7/14/30). */
    public function test_defaut_fenetre_apprentissage_trente_jours(): void
    {
        self::assertSame(30, AdminAnalyticsController::fenetreApprentissage(null), 'Sans GET fw → 30.');
        self::assertSame(30, AdminAnalyticsController::fenetreApprentissage(''), 'GET fw vide → 30.');
        self::assertSame(30, AdminAnalyticsController::fenetreApprentissage('42'), 'GET fw hors whitelist → 30.');
        self::assertSame(30, AdminAnalyticsController::fenetreApprentissage('abc'), 'GET fw invalide → 30.');
        self::assertSame(7, AdminAnalyticsController::fenetreApprentissage('7'), 'fw=7 reste possible.');
        self::assertSame(14, AdminAnalyticsController::fenetreApprentissage('14'), 'fw=14 reste possible.');
        self::assertSame(30, AdminAnalyticsController::fenetreApprentissage('30'), 'fw=30 explicite.');
        self::assertSame(30, AdminAnalyticsController::fenetreApprentissage(' 30 '), 'Espaces ignorés.');
    }

    /**
     * 20 jours ouverts (lun 07/09 → ven 02/10), CA = BRUIT à rotation
     * hebdomadaire, panier constant 1,25 (N = CA/1,25).
     *
     * @return array<string,DayCurrent>
     */
    private static function serieParCABruit(): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07');
        $i = 0;
        while (count($days) < 20) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $semaine = intdiv($i, 5);
                $ca = self::BRUIT[($i % 5 + $semaine) % 5]; // rotation d'un cran par semaine
                $n = (int) round($ca / 1.25);
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, self::horaire($n), array_fill(0, $n, 1.25));
                $i++;
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }

    /**
     * $jours jours ouverts (lun 07/09 → …) au motif N=80 (CA 100 €),
     * multiplié par les facteurs hebdomadaires de FACTEURS_DECLIN(35J)/
     * FACTEURS_CROISSANCE par semaine entière (effets de dow identiques
     * par construction).
     *
     * @param list<float> $facteurs facteurs hebdomadaires (1 par semaine)
     * @param int         $jours    nb de jours ouverts (multiple de 5)
     *
     * @return array<string,DayCurrent>
     */
    private static function serieParFacteurs(array $facteurs, int $jours): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07');
        $i = 0;
        while (count($days) < $jours) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $n = (int) round(80 * $facteurs[intdiv($i, 5)]);
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, self::horaire($n), array_fill(0, $n, 1.25));
                $i++;
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }

    /**
     * Clés ('Y-m-d') des jours de CA (n × panier) le plus haut et le plus bas.
     *
     * @param array<string,DayCurrent> $days
     *
     * @return array{0:string,1:string}
     */
    private static function clesExtremes(array $days): array
    {
        $cleHaute = $cleBasse = null;
        $caHaute = -INF;
        $caBasse = INF;
        foreach ($days as $key => $day) {
            if ($day->ca > $caHaute) {
                $caHaute = $day->ca;
                $cleHaute = (string) $key;
            }
            if ($day->ca < $caBasse) {
                $caBasse = $day->ca;
                $cleBasse = (string) $key;
            }
        }

        return [(string) $cleHaute, (string) $cleBasse];
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

    private static function paris(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone(Hyper::TZ));
    }
}
