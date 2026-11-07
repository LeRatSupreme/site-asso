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
 * Carte « Par jour de semaine » (jours_semaine + meilleur_jour) :
 *  - ré-normalisation : ca = attendu de la PROCHAINE occurrence du dow
 *    (expectedCa du moteur — niveau + tendance amortie), ×facteur creuse
 *    quand la date prochaine est en plage — cohérent avec « jours » ;
 *  - série observée : obs_ca = moyenne brute des CA passés du dow,
 *    occ = nb de jours vus ;
 *  - « meilleur jour » désigné SEULEMENT si ca_best − ca_second >
 *    2 × sqrt(se_best² + se_second²) (sinon null + note) ;
 *  - formule se exacte : sqrt(μ + μ²/r) / sqrt(max(1, occ)).
 */
final class JoursSemaineTest extends TestCase
{
    /** Série plate 28 jours ouverts (lun 07/09 → mer 07/10), panier 1,25. */
    public function test_ca_modele_renormalise_sur_attendu_prochaine_occurrence(): void
    {
        $serie = self::serie(28);
        $now = self::paris('2026-10-19 23:59'); // lundi suivant la série
        $r = ForecastEngine::forecast(new ForecastDataset($serie), $now, '23:59', ['seed' => 42, 'draws' => 200]);

        // Modèle indépendant (MÊME fit que le moteur) : source de vérité.
        $model = DemandModel::fit($serie, Clean::atypicalWeights($serie), Hyper::K_POOL);

        $parJour = [];
        foreach ($r['jours'] as $j) {
            $parJour[(string) $j['date']] = $j;
        }

        foreach ($r['jours_semaine'] as $js) {
            $dow = (int) $js['dow'];
            if (!$js['is_open']) {
                self::assertNull($js['next_date'], 'Jour fermé : pas de prochaine occurrence.');
                self::assertNull($js['obs_ca'], 'Jour fermé jamais vu : obs_ca null.');
                self::assertSame(0.0, (float) $js['ca']);
                continue;
            }

            // Prochaine occurrence STRICTEMENT après aujourd'hui.
            $next = $now->modify('+1 day');
            while ((int) $next->format('N') - 1 !== $dow) {
                $next = $next->modify('+1 day');
            }
            self::assertSame($next->format('Y-m-d'), $js['next_date']);

            $attendu = $model->expectedCa($next);
            self::assertEqualsWithDelta(
                $attendu,
                (float) $js['ca'],
                abs($attendu) * 1.0e-9 + 1.0e-9,
                "dow $dow : ca = expectedCa(prochaine occurrence) — niveau + tendance, pas le profil dow pur."
            );
            self::assertEqualsWithDelta(
                $model->expectedN($next),
                (float) $js['n'],
                1.0e-9,
                "dow $dow : n = E[N] de la prochaine occurrence."
            );

            // Cohérence avec la carte « jours » (même date, tirages MC) : ±5 %.
            $jour = $parJour[$js['next_date']] ?? null;
            self::assertNotNull($jour, 'La prochaine occurrence doit figurer dans jours (J+1..J+7).');
            self::assertEqualsWithDelta((float) $js['ca'], (float) $jour['ca'], max(0.05 * (float) $js['ca'], 0.01));
        }
    }

    /** Plage creuse couvrant les prochaines occurrences ⇒ ×0,8 exact. */
    public function test_periode_creuse_multiplie_les_jours_semaine(): void
    {
        $serie = self::serie(28);
        $now = self::paris('2026-10-19 23:59');
        $opts = ['seed' => 42, 'draws' => 200];
        $slow = ['facteur' => 0.8, 'ranges' => [['start' => '2026-10-19', 'end' => '2026-11-30']]];

        $base = ForecastEngine::forecast(new ForecastDataset($serie), $now, '23:59', $opts);
        $ajuste = ForecastEngine::forecast(new ForecastDataset($serie), $now, '23:59', $opts + ['slow_periods' => $slow]);

        foreach ($ajuste['jours_semaine'] as $i => $js) {
            if (!$js['is_open']) {
                continue;
            }
            self::assertEqualsWithDelta(
                (float) $base['jours_semaine'][$i]['ca'] * 0.8,
                (float) $js['ca'],
                1.0e-9,
                'Prochaine occurrence en plage creuse ⇒ ca ×0,8 (comme jours/semaine).'
            );
            self::assertEqualsWithDelta(
                (float) $base['jours_semaine'][$i]['n'] * 0.8,
                (float) $js['n'],
                1.0e-9,
                'n (E[N]) suit le facteur creuse.'
            );
        }
    }

    /** obs_ca = moyenne brute des CA passés du dow ; occ = jours vus. */
    public function test_obs_ca_et_occ_exact_sur_dataset_connu(): void
    {
        // 28 jours ouverts : lun/mar/mer vus 6 fois, jeu/ven 5 fois.
        $serie = self::serie(28);
        $r = ForecastEngine::forecast(
            new ForecastDataset($serie),
            self::paris('2026-10-19 23:59'),
            '23:59',
            ['seed' => 42, 'draws' => 100]
        );

        $attenduOcc = [6, 6, 6, 5, 5, 0, 0];
        $attenduObs = [87.5, 75.0, 81.25, 93.75, 112.5, null, null]; // N×1,25 par dow

        foreach ($r['jours_semaine'] as $dow => $js) {
            self::assertSame($attenduOcc[$dow], (int) $js['occ'], "dow $dow : occurrences comptées depuis les jours passés.");
            if ($attenduObs[$dow] === null) {
                self::assertNull($js['obs_ca']);
                continue;
            }
            self::assertEqualsWithDelta($attenduObs[$dow], (float) $js['obs_ca'], 1.0e-9, "dow $dow : moyenne brute des CA observés.");
        }
    }

