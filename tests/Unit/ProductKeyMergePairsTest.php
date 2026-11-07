<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductKeyMerge;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la validation pure des paires du formulaire « Tout fusionner »
 * des doublons probables (ProductKeyMerge::validPairs) : indices appariés,
 * chaînes non vides ≤ 255 caractères, distinctes en comparaison brute
 * (même garde que merge()). Aucun accès base requis (méthode pure).
 */
final class ProductKeyMergePairsTest extends TestCase
{
    public function test_paires_valides_conservees_et_espaces_rognes(): void
    {
        $pairs = ProductKeyMerge::validPairs(
            [' Bowling ', 'Bueno White'],
            ['bowling', ' Bueno white ']
        );

        self::assertSame([
            ['from' => 'Bowling', 'to' => 'bowling'],
            ['from' => 'Bueno White', 'to' => 'Bueno white'],
        ], $pairs);
    }

    public function test_entrees_non_tableaux_refusees(): void
    {
        self::assertSame([], ProductKeyMerge::validPairs(null, null));
        self::assertSame([], ProductKeyMerge::validPairs(null, ['bowling']));
        self::assertSame([], ProductKeyMerge::validPairs('Bowling', ['bowling']));
        self::assertSame([], ProductKeyMerge::validPairs(['Bowling'], 42));
    }

    public function test_indices_non_apparies_ignores(): void
    {
        self::assertSame([], ProductKeyMerge::validPairs(['Bowling', 'Cristaline'], []));
        self::assertSame(
            [['from' => 'Bowling', 'to' => 'bowling']],
            ProductKeyMerge::validPairs(['Bowling', 'Cristaline'], ['bowling']),
            'Seuls les indices présents des DEUX côtés forment une paire.'
        );
    }

    public function test_chaines_vides_ou_non_scalaires_jetees(): void
    {
        self::assertSame([], ProductKeyMerge::validPairs(['', '   '], ['bowling', 'bowling']));
        self::assertSame([], ProductKeyMerge::validPairs(['Bowling'], ['']));
        self::assertSame([], ProductKeyMerge::validPairs([['x']], ['bowling']));
    }

    public function test_cle_trop_longue_jetee(): void
    {
        $long = str_repeat('a', 256);
        self::assertSame([], ProductKeyMerge::validPairs([$long], ['bowling']));
        self::assertSame([], ProductKeyMerge::validPairs(['Bowling'], [$long]));

        $ok = str_repeat('a', 255);
        self::assertSame(
            [['from' => $ok, 'to' => 'bowling']],
            ProductKeyMerge::validPairs([$ok], ['bowling']),
            '255 caractères (taille de la colonne product_key) passent.'
        );
    }

    public function test_paire_identique_jetee_variante_de_casse_acceptee(): void
    {
        // Comparaison BRUTE (même garde que merge()) : deux clés
        // strictement égales ne forment rien à fusionner…
        self::assertSame([], ProductKeyMerge::validPairs(['Bowling'], ['Bowling']));
        self::assertSame([], ProductKeyMerge::validPairs([' Bowling '], ['Bowling ']));

        // … mais consolider deux variantes de casse est légitime — c'est
        // le cas d'usage courant de la liste des doublons.
        self::assertSame(
            [['from' => 'Bowling', 'to' => 'BOWLING']],
            ProductKeyMerge::validPairs(['Bowling'], ['BOWLING'])
        );
    }
}
