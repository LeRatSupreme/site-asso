<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Sale;
use PHPUnit\Framework\TestCase;

/**
 * Tests du repli alias de Sale::completeCategoriesFromAliases() — la
 * partie PURE de Sale::categoryByProduct() : complétion du map
 * clé → catégorie par les catégories du mapping des libellés
 * (product_aliases), sans accès base.
 *
 * Règle attendue : ventes > alias par clé canonique > alias par libellé
 * brut. Les trous restants (produit sans vente classée ni alias) restent
 * absents du map — l'appelant applique son libellé de repli.
 */
final class SaleCategoryAliasesTest extends TestCase
{
    public function test_les_catégories_issues_des_ventes_ne_sont_jamais_écrasées(): void
    {
        $map = ['twix' => 'Snacks', 'crunch' => 'Nourriture'];

        $res = Sale::completeCategoriesFromAliases(
            $map,
            ['twix' => 'Nourriture', 'crunch' => 'Boissons'],
            ['twix' => 'Spécial']
        );

        self::assertSame('Snacks', $res['twix']);
        self::assertSame('Nourriture', $res['crunch']);
    }

    public function test_alias_par_clé_canonique_comble_le_trou(): void
    {
        $res = Sale::completeCategoriesFromAliases([], ['crunch' => 'Nourriture'], []);

        self::assertSame(['crunch' => 'Nourriture'], $res);
    }

    public function test_alias_par_libellé_brut_comble_le_trou_si_pas_de_clé(): void
    {
        $res = Sale::completeCategoriesFromAliases([], [], ['pepsi' => 'Boissons']);

        self::assertSame(['pepsi' => 'Boissons'], $res);
    }

    public function test_alias_par_clé_canonique_passe_avant_le_libellé_brut(): void
    {
        $res = Sale::completeCategoriesFromAliases(
            [],
            ['twix' => 'Nourriture'],
            ['twix' => 'Snacks']
        );

        self::assertSame('Nourriture', $res['twix']);
    }

    public function test_sans_alias_le_map_est_rendu_inchangé(): void
    {
        $res = Sale::completeCategoriesFromAliases(['twix' => 'Snacks', 'divers' => 'Divers'], [], []);

        self::assertSame(['twix' => 'Snacks', 'divers' => 'Divers'], $res);
    }

    public function test_normalisation_casse_et_espaces_des_clés(): void
    {
        $res = Sale::completeCategoriesFromAliases(
            [],
            ['  RED BULL PEACH ' => '  Redbull  '],
            ['MINUTE MAID NECT POMME' => 'Boissons']
        );

        self::assertSame('Redbull', $res['red bull peach']);
        self::assertSame('Boissons', $res['minute maid nect pomme']);
    }

    public function test_les_entrées_vides_des_alias_sont_ignorées(): void
    {
        $res = Sale::completeCategoriesFromAliases(
            [],
            ['' => 'Boissons', 'nesquik' => '  '],
            ['' => 'Snacks', 'pepsi' => '']
        );

        self::assertArrayNotHasKey('nesquik', $res);
        self::assertArrayNotHasKey('pepsi', $res);
        self::assertArrayNotHasKey('', $res);
    }

    public function test_pureté_le_map_d_origine_n_est_pas_modifié(): void
    {
        $map = ['twix' => 'Snacks'];

        Sale::completeCategoriesFromAliases($map, ['crunch' => 'Nourriture'], ['pepsi' => 'Boissons']);

        self::assertSame(['twix' => 'Snacks'], $map);
    }
}
