<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Core\Compta\Dashboard\Cartes;
use PHPUnit\Framework\TestCase;

/**
 * Cartes::construire — 4 cartes dans l'ordre (ca, profit, net, articles),
 * repères en % seulement si la période est complète ET le précédent fiable
 * et non nul, ligne2 « sur 1 € » seulement si la période est complète et le
 * CA positif, repere_code du résultat net par son signe (jamais par la
 * tendance), formats FR (espace milliers, virgule décimale).
 */
final class CartesTest extends TestCase
{
    /** Période actuelle de référence : CA 1000, marge 300, 42 articles, 100 de dépenses (net 200). */
    private static function actuel(): array
    {
        return DashboardScenario::chiffres(1000.0, 300.0, 42.0, 100.0);
    }

    /** Période précédente de référence : CA 900, marge 250, 40 articles, 80 de dépenses (net 170). */
    private static function precedent(): array
    {
        return DashboardScenario::chiffres(900.0, 250.0, 40.0, 80.0);
    }

    public function test_quatre_cartes_dans_l_ordre_avec_labels_et_structure(): void
    {
        $cartes = Cartes::construire(self::actuel(), self::precedent(), true, true, []);

        self::assertSame(['ca', 'profit', 'net', 'articles'], array_column($cartes, 'cle'));
        self::assertSame('Argent encaissé', $cartes[0]['label']);
        self::assertSame("Ce qu'on gagne sur les ventes", $cartes[1]['label']);
        self::assertSame('Ce qui reste après les dépenses', $cartes[2]['label']);
        self::assertSame('Articles vendus', $cartes[3]['label']);
        foreach ($cartes as $carte) {
            self::assertSame(
                ['cle', 'label', 'valeur', 'valeur_num', 'repere', 'repere_code', 'aide', 'ligne2'],
                array_keys($carte)
            );
        }
    }

    public function test_reperes_pourcentages_exactes_avec_precedent_fiable(): void
    {
        $cartes = Cartes::construire(self::actuel(), self::precedent(), true, true, []);

        // CA : (1000 − 900) / 900 × 100 = +11,1 %.
        self::assertSame('1 000,00 €', $cartes[0]['valeur']);
        self::assertEqualsWithDelta(1000.0, $cartes[0]['valeur_num'], 1.0e-9);
        self::assertSame('+11,1 % vs période précédente', $cartes[0]['repere']);
        self::assertSame('ok', $cartes[0]['repere_code']);

        // Profit : pas de repère défini, ligne2 « sur 1 € » (300 / 1000 = 0,30).
        self::assertSame('300,00 €', $cartes[1]['valeur']);
        self::assertNull($cartes[1]['repere']);
        self::assertSame('neutre', $cartes[1]['repere_code']);
        self::assertSame('Sur 1 € vendu, on garde 0,30 €', $cartes[1]['ligne2']);

        // Résultat net : (200 − 170) / 170 × 100 = +17,6 % ; code par le signe (200 ≥ 0).
        self::assertSame('200,00 €', $cartes[2]['valeur']);
        self::assertEqualsWithDelta(200.0, $cartes[2]['valeur_num'], 1.0e-9);
        self::assertSame('+17,6 % vs période précédente', $cartes[2]['repere']);
        self::assertSame('ok', $cartes[2]['repere_code']);

        // Articles : (42 − 40) / 40 × 100 = +5,0 %, sans suffixe.
        self::assertSame('42', $cartes[3]['valeur']);
        self::assertEqualsWithDelta(42.0, $cartes[3]['valeur_num'], 1.0e-9);
        self::assertSame('+5,0 %', $cartes[3]['repere']);
        self::assertSame('ok', $cartes[3]['repere_code']);
    }

    public function test_baisse_ca_repere_attention_et_code_net_independant(): void
    {
        $actuel = DashboardScenario::chiffres(900.0, 250.0, 40.0, 80.0); // net 170
        $precedent = DashboardScenario::chiffres(1000.0, 300.0, 42.0, 100.0); // net 200
        $cartes = Cartes::construire($actuel, $precedent, true, true, []);

        self::assertSame('−10,0 % vs période précédente', $cartes[0]['repere']);
        self::assertSame('attention', $cartes[0]['repere_code']);

        // Résultat net positif en baisse : repère négatif mais code « ok » (signe ≥ 0).
        self::assertSame('−15,0 % vs période précédente', $cartes[2]['repere']);
        self::assertSame('ok', $cartes[2]['repere_code']);
    }

