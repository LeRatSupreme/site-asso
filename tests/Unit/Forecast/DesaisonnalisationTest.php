<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Core\Compta\Forecast\ForecastDataset;
use App\Core\Compta\Forecast\ForecastEngine;
use App\Core\Compta\Forecast\DayCurrent;
use PHPUnit\Framework\TestCase;

/**
 * DÉSAISONNALISATION À L'ENTRÉE (correction du double-comptage vacances).
 *
 * Le drapeau « période creuse » ne doit compter la baisse qu'UNE SEULE
 * FOIS : les jours passés en plage sont ramenés au niveau normal (÷f)
 * AVANT l'apprentissage (DemandModel, HourlyProfile, Backtest), et le
 * facteur ×f ne s'applique qu'aux prévisions des dates EN plage. Sans la
 * désaisonnalisation, le niveau apprend les jours bas PUIS ×f les
 * re-compte (sous-estimation ~50 % observée en prod pendant les vacances
 * du 21/10/2026 : prévision mercredi 60 € au lieu de ~80 €).
 *
 * Cas du propriétaire : jours à N=80, panier 1,25 → CA 100 €.
 *  - T1 : série plate 100 € SANS drapeau → prévisions ≈ 100 € (anti-45 €) ;
 *  - T2 : 6 jours à 100 € puis 6 jours de vacances à 80 € flagués →
 *    prévision EN plage ≈ 80 €, HORS plage ≈ 100 €, tendance ≈ 0 ;
 *  - T3 : ajouter/retirer le jour en cours (absent ou partiel) pendant une
 *    plage ne change PAS les jours suivants.
 */
final class DesaisonnalisationTest extends TestCase
{
    /** Série plate SANS drapeau : prévision du jour et jours ≈ 100 € (±5 %). */
    public function test_t1_serie_plate_sans_drapeau_previsions_100_eur(): void
    {
        $r = ForecastEngine::forecast(
            new ForecastDataset(self::serie(15, 80)), // 3 semaines pleines à 100 €
            self::paris('2026-09-28 23:59'),          // lundi suivant (ouvert)
            '23:59',
            ['seed' => 42, 'draws' => 2000]
        );

        self::assertNotNull($r['prevision_jour']);
        self::assertEqualsWithDelta(100.0, (float) $r['prevision_jour'], 5.0, 'Série plate 100 € sans plage : prévision du jour ≈ 100 € (verrou anti-45 €).');

        $ouverts = 0;
        foreach ($r['jours'] as $j) {
            if ($j['is_open']) {
                $ouverts++;
                self::assertEqualsWithDelta(100.0, (float) $j['ca'], 5.0, "Série plate sans plage : {$j['label']} ≈ 100 €.");
            }
        }
        self::assertSame(5, $ouverts, 'Lun..ven ouverts, sam/dim fermés.');

        // debug cohérent sur série plate : niveau ≈ 100 €, tendance ≈ 0.
        self::assertEqualsWithDelta(100.0, (float) $r['debug']['niveau_eur'], 2.0, 'Niveau appris ≈ 100 €/jour (80 tx × panier 1,25).');
        self::assertEqualsWithDelta(0.0, (float) $r['debug']['tendance_pct_jour'], 2.0, 'Série plate : tendance ≈ 0 %/jour.');
    }