    /** (a) petits écarts entre jours → pas de meilleur jour + note. */
    public function test_petits_ecarts_pas_de_meilleur_jour_avec_note(): void
    {
        // Écart max 2 transactions entre jours (2,5 €) vs se ≈ 5 € : rien
        // n'est désignable, quel que soit le partial pooling.
        $r = ForecastEngine::forecast(
            new ForecastDataset(self::serie(28, [70, 71, 70, 72, 70])),
            self::paris('2026-10-19 23:59'),
            '23:59',
            ['seed' => 42, 'draws' => 200]
        );

        self::assertNull($r['meilleur_jour'], 'Écarts de quelques € avec ~5 occurrences/jour : jamais de meilleur jour.');
        $trouve = false;
        foreach ($r['notes'] as $note) {
            if (str_contains((string) $note, 'statistiquement indiscernables')) {
                $trouve = true;
                break;
            }
        }
        self::assertTrue($trouve, 'Note honnête « jours indiscernables » requise : ' . json_encode($r['notes'], JSON_UNESCAPED_UNICODE));
    }

    /** (b) gros écart avec n élevé → meilleur jour désigné, sans note. */
    public function test_gros_ecart_n_eleve_meilleur_jour_designe(): void
    {
        // 56 jours ouverts (11 semaines), ven = 300 tx (375 €) : écart
        // ven−jeu ≈ 116 € > 2 × sqrt(se_ven² + se_jeu²) ≈ 90 €.
        $r = ForecastEngine::forecast(
            new ForecastDataset(self::serie(56, [70, 60, 65, 75, 300])),
            self::paris('2026-11-23 23:59'),
            '23:59',
            ['seed' => 42, 'draws' => 200]
        );

        self::assertNotNull($r['meilleur_jour'], 'Vendredi ×3 sur 11 semaines : écart largement significatif.');
        self::assertSame(4, (int) $r['meilleur_jour']['dow']);
        self::assertSame('Ven', (string) $r['meilleur_jour']['label']);
        foreach ($r['notes'] as $note) {
            self::assertStringNotContainsString('indiscernables', (string) $note, 'Meilleur jour désigné ⇒ pas de note indiscernables.');
        }
    }

    /** (c) formule se exacte sur cas calculé à la main (r = R_MAX). */
    public function test_formule_se_exacte_cas_calcule_a_la_main(): void
    {
        // Série plate : résidus quasi nuls → den ≤ 0 → r = R_MAX = 200.
        $serie = self::serie(28);
        $r = ForecastEngine::forecast(
            new ForecastDataset($serie),
            self::paris('2026-10-19 23:59'),
            '23:59',
            ['seed' => 42, 'draws' => 100]
        );

        self::assertEqualsWithDelta(Hyper::R_MAX, (float) $r['dispersion_r'], 1.0e-9, 'Série plate : dispersion quasi-Poisson (R_MAX).');

        foreach ($r['jours_semaine'] as $js) {
            if (!$js['is_open']) {
                self::assertNull($js['se']);
                continue;
            }
            $mu  = (float) $js['ca'];
            $occ = max(1, (int) $js['occ']);
            $attendu = sqrt($mu + $mu * $mu / Hyper::R_MAX) / sqrt($occ);
            self::assertEqualsWithDelta($attendu, (float) $js['se'], 1.0e-12, "dow {$js['label']} : se = sqrt(μ + μ²/r)/√occ.");

            // Cas littéral (lundi) : μ ≈ 87,21 €, occ = 6 → se ≈ 4,57.
            if ((int) $js['dow'] === 0) {
                self::assertEqualsWithDelta(6, (int) $js['occ'], 0.0);
                self::assertGreaterThan(4.0, (float) $js['se']);
                self::assertLessThan(5.2, (float) $js['se']);
            }
        }
    }

    /**
     * Série synthétique : $jours jours ouverts consécutifs lun–ven depuis
     * le lundi 07/09/2026, N selon $motif, panier constant 1,25.
     *
     * @return array<string,DayCurrent>
     */
    private static function serie(int $jours, array $motif = [70, 60, 65, 75, 90]): array
    {
        $days = [];
        $d = new \DateTimeImmutable('2026-09-07');
        while (count($days) < $jours) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
                $n = $motif[$dow];
                $key = $d->format('Y-m-d');
                $h = array_fill(0, 24, 0);
                $base = intdiv($n, 10);
                $reste = $n - $base * 10;
                for ($i = 0; $i < 10; $i++) {
                    $h[8 + $i] = $base + ($i < $reste ? 1 : 0);
                }
                $days[$key] = new DayCurrent($key, $n * 1.25, $n, $h, array_fill(0, $n, 1.25));
            }
            $d = $d->modify('+1 day');
        }

        return $days;
    }

    private static function paris(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Paris'));
    }
}