    public function test_sans_precedent_fiable_aucun_repere(): void
    {
        $cartes = Cartes::construire(self::actuel(), self::precedent(), false, true, []);

        self::assertNull($cartes[0]['repere']);
        self::assertSame('neutre', $cartes[0]['repere_code']);
        self::assertNull($cartes[1]['repere']);
        self::assertNull($cartes[2]['repere']);
        self::assertSame('ok', $cartes[2]['repere_code']); // net ≥ 0, même sans comparaison
        self::assertNull($cartes[3]['repere']);
        self::assertSame('neutre', $cartes[3]['repere_code']);
    }

    public function test_periode_incomplete_ligne2_null_et_aucun_repere(): void
    {
        $cartes = Cartes::construire(self::actuel(), self::precedent(), true, false, []);

        self::assertNull($cartes[1]['ligne2']);
        self::assertNull($cartes[0]['ligne2']);
        self::assertNull($cartes[2]['ligne2']);
        self::assertNull($cartes[3]['ligne2']);
        // Règle de mission « aucun % sur période incomplète » : le repère
        // ne dépend plus du seul précédent, la période doit être terminée.
        self::assertNull($cartes[0]['repere']);
        self::assertNull($cartes[2]['repere']);
        self::assertNull($cartes[3]['repere']);
        self::assertSame('neutre', $cartes[0]['repere_code']);
        self::assertSame('neutre', $cartes[3]['repere_code']);
        // Le code de la carte net reste lié au signe, pas à la comparaison.
        self::assertSame('ok', $cartes[2]['repere_code']);
    }

    public function test_net_negatif_alerte_meme_en_hausse(): void
    {
        $actuel = DashboardScenario::chiffres(500.0, 300.0, 10.0, 400.0); // net −100
        $precedent = DashboardScenario::chiffres(400.0, 100.0, 8.0, 300.0); // net −200
        $cartes = Cartes::construire($actuel, $precedent, true, true, []);

        self::assertSame('−100,00 €', $cartes[2]['valeur']);
        self::assertSame('+50,0 % vs période précédente', $cartes[2]['repere']);
        self::assertSame('alerte', $cartes[2]['repere_code']);
    }

    public function test_precedent_nul_pas_de_base_de_comparaison(): void
    {
        $actuel = DashboardScenario::chiffres(500.0, 200.0, 10.0, 50.0);
        $precedent = DashboardScenario::chiffres(0.0, 0.0, 0.0, 0.0);
        $cartes = Cartes::construire($actuel, $precedent, true, true, []);

        self::assertNull($cartes[0]['repere']);
        self::assertNull($cartes[2]['repere']);
        self::assertNull($cartes[3]['repere']);
        self::assertSame('ok', $cartes[2]['repere_code']); // net 150 ≥ 0
    }

    public function test_format_fr_milliers_et_virgule(): void
    {
        $actuel = DashboardScenario::chiffres(1234.56, 617.28, 1234.0, 0.0);
        $cartes = Cartes::construire($actuel, null, false, true, []);

        self::assertSame('1 234,56 €', $cartes[0]['valeur']);
        self::assertSame('617,28 €', $cartes[1]['valeur']);
        self::assertSame('Sur 1 € vendu, on garde 0,50 €', $cartes[1]['ligne2']);
        self::assertSame('617,28 €', $cartes[2]['valeur']); // aucune dépense : net = profit = 617,28
        self::assertSame('1 234', $cartes[3]['valeur']);
    }

    public function test_marge_negative_sur_un_euro(): void
    {
        $actuel = DashboardScenario::chiffres(1000.0, -50.0, 10.0, 0.0);
        $cartes = Cartes::construire($actuel, null, false, true, []);

        self::assertSame('Sur 1 € vendu, on garde −0,05 €', $cartes[1]['ligne2']);
        self::assertSame('alerte', $cartes[2]['repere_code']); // net −50
    }
}
