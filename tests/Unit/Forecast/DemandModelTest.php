<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\Clean;
use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\DemandModel;
use App\Core\Compta\Forecast\Hyper;
use PHPUnit\Framework\TestCase;

/**
 * Modèle de demande N × P : motif hebdomadaire sans bruit (précision
 * < 1 %), dow fermé → 0 sans perturber les autres dows, tendance
 * amortie (croissance +50 % non explosive), amortissement d'un jour
 * atypique, rétrécissement du panier, insuffisance et confiance.
 */
final class DemandModelTest extends TestCase
{
    /** Motif de mission : N par jour, lun..ven. */
    private const MOTIF = [70, 60, 65, 75, 90];

    public function test_motif_hebdomadaire_sans_bruit_prevision_precise(): void
    {
        $model = DemandModel::fit(self::serie(4), [], Hyper::K_POOL);

        // La série se termine le vendredi 02/10 ; le « dow suivant » est
        // le lundi 05/10 (h = 1).
        $lundi = self::paris('2026-10-05 12:00');
        $prev = $model->expectedN($lundi);

        self::assertEqualsWithDelta(70.0, $prev, 70.0 * 0.01, 'ExpectedN(lundi suivant) à moins de 1 % du motif (70).');
        self::assertEqualsWithDelta(1.25, $model->expectedP($lundi), 0.0001, 'Panier constant → panier attendu exact.');
        self::assertEqualsWithDelta(70.0 * 1.25, $model->expectedCa($lundi), 70.0 * 1.25 * 0.01, 'ExpectedCa = ExpectedN × ExpectedP.');
        self::assertSame([0, 1, 2, 3, 4], $model->openWeekdays);
        self::assertFalse($model->isInsufficient(), '20 jours ouverts ≥ 7.');
        self::assertSame(20, $model->openDaysCount());
        self::assertSame('elevee', $model->confidenceFor($lundi), '≥ 14 jours et lundi vu 4 fois.');
    }

