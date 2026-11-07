<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Core\Compta\Dashboard\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * Verdict::construire — règles déterministes dans l'ordre de la mission :
 * période incomplète → neutre sans % ; précédent non fiable → neutre ;
 * 0 vente ; base de comparaison nulle ; tendance (hausse / baisse légère
 * ≥ seuil alerte / forte baisse) ; objectif de CA après la tendance ;
 * seuil d'alerte surchargeable via « dashboard_baisse_alerte_pct ».
 */
final class VerdictTest extends TestCase
{
    public function test_hausse_du_resultat_net(): void
    {
        $v = Verdict::construire(500.0, 400.0, true, true, 1000.0, null, []);

        self::assertSame('ok', $v['code']);
        self::assertStringContainsString('progresse', $v['phrase']);
        self::assertStringContainsString('+25,0 %', $v['phrase']);
        self::assertNull($v['objectif_phrase']);
    }

    public function test_baisse_legere_exactement_moins_10_pour_cent(): void
    {
        $v = Verdict::construire(360.0, 400.0, true, true, 1000.0, null, []);

        self::assertSame('attention', $v['code']);
        self::assertStringContainsString('baisse', $v['phrase']);
        self::assertStringContainsString('−10,0 %', $v['phrase']);
    }

    public function test_forte_baisse_moins_27_5_pour_cent(): void
    {
        $v = Verdict::construire(290.0, 400.0, true, true, 1000.0, null, []);

        self::assertSame('alerte', $v['code']);
        self::assertStringContainsString('−27,5 %', $v['phrase']);
    }

    public function test_periode_incomplete_neutre_sans_pourcentage(): void
    {
        $v = Verdict::construire(500.0, 400.0, true, false, 1000.0, 500.0, []);

        self::assertSame('neutre', $v['code']);
        self::assertSame('Période en cours : pas encore de verdict.', $v['phrase']);
        self::assertStringNotContainsString('%', $v['phrase']);
        self::assertNull($v['objectif_phrase']);
    }

    public function test_periode_incomplete_avec_libelle(): void
    {
        $v = Verdict::construire(500.0, 400.0, true, false, 1000.0, null, [], '7 derniers jours');

        self::assertSame('neutre', $v['code']);
        self::assertSame('Période en cours (7 derniers jours) : pas encore de verdict.', $v['phrase']);
    }

    public function test_resultat_manquant_reviens_periode_en_cours(): void
    {
        $v = Verdict::construire(null, 400.0, true, true, 1000.0, null, []);

        self::assertSame('neutre', $v['code']);
        self::assertSame('Période en cours : pas encore de verdict.', $v['phrase']);
    }

    public function test_precedent_incomplet_neutre_avec_objectif(): void
    {
        $v = Verdict::construire(360.0, 400.0, false, true, 400.0, 500.0, []);

        self::assertSame('neutre', $v['code']);
        self::assertSame('Pas de comparaison fiable avec la période précédente (données incomplètes).', $v['phrase']);
        self::assertSame('Objectif de CA : 80,0 % atteint (400,00 € sur 500,00 €).', $v['objectif_phrase']);
    }

    public function test_aucune_vente_avec_depenses(): void
    {
        $v = Verdict::construire(-50.0, 100.0, true, true, 0.0, null, []);

        self::assertSame('alerte', $v['code']);
        self::assertSame('Aucune vente sur la période, et des dépenses : le résultat net est négatif (−50,00 €).', $v['phrase']);
    }

    public function test_aucune_vente_sans_depenses(): void
    {
        $v = Verdict::construire(0.0, 100.0, true, true, 0.0, null, []);

        self::assertSame('neutre', $v['code']);
        self::assertSame('Aucune vente sur la période.', $v['phrase']);
    }

    public function test_base_de_comparaison_nulle_net_positif(): void
    {
        $v = Verdict::construire(200.0, 0.0, true, true, 400.0, null, []);

        self::assertSame('ok', $v['code']);
        self::assertSame('Résultat net de 200,00 € (pas de base de comparaison).', $v['phrase']);
        self::assertStringNotContainsString('%', $v['phrase']);
    }

    public function test_base_de_comparaison_nulle_net_negatif(): void
    {
        $v = Verdict::construire(-30.0, 0.0, true, true, 400.0, null, []);

        self::assertSame('alerte', $v['code']);
        self::assertSame('Dépenses supérieures à la marge : résultat net négatif (−30,00 €).', $v['phrase']);
    }

    public function test_base_de_comparaison_nulle_net_nul_stable(): void
    {
        $v = Verdict::construire(0.0, 0.0, true, true, 400.0, null, []);

        self::assertSame('neutre', $v['code']);
        self::assertSame('Résultat net stable (0,00 €).', $v['phrase']);
    }

    public function test_variation_nulle_resultat_stable(): void
    {
        $v = Verdict::construire(400.0, 400.0, true, true, 1000.0, null, []);

        self::assertSame('neutre', $v['code']);
        self::assertSame('Résultat net stable (400,00 €).', $v['phrase']);
    }

    public function test_objectif_non_atteint_degrade_ok_en_attention(): void
    {
        $v = Verdict::construire(500.0, 400.0, true, true, 400.0, 500.0, []);

        self::assertSame('attention', $v['code']);
        self::assertSame('Objectif de CA : 80,0 % atteint (400,00 € sur 500,00 €).', $v['objectif_phrase']);
    }

    public function test_objectif_atteint_conserve_le_code(): void
    {
        $v = Verdict::construire(500.0, 400.0, true, true, 500.0, 500.0, []);

        self::assertSame('ok', $v['code']);
        self::assertSame('Objectif de CA atteint (500,00 € sur 500,00 €).', $v['objectif_phrase']);
    }

    public function test_objectif_non_atteint_ne_ameliore_pas_un_code_deja_orange_rouge(): void
    {
        $v = Verdict::construire(360.0, 400.0, true, true, 400.0, 500.0, []);

        self::assertSame('attention', $v['code']);
        self::assertSame('Objectif de CA : 80,0 % atteint (400,00 € sur 500,00 €).', $v['objectif_phrase']);
    }

    public function test_objectif_masque_si_periode_incomplete(): void
    {
        $v = Verdict::construire(500.0, 400.0, true, false, 400.0, 500.0, []);

        self::assertNull($v['objectif_phrase']);
    }

    public function test_seuil_alerte_surchargeable(): void
    {
        $v = Verdict::construire(290.0, 400.0, true, true, 1000.0, null, ['dashboard_baisse_alerte_pct' => -50]);
        self::assertSame('attention', $v['code']);

        $v2 = Verdict::construire(290.0, 400.0, true, true, 1000.0, null, ['dashboard_baisse_alerte_pct' => '-50']);
        self::assertSame('attention', $v2['code']);
    }

    public function test_seuil_alerte_surcharge_borne_inclusive(): void
    {
        // −10,0 % avec seuil surchargé à −10 : borne incluse → attention (pas alerte).
        $v = Verdict::construire(360.0, 400.0, true, true, 1000.0, null, ['dashboard_baisse_alerte_pct' => -10]);
        self::assertSame('attention', $v['code']);
    }
}
