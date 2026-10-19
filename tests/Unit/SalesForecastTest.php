<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\SalesForecast;
use PHPUnit\Framework\TestCase;

/**
 * Prévision de CA (pure, sans DB) : moyennes par jour de semaine sur
 * les jours écoulés, repli global pour un jour jamais vu, pro-rata
 * horaire (avec repli linéaire), badges du jour et d'hier.
 *
 * Grille de référence : 2026-10-19 est un LUNDI, donc 2026-10-01 est
 * un jeudi, 2026-10-05 et 2026-10-12 des lundis, 2026-10-15 un jeudi.
 */
final class SalesForecastTest extends TestCase
{
    private function paris(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Paris'));
    }

    /**
     * 14 jours écoulés (01 → 14 octobre) : lundis à 100 €, tous les
     * autres jours à 40 € (chaque jour de semaine apparaît 2 fois).
     *
     * @return array<string,float>
     */
    private function deuxSemaines(float $lundi = 100.0, float $autres = 40.0): array
    {
        $map = [];
        for ($d = 1; $d <= 14; $d++) {
            $jour  = sprintf('2026-10-%02d', $d);
            $estLundi = in_array($d, [5, 12], true);
            $map[$jour] = $estLundi ? $lundi : $autres;
        }

        return $map;
    }

    public function test_moyenne_par_jour_de_semaine_sur_14_jours(): void
    {
        $fc = SalesForecast::forecast($this->deuxSemaines(), $this->paris('2026-10-15 12:00:00'));

        // 7 jours à partir de DEMAIN (vendredi 16).
        self::assertCount(7, $fc['days']);
        self::assertSame('2026-10-16', $fc['days'][0]['date']);
        self::assertSame('Ven 16/10', $fc['days'][0]['label']);
        self::assertSame(40.0, $fc['days'][0]['value'], 'Vendredi vu 2 fois à 40 € → moyenne 40.');

        // Lundi 19 : vu 2 fois à 100 € → moyenne 100.
        self::assertSame('2026-10-19', $fc['days'][3]['date']);
        self::assertSame('Lun 19/10', $fc['days'][3]['label']);
        self::assertSame(100.0, $fc['days'][3]['value']);

        self::assertSame(14, $fc['elapsed_days']);
        self::assertTrue($fc['reliable']);
        self::assertEqualsWithDelta(680 / 14, $fc['global_avg'], 0.0001, '2×100 + 12×40 sur 14 jours.');
    }

    public function test_jour_de_semaine_jamais_vu_repli_sur_moyenne_globale(): void
    {
        // Seuls des lundis et mardis sont écoulés.
        $fc = SalesForecast::forecast(
            ['2026-10-05' => 100.0, '2026-10-06' => 200.0, '2026-10-12' => 300.0, '2026-10-13' => 100.0],
            $this->paris('2026-10-15 12:00:00')
        );

        self::assertSame(4, $fc['elapsed_days']);
        self::assertEqualsWithDelta(175.0, $fc['global_avg'], 0.0001);

        // Vendredi 16 jamais vu → moyenne globale.
        self::assertEqualsWithDelta(175.0, $fc['days'][0]['value'], 0.0001);
        // Jeudi (aujourd'hui) jamais vu non plus.
        self::assertEqualsWithDelta(175.0, $fc['today']['forecast_full'], 0.0001);
        // Lundi 19 vu 2 fois → sa propre moyenne.
        self::assertEqualsWithDelta(200.0, $fc['days'][3]['value'], 0.0001);
    }

    public function test_pro_rata_avec_parts_horaires_reelles(): void
    {
        $shares = [10 => 0.5, 14 => 0.5];

        // À 14h30 : seules les heures < 14 comptent (10h) → 50 %.
        self::assertEqualsWithDelta(120.0, SalesForecast::proRatedForecast(240.0, $shares, $this->paris('2026-10-15 14:30:00')), 0.0001);
        // À 16h : heures 10 et 14 écoulées → 100 %.
        self::assertEqualsWithDelta(240.0, SalesForecast::proRatedForecast(240.0, $shares, $this->paris('2026-10-15 16:00:00')), 0.0001);
        // À 10h pile : aucune part écoulée → repli linéaire 11/24.
        self::assertEqualsWithDelta(240.0 * 11 / 24, SalesForecast::proRatedForecast(240.0, $shares, $this->paris('2026-10-15 10:00:00')), 0.0001);
    }

    public function test_pro_rata_repli_lineaire_sans_parts(): void
    {
        // Aucune part → (heure + 1) / 24 : à 9h, 10 heures écoulées.
        self::assertEqualsWithDelta(100.0, SalesForecast::proRatedForecast(240.0, [], $this->paris('2026-10-15 09:15:00')), 0.0001);
        // Parts toutes nulles → même repli linéaire.
        self::assertEqualsWithDelta(150.0, SalesForecast::proRatedForecast(240.0, [12 => 0.0, 18 => 0.0], $this->paris('2026-10-15 14:00:00')), 0.0001);
    }

