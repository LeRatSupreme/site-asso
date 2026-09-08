<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\AliasSuggester;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'heuristique de suggestion de clé canonique.
 *
 * On vérifie surtout la déterminisme et la cohérence des regroupements,
 * sans exiger une fusion parfaite de tous les libellés.
 */
final class AliasSuggesterTest extends TestCase
{
    public function test_bueno_white_regroupé_vers_bueno(): void
    {
        self::assertSame('bueno', AliasSuggester::suggest('Bueno_white'));
        self::assertSame('bueno', AliasSuggester::suggest('Bueno'));
        self::assertSame('bueno', AliasSuggester::suggest('bueno white'));
    }

    public function test_coca_cherry_variants_meme_cle(): void
    {
        self::assertSame(AliasSuggester::suggest('Coca_cherry'), AliasSuggester::suggest('Coca cherry'));
        self::assertSame('coca cherry', AliasSuggester::suggest('Coca_cherry'));
        self::assertSame('coca cherry', AliasSuggester::suggest('Coca cherry'));
    }

    public function test_monster_variantes_de_couleur_vers_le_radical(): void
    {
        // « Blanche » et « Bleue » sont des suffixes de couleur : on retombe
        // sur le radical « monster » (cohérent, déterministe).
        self::assertSame('monster', AliasSuggester::suggest('Monster Blanche'));
        self::assertSame('monster', AliasSuggester::suggest('Monster_Bleue'));
        self::assertSame('monster', AliasSuggester::suggest('Monster white'));
    }

    public function test_normalise_accents_et_casse(): void
    {
        self::assertSame('cristaline', AliasSuggester::suggest('CrIstaline'));
        self::assertSame('cristaline', AliasSuggester::suggest('CRISTALINE'));
        self::assertSame('cristaline', AliasSuggester::suggest('cristaline'));
    }

    public function test_normalise_separateurs_multiples(): void
    {
        self::assertSame('kit kat', AliasSuggester::suggest('Kit_Kat'));
        self::assertSame('kit kat', AliasSuggester::suggest('Kit-Kat'));
        self::assertSame('kit kat', AliasSuggester::suggest('Kit Kat'));
    }

    public function test_retire_parentheses(): void
    {
        self::assertSame('coca zero', AliasSuggester::suggest('Coca (zero)'));
    }

    public function test_chaine_vide(): void
    {
        self::assertSame('', AliasSuggester::suggest(''));
        self::assertSame('', AliasSuggester::suggest('   '));
    }

    public function test_conserve_les_suffixes_non_colorimetriques(): void
    {
        // « cherry » n'est pas un suffixe de couleur : il est conservé.
        self::assertSame('oasis tropical', AliasSuggester::suggest('Oasis Tropical'));
    }

    public function test_deterministe(): void
    {
        $libelles = ['Bueno_white', 'Coca_cherry', 'Monster Blanche', 'Cristaline', 'Kit_Kat'];
        foreach ($libelles as $lib) {
            self::assertSame(AliasSuggester::suggest($lib), AliasSuggester::suggest($lib));
        }
    }

    public function test_normalize_key_sans_retrait_de_suffixe(): void
    {
        // normalizeKey ne retire PAS les suffixes de couleur (contrairement
        // à suggest) : les variantes restent distinguables.
        self::assertSame('monster blanche', AliasSuggester::normalizeKey('Monster Blanche'));
        self::assertSame('monster bleue', AliasSuggester::normalizeKey('Monster_Bleue'));
        self::assertNotSame(AliasSuggester::normalizeKey('Monster Blanche'), AliasSuggester::normalizeKey('Monster Bleue'));
    }

    public function test_normalize_key_casse_et_accents(): void
    {
        self::assertSame('redbull peach', AliasSuggester::normalizeKey('Redbull Peach'));
        self::assertSame('redbull peach', AliasSuggester::normalizeKey('redbull Peach'));
        self::assertSame('redbull peach', AliasSuggester::normalizeKey('  REDBULL_Peach  '));
        self::assertSame('cristaline', AliasSuggester::normalizeKey('Cristaline'));
    }

    public function test_group_duplicates_case_et_accent(): void
    {
        $groups = AliasSuggester::groupDuplicates([
            'redbull Peach',
            'Redbull Peach',
            'Monster',
            'Cristaline',
        ]);

        self::assertCount(1, $groups);
        self::assertSame(['redbull Peach', 'Redbull Peach'], $groups[0]);
    }

    public function test_group_duplicates_variantes_distinctes_non_groupées(): void
    {
        // « Monster Blanche » et « Monster Bleue » sont deux variantes
        // légitimes : elles ne doivent PAS être signalées comme doublons.
        $groups = AliasSuggester::groupDuplicates([
            'Monster Blanche',
            'Monster Bleue',
            'Monster verte (energy)',
        ]);

        self::assertSame([], $groups);
    }

    public function test_group_duplicates_ignores_vides_et_uniques(): void
    {
        self::assertSame([], AliasSuggester::groupDuplicates(['Coca', 'Pepsi', '']));
        self::assertSame([], AliasSuggester::groupDuplicates([]));
    }
}