    /**
     * T2 — LE test clé (échoue sur le code sans désaisonnalisation) :
     * 6 jours normaux à 100 € puis 6 jours de vacances à 80 € (mêmes dow),
     * plage creuse couvrant les 6 derniers et les jours à prévoir →
     * prévision d'un jour EN plage ≈ 80 € (±8 %), d'un jour HORS plage
     * ≈ 100 € (±8 %), tendance apprise ≈ 0 (±2 pts).
     */
    public function test_t2_vacances_flaguees_prevision_80_en_plage_100_hors_plage(): void
    {
        $days = self::serie(6, 80); // lun 07/09 → lun 14/09 à 100 €
        foreach (self::serie(6, 64, '2026-09-15') as $key => $day) { // mar 15/09 → mar 22/09 à 80 €
            $days[$key] = $day;
        }
        $opts = [
            'seed' => 42,
            'draws' => 2000,
            'slow_periods' => ['facteur' => 0.8, 'ranges' => [['start' => '2026-09-15', 'end' => '2026-09-28']]],
        ];

        $r = ForecastEngine::forecast(
            new ForecastDataset($days),
            self::paris('2026-09-23 23:59'), // mercredi 23/09, EN plage
            '23:59',
            $opts
        );

        // Jour EN plage (aujourd'hui, mercredi) : le niveau appris voit des
        // jours désaisonnalisés à 80 tx → prévision 80 × 1,25 × 0,8 = 80 €.
        self::assertNotNull($r['prevision_jour']);
        self::assertEqualsWithDelta(80.0, (float) $r['prevision_jour'], 80.0 * 0.08, 'T2 : jour EN plage ≈ 80 € (×0,8 sur niveau normal) — le double-comptage donnait ~60-72 €.');

        // Jours à prévoir : 24-28/09 EN plage (≈ 80 €), 29-30/09 HORS plage (≈ 100 €).
        $vuEnPlage = 0;
        $vuHorsPlage = 0;
        foreach ($r['jours'] as $j) {
            if (!$j['is_open']) {
                continue;
            }
            if ($j['date'] <= '2026-09-28') {
                $vuEnPlage++;
                self::assertEqualsWithDelta(80.0, (float) $j['ca'], 80.0 * 0.08, "T2 : {$j['label']} EN plage ≈ 80 €.");
            } else {
                $vuHorsPlage++;
                self::assertEqualsWithDelta(100.0, (float) $j['ca'], 100.0 * 0.08, "T2 : {$j['label']} HORS plage ≈ 100 € (niveau désaisonnalisé, pas de ×0,8).");
            }
        }
        self::assertGreaterThanOrEqual(3, $vuEnPlage);
        self::assertSame(2, $vuHorsPlage, 'Mar 29 + mer 30/09 hors plage.');

        // Tendance apprise ≈ 0 : la baisse des vacances n'est PAS apprise.
        self::assertEqualsWithDelta(0.0, (float) $r['debug']['tendance_pct_jour'], 2.0, 'T2 : tendance ≈ 0 %/jour (la baisse vacances n\'est pas dans le niveau).');
        self::assertEqualsWithDelta(100.0, (float) $r['debug']['niveau_eur'], 3.0, 'T2 : niveau appris au niveau NORMAL (100 €/jour désaisonnalisé).');
    }

    /**
     * T3 (variante période creuse) — non-régression jour courant : le jour
     * EN COURS (absent ou partiel, EN plage) ne change NI la prévision du
     * jour (pleine journée, modèle) NI les jours suivants.
     */
    public function test_t3_jour_cours_absent_ou_partiel_jours_suivants_inchanges_en_plage(): void
    {
        $now = self::paris('2026-09-16 08:09'); // mercredi, EN plage
        $opts = [
            'seed' => 42,
            'draws' => 2000,
            'slow_periods' => ['facteur' => 0.8, 'ranges' => [['start' => '2026-09-08', 'end' => '2026-10-31']]],
        ];

        $sansAuj = self::serie(7, 80); // lun 07/09 → mar 15/09 (mercredi absent)
        $partiel = $sansAuj;
        $partiel['2026-09-16'] = new DayCurrent('2026-09-16', 7.5, 6, [8 => 2, 9 => 4], array_fill(0, 6, 1.25));

        $r = ForecastEngine::forecast(new ForecastDataset($sansAuj), $now, '08:09', $opts);
        $rP = ForecastEngine::forecast(new ForecastDataset($partiel), $now, '08:09', $opts);

        self::assertNotNull($r['prevision_jour']);
        self::assertGreaterThan(0.0, (float) $r['prevision_jour'], 'Jour ouvré sans vente : prévision pleine journée calculée.');
        self::assertSame($r['jours'], $rP['jours'], 'T3 : les jours J+1..J+7 ne dépendent pas des données partielles du jour.');
        self::assertSame($r['prevision_jour'], $rP['prevision_jour'], 'T3 : l\'attendu du jour ne dépend pas des données partielles.');
        self::assertSame($r['intervalle_80'], $rP['intervalle_80']);
    }

