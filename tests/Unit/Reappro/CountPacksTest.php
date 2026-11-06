<?php

declare(strict_types=1);

namespace Tests\Unit\Reappro;

use App\Core\Compta\Reappro\CountPacks;
use App\Core\Compta\Reappro\PackResolver;
use PHPUnit\Framework\TestCase;

/**
 * Fusion « packs + unités » d'une ligne de comptage d'inventaire : la
 * conversion est serveur, en un seul point, via le PackResolver partagé
 * (exacte → normalisée → alias — jamais de map[strtolower] ad hoc,
 * règle AGENTS.md). inventory_counts reste en unités.
 */
final class CountPacksTest extends TestCase
{
    public function test_conversion_exacte_deux_packs_plus_trois(): void
    {
        $r = new PackResolver(['Redbull Ice' => 24], []);

        $out = CountPacks::merge('Redbull Ice', '3', '2', $r);

        self::assertSame(51, $out['counted'], '2 packs de 24 + 3 unités = 51 unités comptées.');
        self::assertSame('2×24+3', $out['note'], 'La note trace la saisie d\'origine.');
    }

    public function test_packs_seuls_unites_vides(): void
    {
        $r = new PackResolver(['Redbull Ice' => 24], []);

        $out = CountPacks::merge('redbull ice', '', '2', $r);

        self::assertSame(48, $out['counted']);
        self::assertSame('2×24', $out['note'], 'Sans unité isolée, la note s\'arrête au pack.');
    }

    public function test_pack_resolu_sous_graphie_differentes(): void
    {
        // Pack saisi sous la clé « RedBull Ice » (Réappro), comptage saisi
        // sous une autre graphie : les passes du résolveur doivent trouver
        // le pack (régression « stock introuvable » du 22/10/2026).
        $r = new PackResolver(['RedBull Ice' => 24], []);

        self::assertSame(24, CountPacks::merge('redbull ice', '0', '1', $r)['counted'], 'Casse ignorée.');
        self::assertSame(24, CountPacks::merge('RedBull - ICE', '0', '1', $r)['counted'], 'Ponctuation/espacements repliés.');
    }

    public function test_pack_rapproche_par_alias(): void
    {
        // Pack sur la clé canonique, comptage saisi sous le libellé de vente.
        $r = new PackResolver(
            ['Pepsi sans sucre' => 6],
            [['raw_description' => 'pepsi Zero', 'product_key' => 'Pepsi sans sucre']]
        );

        $out = CountPacks::merge('pepsi Zero', '2', '1', $r);

        self::assertSame(8, $out['counted'], '1 pack de 6 + 2 unités = 8.');
        self::assertSame('1×6+2', $out['note']);
    }

    public function test_sans_pack_champ_packs_ignore_silencieusement(): void
    {
        // Résolveur = 0 : comportement strictement identique au comptage
        // d'unités seules d'avant les packs (D4).
        $r = new PackResolver([], []);

        $out = CountPacks::merge('Snack', '7', '5', $r);

        self::assertSame(7, $out['counted'], 'Le champ packs ne doit rien ajouter sans pack défini.');
        self::assertNull($out['note']);
    }

    public function test_taille_un_ignoree(): void
    {
        // Un « pack de 1 » ne change rien : ignoré comme un pack absent.
        $r = new PackResolver(['Unitaire' => 1], []);

        $out = CountPacks::merge('unitaire', '7', '3', $r);

        self::assertSame(7, $out['counted']);
        self::assertNull($out['note']);
    }

    public function test_saisie_packs_parasite_ignoree(): void
    {
        $r = new PackResolver(['Redbull Ice' => 24], []);

        self::assertSame(7, CountPacks::merge('redbull ice', '7', '', $r)['counted'], 'Champ vide → unités seules.');
        self::assertNull(CountPacks::merge('redbull ice', '7', '', $r)['note']);
        self::assertSame(7, CountPacks::merge('redbull ice', '7', '-2', $r)['counted'], 'Packs négatifs ignorés.');
        self::assertSame(7, CountPacks::merge('redbull ice', '7', 'abc', $r)['counted'], 'Texte non numérique ignoré.');
    }

    public function test_unites_seules_comportement_inchange(): void
    {
        // Champ packs absent du POST : exactement le comptage d'avant.
        $r = new PackResolver(['Redbull Ice' => 24], []);

        $out = CountPacks::merge('redbull ice', '5', '', $r);

        self::assertSame(5, $out['counted']);
        self::assertNull($out['note'], 'Pas de note parasite sans pack saisi.');
    }
}