    public function test_aucun_jour_ecoule_prevision_nulle(): void
    {
        // Seul aujourd'hui est fourni : rien d'écoulé.
        $fc = SalesForecast::forecast(['2026-10-15' => 50.0], $this->paris('2026-10-15 12:00:00'));

        self::assertSame(0, $fc['elapsed_days']);
        self::assertFalse($fc['reliable']);
        self::assertNull($fc['global_avg']);
        self::assertNull($fc['today']['forecast_full']);
        self::assertNull($fc['today']['forecast_so_far']);
        self::assertNull($fc['today']['delta_pct']);
        self::assertNull($fc['yesterday']['forecast_full']);
        foreach ($fc['days'] as $d) {
            self::assertNull($d['value']);
        }
        // Le réel du jour reste repris de la carte.
        self::assertSame(50.0, $fc['today']['actual']);
    }

    public function test_delta_nul_quand_la_prevision_est_nulle_ou_nulle_valeur(): void
    {
        // 14 jours écoulés mais tous à 0 € : prévision 0 → delta null.
        $fc = SalesForecast::forecast($this->deuxSemaines(0.0, 0.0), $this->paris('2026-10-15 12:00:00'));

        self::assertTrue($fc['reliable']);
        self::assertSame(0.0, $fc['today']['forecast_full']);
        self::assertSame(0.0, $fc['today']['forecast_so_far']);
        self::assertNull($fc['today']['delta_pct'], 'Division par zéro interdite : delta null.');
    }

    public function test_fiable_a_partir_de_7_jours_ecoules(): void
    {
        $six = [];
        for ($d = 9; $d <= 14; $d++) {
            $six[sprintf('2026-10-%02d', $d)] = 10.0;
        }
        self::assertFalse(SalesForecast::forecast($six, $this->paris('2026-10-15 12:00:00'))['reliable'], '6 jours : non fiable.');

        $sept = ['2026-10-08' => 10.0] + $six;
        self::assertTrue(SalesForecast::forecast($sept, $this->paris('2026-10-15 12:00:00'))['reliable'], '7 jours : fiable.');
    }

    public function test_badges_du_jour_pro_rata_et_d_hier(): void
    {
        $today = $this->paris('2026-10-15 00:30:00'); // jeudi, G = 0 → 1/24 linéaire
        $map   = $this->deuxSemaines();
        $map['2026-10-15'] = 2.0; // réel du jour en cours

        $fc = SalesForecast::forecast($map, $today);

        // Jeudi vu 2 fois à 40 € → prévision pleine 40 €, pro-rata 40/24.
        self::assertSame(40.0, $fc['today']['forecast_full']);
        self::assertEqualsWithDelta(40.0 / 24, $fc['today']['forecast_so_far'], 0.0001);
        self::assertSame(2.0, $fc['today']['actual']);
        self::assertSame(20.0, $fc['today']['delta_pct'], '(2 − 40/24) / (40/24) = +20 %.');

        // Hier (mercredi 14) : 40 € réels vs 40 € prévus → delta 0.
        self::assertSame(40.0, $fc['yesterday']['actual']);
        self::assertSame(40.0, $fc['yesterday']['forecast_full']);
        self::assertSame(0.0, $fc['yesterday']['delta_pct']);
    }

    public function test_sans_entree_pour_aujourd_hui_le_reel_est_zero(): void
    {
        $fc = SalesForecast::forecast($this->deuxSemaines(), $this->paris('2026-10-15 12:00:00'));

        self::assertSame(0.0, $fc['today']['actual'], 'Jour absent de la carte → réel 0 (pas null).');
    }

    /**
     * Parts horaires et CA du jour partagés par les tests dayTrack :
     * estimation 240 €, ventes à 10h (50 %) et 14h (50 %).
     *
     * @return array{0:array<int,float>,1:float,2:array<int,float>}
     */
    private function journeeSuivi(): array
    {
        return [
            [10 => 60.0, 12 => 30.0, 14 => 30.0], // CA du jour par heure
            240.0,                                    // estimation journée
            [10 => 0.5, 14 => 0.5],                   // parts horaires
        ];
    }

    public function test_daytrack_courbes_cumulees_correctes(): void
    {
        [$ca, $estimation, $shares] = $this->journeeSuivi();

        $t = SalesForecast::dayTrack($ca, $estimation, $shares, $this->paris('2026-10-15 12:30:00'));

        self::assertCount(24, $t['cum_actual']);
        self::assertCount(24, $t['cum_expected']);

        // Réel : 0 € jusqu'à 9h, 60 € à 10-11h, 90 € à 12-13h, 120 € ensuite.
        self::assertSame(0.0, $t['cum_actual'][9]);
        self::assertSame(60.0, $t['cum_actual'][10]);
        self::assertSame(90.0, $t['cum_actual'][12]);
        self::assertSame(120.0, $t['cum_actual'][14]);
        self::assertSame(120.0, $t['cum_actual'][23]);

        // Attendu : 0 € jusqu'à 9h (parts 0 → cumul porté), 120 € à
        // 10-13h, 240 € à partir de 14h.
        self::assertSame(0.0, $t['cum_expected'][9]);
        self::assertSame(120.0, $t['cum_expected'][10]);
        self::assertSame(120.0, $t['cum_expected'][13]);
        self::assertSame(240.0, $t['cum_expected'][14]);
        self::assertSame(240.0, $t['cum_expected'][23]);
    }

