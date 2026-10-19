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
}
