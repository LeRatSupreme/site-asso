<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\ComptaCalc;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la logique de calcul comptable (pure, sans DB).
 *
 * Valide : calcul de bénéfice (TTC − coût×qté), exclusion des montants
 * personnalisés du bénéfice (mais pas du CA), et sélection du bon lot de
 * coût selon la date.
 */
final class ComptaCalcTest extends TestCase
{
    public function test_benefice_unitaire_ttc_moins_cout(): void
    {
        // Bueno : 1 € TTC − 0,60 € coût = 0,40 € de bénéfice.
        self::assertSame(0.40, ComptaCalc::lineProfit(1.0, 0.60, 1, false));
    }

    public function test_benefifice_multiplie_par_quantite(): void
    {
        // 2 € TTC (donc 2 × Bueno) − 0,60 × 2 = 2 − 1,20 = 0,80.
        self::assertSame(0.80, ComptaCalc::lineProfit(2.0, 0.60, 2, false));
    }

    public function test_montant_personnalise_exclu_du_benefice(): void
    {
        // Un montant personnalisé n'a pas de coût de revient : bénéfice = 0.
        self::assertSame(0.0, ComptaCalc::lineProfit(1.75, 0.60, 1, true));
        // Même sans coût connu, le montant perso reste exclu.
        self::assertSame(0.0, ComptaCalc::lineProfit(5.0, null, 1, true));
    }

    public function test_cout_inconnu_donne_benefifice_egal_au_ttc(): void
    {
        // Pas de lot saisi → coût = 0 → bénéfice = TTC (surévalué, attendu).
        self::assertSame(1.0, ComptaCalc::lineProfit(1.0, null, 1, false));
    }

    public function test_marge_pourcentage(): void
    {
        // 24 € de bénéfice / 60 € de CA = 40 %.
        self::assertSame(40.0, ComptaCalc::marginPercent(24.0, 60.0));
    }

    public function test_marge_pourcentage_ca_nul_renvoie_zero(): void
    {
        self::assertSame(0.0, ComptaCalc::marginPercent(10.0, 0.0));
    }

    public function test_selection_du_lot_valide_selon_la_date(): void
    {
        $lots = [
            ['valid_from' => '2026-06-01', 'valid_to' => null,      'cost_price' => 0.60, 'supplier' => 'A'],
            ['valid_from' => '2026-01-01', 'valid_to' => '2026-06-01', 'cost_price' => 0.50, 'supplier' => 'B'],
        ];

        // Vente en mai → lot B (0,50).
        $lotMay = ComptaCalc::selectCostLot('2026-05-15', $lots);
        self::assertNotNull($lotMay);
        self::assertSame(0.50, (float) $lotMay['cost_price']);

        // Vente en juin → lot A (0,60).
        $lotJune = ComptaCalc::selectCostLot('2026-06-10', $lots);
        self::assertNotNull($lotJune);
        self::assertSame(0.60, (float) $lotJune['cost_price']);
    }

    public function test_aucun_lot_valide_renvoie_null(): void
    {
        $lots = [
            ['valid_from' => '2026-06-01', 'valid_to' => '2026-07-01', 'cost_price' => 0.60],
        ];

        // Vente AVANT le premier lot.
        self::assertNull(ComptaCalc::selectCostLot('2026-05-01', $lots));
        // Vente APRÈS la fin du lot (valid_to exclusive).
        self::assertNull(ComptaCalc::selectCostLot('2026-07-01', $lots));
        self::assertNotNull(ComptaCalc::selectCostLot('2026-06-30', $lots));
    }

    public function test_cost_at_raccourci_renvoie_le_prix(): void
    {
        $lots = [
            ['valid_from' => '2026-06-01', 'valid_to' => null, 'cost_price' => 0.60],
        ];

        self::assertSame(0.60, ComptaCalc::costAt('2026-06-15', $lots));
        self::assertNull(ComptaCalc::costAt('2026-05-15', $lots));
    }

    public function test_moyenne_mobile_3_mois(): void
    {
        // 60 ventes en avril, 90 en mai, 30 en juin → moyenne = (60+90+30)/3 = 60.
        self::assertSame(60.0, ComptaCalc::movingAverage([60, 90, 30], 3));
    }

    public function test_moyenne_mobile_fenetre_supérieure_a_la_serie(): void
    {
        // Si moins de mois que la fenêtre, on moyenne ce qu'on a.
        self::assertSame(45.0, ComptaCalc::movingAverage([30, 60], 3));
    }

    public function test_suggestion_de_reappro(): void
    {
        // Besoin sur 1 mois = 60, stock = 20 → suggérer 40.
        self::assertSame(40, ComptaCalc::suggestedReorder(60.0, 1, 20));
        // Stock suffisant → 0.
        self::assertSame(0, ComptaCalc::suggestedReorder(60.0, 1, 80));
    }

    public function test_autonomie_en_jours(): void
    {
        // 60 u/mois = 2 u/jour ; stock 30 → 15 jours.
        self::assertSame(15, ComptaCalc::autonomyDays(30, 60.0));
        // Conso inconnue → null.
        self::assertNull(ComptaCalc::autonomyDays(30, 0.0));
    }

    public function test_scenario_complet_bueno_juin_2026(): void
    {
        // Scénario §21.11 : 60 Bueno en juin × 1 € TTC, coût lot juin = 0,60.
        $ca = 60 * 1.0;
        $beneficeUnite = ComptaCalc::lineProfit(1.0, 0.60, 1, false);
        $beneficeTotal = $beneficeUnite * 60;
        $marge = ComptaCalc::marginPercent($beneficeTotal, $ca);

        self::assertSame(0.40, $beneficeUnite);
        self::assertSame(24.0, $beneficeTotal);
        self::assertSame(40.0, $marge);
    }

    public function test_scenario_changement_de_fournisseur_juillet(): void
    {
        // En juillet, nouveau lot à 0,70 → marge recalculée à 30 %.
        $beneficeJuillet = ComptaCalc::lineProfit(1.0, 0.70, 1, false);
        $margeJuillet = ComptaCalc::marginPercent($beneficeJuillet, 1.0);

        self::assertSame(0.30, $beneficeJuillet);
        self::assertSame(30.0, $margeJuillet);
    }
}