    public function test_daytrack_pro_rata_et_projection_fin_de_journee_exacts(): void
    {
        [$ca, $estimation, $shares] = $this->journeeSuivi();

        // À 12h30 : 90 € réels vs 120 € attendus (moitié des parts écoulée).
        $t = SalesForecast::dayTrack($ca, $estimation, $shares, $this->paris('2026-10-15 12:30:00'));
        self::assertSame(12, $t['hour']);
        self::assertSame(90.0, $t['actual_so_far']);
        self::assertSame(120.0, $t['expected_so_far']);
        self::assertSame(-25.0, $t['delta_pct'], '(90 − 120) / 120 = −25 %.');
        self::assertEqualsWithDelta(210.0, $t['projection_eod'], 0.0001, '90 + 240 × (1 − 0,5) = 210.');

        // À 14h30 : 120 € réels vs 240 € attendus, plus rien à ajouter.
        $t14 = SalesForecast::dayTrack($ca, $estimation, $shares, $this->paris('2026-10-15 14:30:00'));
        self::assertSame(14, $t14['hour']);
        self::assertSame(120.0, $t14['actual_so_far']);
        self::assertSame(240.0, $t14['expected_so_far']);
        self::assertSame(-50.0, $t14['delta_pct']);
        self::assertEqualsWithDelta(120.0, $t14['projection_eod'], 0.0001, 'Part écoulée = 1 → projection = réel.');
    }

    public function test_daytrack_delta_null_quand_attendu_zero(): void
    {
        // Estimation nulle : attendu 0 à toute heure → pas de division.
        $t = SalesForecast::dayTrack([10 => 50.0], 0.0, [10 => 1.0], $this->paris('2026-10-15 12:00:00'));
        self::assertSame(0.0, $t['expected_so_far']);
        self::assertNull($t['delta_pct']);
        self::assertSame(50.0, $t['projection_eod'], 'Rien à ajouter : projection = réel.');

        // Estimation positive mais toutes les ventes arrivent plus tard :
        // l'attendu à cette heure est encore 0 → delta null aussi.
        $t2 = SalesForecast::dayTrack([10 => 50.0], 100.0, [20 => 1.0], $this->paris('2026-10-15 12:00:00'));
        self::assertNull($t2['delta_pct']);
        self::assertEqualsWithDelta(150.0, $t2['projection_eod'], 0.0001, '50 + 100 × (1 − 0) = 150.');
    }

    public function test_daytrack_repli_lineaire_sans_parts_horaires(): void
    {
        // Aucune part → 1/24 par heure : à 9h15, 10/24e de journée écoulés.
        $t = SalesForecast::dayTrack([9 => 100.0], 240.0, [], $this->paris('2026-10-15 09:15:00'));
        self::assertEqualsWithDelta(240.0 / 24, $t['cum_expected'][0], 0.0001);
        self::assertEqualsWithDelta(240.0 * 10 / 24, $t['cum_expected'][9], 0.0001);
        self::assertEqualsWithDelta(240.0, $t['cum_expected'][23], 0.0001);
        self::assertEqualsWithDelta(100.0, $t['expected_so_far'], 0.0001);
        self::assertSame(0.0, $t['delta_pct'], '100 réels vs 100 attendus.');
        self::assertEqualsWithDelta(240.0, $t['projection_eod'], 0.0001, '100 + 240 × (1 − 10/24) = 240.');

        // Parts toutes nulles → même repli linéaire.
        $t2 = SalesForecast::dayTrack([9 => 100.0], 240.0, [12 => 0.0, 18 => 0.0], $this->paris('2026-10-15 09:15:00'));
        self::assertEqualsWithDelta(100.0, $t2['expected_so_far'], 0.0001);
    }

    public function test_daytrack_heures_manquantes_comptent_pour_zero(): void
    {
        // Seule une vente à 23h : le cumulé reste 0 jusqu'à 22h.
        $t = SalesForecast::dayTrack([23 => 10.0], 20.0, [23 => 1.0], $this->paris('2026-10-15 23:30:00'));

        for ($h = 0; $h <= 22; $h++) {
            self::assertSame(0.0, $t['cum_actual'][$h], "Heure $h absente → cumulé 0.");
        }
        self::assertSame(10.0, $t['cum_actual'][23]);
        self::assertSame(10.0, $t['actual_so_far']);
        self::assertEqualsWithDelta(20.0, $t['expected_so_far'], 0.0001);
        self::assertSame(-50.0, $t['delta_pct']);
    }
}