    /** Note de désaisonnalisation présente avec le BON nombre de jours. */
    public function test_note_desaisonnalisation_compte_les_bons_jours(): void
    {
        $days = self::serie(6, 80);
        foreach (self::serie(6, 64, '2026-09-15') as $key => $day) {
            $days[$key] = $day;
        }

        $r = ForecastEngine::forecast(
            new ForecastDataset($days),
            self::paris('2026-09-23 23:59'),
            '23:59',
            ['seed' => 42, 'draws' => 300, 'slow_periods' => ['facteur' => 0.8, 'ranges' => [['start' => '2026-09-15', 'end' => '2026-09-28']]]]
        );

        self::assertContains(
            'Historique désaisonnalisé avant apprentissage : 6 jours de période creuse ramenés au niveau normal (÷0,8).',
            $r['notes']
        );
        foreach ($r['notes'] as $note) {
            self::assertStringNotContainsString('double-compter', (string) $note, 'Note double-comptage obsolète supprimée.');
        }
    }

    /**
     * Tooltip « dont période creuse » : obs_ca_creuse/obs_n_creuse sur
     * dataset à la main — mercredis 09/09 (100 €) et 16/09 (80 €, en
     * plage) : obs_ca = 90 €, obs_ca_creuse = 80 € (n=1).
     */
    public function test_obs_creuse_moyenne_et_nombre_sur_les_jours_en_plage(): void
    {
        $days = self::serie(6, 80);
        foreach (self::serie(6, 64, '2026-09-15') as $key => $day) {
            $days[$key] = $day;
        }

        $r = ForecastEngine::forecast(
            new ForecastDataset($days),
            self::paris('2026-09-23 23:59'),
            '23:59',
            ['seed' => 42, 'draws' => 100, 'slow_periods' => ['facteur' => 0.8, 'ranges' => [['start' => '2026-09-15', 'end' => '2026-09-28']]]]
        );

        $parDow = [];
        foreach ($r['jours_semaine'] as $js) {
            $parDow[(int) $js['dow']] = $js;
        }

        // Mercredi (dow 2) : obs globale (100 + 80) / 2 = 90 ; creuse : 80 (n=1).
        self::assertEqualsWithDelta(90.0, (float) $parDow[2]['obs_ca'], 1.0e-9, 'obs_ca = moyenne brute des deux mercredis.');
        self::assertEqualsWithDelta(80.0, (float) $parDow[2]['obs_ca_creuse'], 1.0e-9, 'obs_ca_creuse = moyenne des SEULS mercredis en plage.');
        self::assertSame(1, (int) $parDow[2]['obs_n_creuse']);
        self::assertEqualsWithDelta(100.0, (float) $parDow[2]['ca'], 8.0, 'Modèle mercredi : prochaine occurrence (30/09) HORS plage ≈ 100 €.');

        // Lundi (dow 0) : deux lundis hors plage (100 €) + un en plage (80 €).
        self::assertEqualsWithDelta((100.0 + 100.0 + 80.0) / 3.0, (float) $parDow[0]['obs_ca'], 1.0e-9);
        self::assertEqualsWithDelta(80.0, (float) $parDow[0]['obs_ca_creuse'], 1.0e-9);
        self::assertSame(1, (int) $parDow[0]['obs_n_creuse']);

        // Aucun jour en plage pour un dow → champs null (ici aucun dow, le
        // dataset couvre tous les dows ouverts : on vérifie le type liste
        // et la cohérence occ/obs_n_creuse sur tous les dows ouverts).
        foreach ($parDow as $dow => $js) {
            if (!$js['is_open']) {
                self::assertNull($js['obs_ca_creuse']);
                self::assertNull($js['obs_n_creuse']);
                continue;
            }
            self::assertTrue(
                $js['obs_n_creuse'] === null || (int) $js['obs_n_creuse'] <= (int) $js['occ'],
                "dow $dow : obs_n_creuse ≤ occ."
            );
        }
    }

    /**
     * Série synthétique : $jours jours ouverts consécutifs lun–ven depuis
     * le lundi 07/09/2026 (ou depuis $debut), N constant = $n, panier
     * constant 1,25 → CA = $n × 1,25.
     *
     * @return array<string,DayCurrent>
     */
    private static function serie(int $jours, int $n, string $debut = '2026-09-07'): array
    {
        $days = [];
        $d = new \DateTimeImmutable($debut);
        while (count($days) < $jours) {
            $dow = (int) $d->format('N') - 1;
            if ($dow <= 4) {
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
