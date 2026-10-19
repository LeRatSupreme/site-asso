<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\OperationsTabs;
use PHPUnit\Framework\TestCase;

/**
 * Tests purs du mapping des onglets de la page fusionnée
 * « Opérations » (OperationsTabs) : « pertes » (journal des pertes) et
 * « inventaire » (relevé tablette SumUp) partagent le MÊME onglet de
 * niveau 1 « Pertes / Inventaire » — la fusion demandée, les deux
 * pages restant distinctes (sous-onglets de niveau 2).
 *
 * Classe pure (aucun accès POST/DB) : consommée par la vue
 * admin/compta/operations.php.
 */
final class OperationsTabsTest extends TestCase
{
    /**
     * La fusion : les deux sections allument le même onglet, sans
     * lequel la vue afficherait deux onglets distincts (comportement
     * d'avant la fusion).
     */
    public function test_pertes_et_inventaire_partagent_le_meme_onglet(): void
    {
        self::assertSame(
            OperationsTabs::tabFor(OperationsTabs::SECTION_PERTES),
            OperationsTabs::tabFor(OperationsTabs::SECTION_INVENTAIRE),
            '« pertes » et « inventaire » = un seul bloc « Pertes / Inventaire ».'
        );
        self::assertSame(OperationsTabs::TAB_PERTES, OperationsTabs::tabFor('pertes'));
        self::assertSame(OperationsTabs::TAB_PERTES, OperationsTabs::tabFor('inventaire'));
    }

    /**
     * Les autres sections gardent chacune leur onglet propre.
     */
    public function test_sections_historiques_onglets_propres(): void
    {
        self::assertSame(OperationsTabs::TAB_ACHATS, OperationsTabs::tabFor('achats'));
        self::assertSame(OperationsTabs::TAB_DEPENSES, OperationsTabs::tabFor('depenses'));
        self::assertSame(OperationsTabs::TAB_EVENEMENTS, OperationsTabs::tabFor('evenements'));
    }

    /**
     * Seule « inventaire » est une sous-section (pas d'onglet de
     * niveau 1 propre dans la barre principale) — la vue s'appuie sur
     * ce test pour ne PAS rendre d'onglet « Inventaire » séparé.
     */
    public function test_inventaire_est_l_unique_sous_section(): void
    {
        self::assertTrue(OperationsTabs::isSubSection('inventaire'));
        foreach (['achats', 'depenses', 'pertes', 'evenements'] as $section) {
            self::assertFalse(OperationsTabs::isSubSection($section), $section . ' garde son onglet propre.');
        }
    }

    /**
     * Le mapping couvre exactement les cinq sections de la page
     * (celles de la vue operations.php) — une section oubliée ici
     * retomberait silencieusement sur « achats ».
     */
    public function test_mapping_couvre_les_cinq_sections(): void
    {
        self::assertSame(
            ['achats', 'depenses', 'pertes', 'inventaire', 'evenements'],
            array_keys(OperationsTabs::tabOf())
        );
    }

    /**
     * Repli : section inconnue → « achats » (même repli que la vue
     * pour une section invalide) ; une sous-section inconnue n'est
     * jamais traitée comme telle.
     */
    public function test_section_inconnue_repli_achats(): void
    {
        self::assertSame(OperationsTabs::TAB_ACHATS, OperationsTabs::tabFor('nexiste_pas'));
        self::assertSame(OperationsTabs::TAB_ACHATS, OperationsTabs::tabFor(''));
        self::assertFalse(OperationsTabs::isSubSection('nexiste_pas'));
    }
}