    public function test_samedi_fermes_zero_et_autres_dows_stables(): void
    {
        // Dataset A : 4 semaines lun–SAM (samedi ouvert à 72, proche de
        // la moyenne du motif) ; dataset B : mêmes semaines SANS samedis
        // (fermés). Fermer le samedi doit mettre sa prévision à 0 sans
        // déplacer les prévisions des autres jours.
        $avecSamedis = self::serie(4, true);
        $sansSamedis = self::serie(4, false);

        $mA = DemandModel::fit($avecSamedis, [], Hyper::K_POOL);
        $mB = DemandModel::fit($sansSamedis, [], Hyper::K_POOL);

        self::assertSame([0, 1, 2, 3, 4, 5], $mA->openWeekdays, 'Samedi vu 4 fois → ouvert habituel.');
        self::assertSame([0, 1, 2, 3, 4], $mB->openWeekdays, 'Samedis fermés → exclu.');

        self::assertSame(0.0, $mB->expectedN(self::paris('2026-10-03 12:00')), 'Samedi fermé → expectedN exactement 0.');
        self::assertGreaterThan(0.0, $mA->expectedN(self::paris('2026-10-10 12:00')), 'Samedi ouvert → prévision positive.');

        // Les autres dows sont inchangés (tolérance 5 % : le samedi à 72
        // est proche de la moyenne, les effets par dow ne se déplacent
        // presque pas).
        foreach (['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $i => $key) {
            $nA = $mA->expectedN(self::paris($key . ' 12:00'));
            $nB = $mB->expectedN(self::paris($key . ' 12:00'));
            self::assertGreaterThan(0.0, $nA);
            self::assertGreaterThan(0.0, $nB);
            self::assertEqualsWithDelta($nB, $nA, $nB * 0.05, sprintf('Dow %d : fermer le samedi déplace la prévision de moins de 5%%.', $i));
        }
    }

    public function test_croissance_amortie_sans_explosion(): void
    {
        // Croissance linéaire sur 6 SEMAINES (30 jours ouverts) : la
        // tendance est DÉSACTIVÉE sous Hyper::TREND_MIN_OPEN_DAYS et
        // PLAFONNÉE à ±1 %/jour au-delà — l'ancienne version à 4 semaines
        // (20 jours) ne teste plus la tendance (porte anti-bruit) et son
        // assertion « au-dessus de la moyenne de la dernière semaine »
        // dépendait d'une tendance NON plafonnée. Motif multiplié par
        // 1, 7/6, 4/3, 1.5, 1.6, 1.7.
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07');
        $mult = [1.0, 7.0 / 6.0, 4.0 / 3.0, 1.5, 1.6, 1.7];
        while (count($days) < 30) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $semaine = intdiv(count($days), 5);
                $n = (int) round(self::MOTIF[$dow] * $mult[$semaine]);
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, self::horaire($n), array_fill(0, $n, 1.25));
            }
            $d = $d->modify('+1 day');
        }
        $model = DemandModel::fit($days, [], Hyper::K_POOL);

        $derniereSemaine = [119.0, 102.0, 110.5, 127.5, 153.0];
        $maxHist = 153.0;

        self::assertGreaterThan(0.0, $model->lastTrend, '30 jours ouverts : tendance active et positive.');
        self::assertLessThanOrEqual(Hyper::TREND_CAP_DAILY, $model->lastTrend, 'Tendance plafonnée à ±1 %/jour.');

        $demain = $model->expectedN(self::paris('2026-10-19 12:00')); // lundi suivant (h = 1)

        // La projection suit la croissance : elle reste dans la plage de
        // la dernière semaine (le plafond limite le RYTHME, pas le niveau
        // appris), et l'explosion est bornée par construction (cumul de
        // tendance ≤ b/(1−φ) = +10 %) : < 1.15 × max historique.
        self::assertGreaterThanOrEqual(min($derniereSemaine), $demain, 'La projection suit le niveau de la dernière semaine.');
        self::assertLessThan(1.15 * $maxHist, $demain, 'La tendance plafonnée n\'explose pas (< 1.15 × max historique).');
    }

    public function test_jour_atypique_amorti(): void
    {
        $base = self::serie(4);
        $variante = self::serie(4);
        $atypique = $variante['2026-09-17']; // jeudi de la semaine 2
        $variante['2026-09-17'] = new DayCurrent('2026-09-17', $atypique->ca * 10.0, $atypique->n * 10, self::horaire($atypique->n * 10), array_fill(0, $atypique->n * 10, 1.25));

        $poids = Clean::atypicalWeights($variante);
        self::assertEqualsWithDelta(Hyper::W_ATYPICAL, $poids['2026-09-17'], 0.0, 'CA ×10 → poids 0.3.');

        $mBase = DemandModel::fit($base, [], Hyper::K_POOL);
        $mVar = DemandModel::fit($variante, $poids, Hyper::K_POOL);

        // Le jour atypique est à 13 jours de la fin : son innovation
        // (α×w = 0.09) est largement amortie à l'horizon de la prévision.
        foreach (['2026-10-05', '2026-10-07'] as $key) {
            $nBase = $mBase->expectedN(self::paris($key . ' 12:00'));
            $nVar = $mVar->expectedN(self::paris($key . ' 12:00'));
            self::assertLessThan(0.10, abs($nVar - $nBase) / $nBase, sprintf('Le poids 0.3 borne l\'impact du jour atypique sur %s (< 10 %%).', $key));
        }
    }

    public function test_expected_p_retrécissement_vers_global(): void
    {
        // 4 semaines lun–jeu à panier 1.0 (50 transactions) + UN vendredi
        // vu une seule fois, panier 2.0. Pool : (1×2.0 + 2×1.0) / 3 = 1.3333.
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07');
        $vendrediVu = false;
        while (count($days) < 17) {
            $dow = (int) $d->format('N') - 1;
            $key = $d->format('Y-m-d');
            if ($dow === 4 && !$vendrediVu) {
                $days[$key] = new DayCurrent($key, 2.0, 1, self::horaire(1), [2.0]);
                $vendrediVu = true;
            } elseif ($dow <= 3) {
                $days[$key] = new DayCurrent($key, 50.0, 50, self::horaire(50), array_fill(0, 50, 1.0));
            }
            $d = $d->modify('+1 day');
        }
        $model = DemandModel::fit($days, [], Hyper::K_POOL);

        self::assertEqualsWithDelta(1.0, $model->globalP, 0.0001, 'Panier global tronqué = 1.0 (le 2.0 isolé est tronqué).');
        self::assertEqualsWithDelta(4.0 / 3.0, $model->expectedP(self::paris('2026-09-18 12:00')), 0.001, 'Dow vu 1 fois : pool(2.0, 1, 1.0, k=2) = 4/3.');
        self::assertEqualsWithDelta(1.0, $model->expectedP(self::paris('2026-09-21 12:00')), 0.0001, 'Lundi : pool(1.0, 200, 1.0, k=2) = 1.0.');
    }

    public function test_insuffisance_et_confiances(): void
    {
        // 4 jours seulement → insuffisant, dow du lundi jamais vu → faible.
        $court = array_slice(self::serie(4), 0, 4, true);
        $mCourt = DemandModel::fit($court, [], Hyper::K_POOL);
        self::assertTrue($mCourt->isInsufficient(), '4 jours < 7.');
        self::assertSame(4, $mCourt->openDaysCount());
        self::assertSame('faible', $mCourt->confidenceFor(self::paris('2026-09-11 12:00')), 'Vendredi jamais vu → faible.');

        // 13 jours (2 semaines + lun–mer) : lundi vu 3 fois mais < 14 jours → moyenne.
        $treize = [];
        $d = new \DateTimeImmutable('2026-09-07');
        while (count($treize) < 13) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $key = $d->format('Y-m-d');
                $treize[$key] = new DayCurrent($key, 100.0, 50, self::horaire(50), array_fill(0, 50, 2.0));
            }
            $d = $d->modify('+1 day');
        }
        $mTreize = DemandModel::fit($treize, [], Hyper::K_POOL);
        self::assertSame(13, $mTreize->openDaysCount());
        self::assertFalse($mTreize->isInsufficient());
        self::assertSame('moyenne', $mTreize->confidenceFor(self::paris('2026-09-28 12:00')), 'Lundi vu 3 fois mais 13 jours < 14 → moyenne.');
        self::assertSame('faible', $mTreize->confidenceFor(self::paris('2026-10-01 12:00')), 'Jeudi vu 2 fois < 3 → faible.');
    }

    public function test_sans_historique_et_panneaux_recents(): void
    {
        $vide = DemandModel::fit([], [], Hyper::K_POOL);
        self::assertSame(0.0, $vide->expectedN(self::paris('2026-10-05 12:00')), 'Sans historique → 0.0.');
        self::assertSame(0.0, $vide->expectedCa(self::paris('2026-10-05 12:00')));
        self::assertTrue($vide->isInsufficient());
        self::assertSame([], $vide->openWeekdays);
        self::assertSame([], $vide->recentBaskets());
        self::assertSame([], $vide->recentBasketsByDow());

        $model = DemandModel::fit(self::serie(2), [], Hyper::K_POOL);
        // 10 jours au motif [70,60,65,75,90] : les 8 derniers = 65+75+90+70+60+65+75+90 paniers.
        $recents = $model->recentBaskets(8);
        self::assertCount(590, $recents, '8 derniers jours ouverts, 1 panier par transaction.');
        $parDow = $model->recentBasketsByDow(5);
        self::assertSame([0, 1, 2, 3, 4], array_keys($parDow), '5 derniers jours ouverts : un dow par jour.');
        self::assertCount(90, $parDow[4], 'Vendredi du motif : 90 paniers.');
    }

    public function test_dispersion_bornee_serie_plate(): void
    {
        // Série plate sans bruit (tous les dows à N = 50) : les résidus
        // in-sample sont nuls → dénominateur ≤ 0 → R_MAX (quasi-Poisson,
        // borne documentée).
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07');
        while (count($days) < 10) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $key = $d->format('Y-m-d');
                $days[$key] = new DayCurrent($key, 62.5, 50, self::horaire(50), array_fill(0, 50, 1.25));
            }
            $d = $d->modify('+1 day');
        }
        $model = DemandModel::fit($days, [], Hyper::K_POOL);

        self::assertEqualsWithDelta(Hyper::R_MAX, $model->dispersionR, 0.0, 'Résidus nuls → dénominateur ≤ 0 → R_MAX.');
        self::assertEqualsWithDelta(50.0, $model->globalN, 0.0001, 'GlobalN = moyenne des N.');
        self::assertEqualsWithDelta(1.25, $model->globalP, 0.0001, 'GlobalP = panier tronqué global.');
        self::assertEqualsWithDelta(50.0, $model->expectedN(self::paris('2026-09-21 12:00')), 50.0 * 0.01, 'Série plate : prévision du lundi suivant ≈ 50 à 1 %.');

        // Série au motif : la dispersion reste dans les bornes [R_MIN, R_MAX].
        $motif = DemandModel::fit(self::serie(4), [], Hyper::K_POOL);
        self::assertGreaterThanOrEqual(Hyper::R_MIN, $motif->dispersionR);
        self::assertLessThanOrEqual(Hyper::R_MAX, $motif->dispersionR);
    }

    /**
     * FIX G — dispersion IMPOSÉE : le refit « hier » réutilise le r du
     * modèle principal (dispersion = propriété globale, 4ᵉ paramètre de
     * DemandModel::fit). Valeur clampée [R_MIN, R_MAX] ; les autres
     * grandeurs (niveau, effets, paniers) restent strictement identiques
     * à un fit sans paramètre.
     */
    public function test_dispersion_imposee_reutilisee_et_bornee(): void
    {
        $days = self::serie(4);
        $m = DemandModel::fit($days, [], Hyper::K_POOL);

        $impose = DemandModel::fit($days, [], Hyper::K_POOL, 18.0);
        self::assertEqualsWithDelta(18.0, $impose->dispersionR, 0.0, 'r imposé repris tel quel (dans les bornes).');
        self::assertEqualsWithDelta($m->globalN, $impose->globalN, 1.0e-9, 'globalN inchangé.');
        self::assertEqualsWithDelta($m->globalP, $impose->globalP, 1.0e-9, 'globalP inchangé.');
        self::assertEqualsWithDelta(
            $m->expectedN(self::paris('2026-10-05 12:00')),
            $impose->expectedN(self::paris('2026-10-05 12:00')),
            1.0e-9,
            'Attendus du modèle inchangés par la dispersion imposée.'
        );

        self::assertEqualsWithDelta(Hyper::R_MIN, DemandModel::fit($days, [], Hyper::K_POOL, 0.1)->dispersionR, 0.0, 'Clamp bas sur R_MIN.');
        self::assertEqualsWithDelta(Hyper::R_MAX, DemandModel::fit($days, [], Hyper::K_POOL, 9999.0)->dispersionR, 0.0, 'Clamp haut sur R_MAX.');
    }

    /**
     * $n semaines lun–ven du motif [70,60,65,75,90], panier 1.25,
     * horaire 8h→17h. Avec $samedi : samedis ouverts à N = 72.
     *
     * @return array<string,DayCurrent>
     */
    private static function serie(int $semaines, bool $samedi = false): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07'); // lundi
        $fin = 5 * $semaines + ($samedi ? $semaines : 0);
        while (count($days) < $fin) {
            $dow = (int) $d->format('N') - 1;
            $n = $dow === 5 ? 72 : ($dow <= 4 ? self::MOTIF[$dow] : 0);
            if (($dow <= 4) || ($dow === 5 && $samedi)) {
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

    private static function paris(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone(Hyper::TZ));
    }
}
