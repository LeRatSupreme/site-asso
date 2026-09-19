<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\StockPublic;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'appariement carte publique ↔ stock théorique d'inventaire.
 *
 * Logique pure (normalisation + règles de correspondance) : aucun accès
 * base ni disque, conformément à la contrainte « jamais de fatal sur la
 * page d'accueil publique ».
 */
final class StockPublicTest extends TestCase
{
    public function test_match_exact_insensible_a_la_casse_aux_espaces_et_accents(): void
    {
        $map = ['redbull' => 23, 'cafeglace' => 4];

        self::assertSame(23, StockPublic::stockForMenuProduct('Red Bull', $map));
        self::assertSame(23, StockPublic::stockForMenuProduct('REDBULL', $map));
        self::assertSame(23, StockPublic::stockForMenuProduct('red-bull', $map));
        self::assertSame(4, StockPublic::stockForMenuProduct('Café Glacé', $map));
    }

    public function test_inclusion_cle_contenue_dans_le_nom(): void
    {
        // « Coca-Cola 33cl » normalisé = « cocacola33cl » ⊃ « coca » (4 car.).
        $map = ['coca' => 12];

        self::assertSame(12, StockPublic::stockForMenuProduct('Coca-Cola 33cl', $map));
    }

    public function test_inclusion_nom_contenu_dans_la_cle(): void
    {
        // « Bueno » ⊂ « Kinder Bueno » (clé inventaire plus précise que le nom).
        $map = ['kinderbueno' => 7];

        self::assertSame(7, StockPublic::stockForMenuProduct('Bueno', $map));
    }

    public function test_aiguilles_trop_courtes_rejetees_pour_eviter_les_faux_positifs(): void
    {
        // « bn » (2 car. < 4) ne doit pas matcher « Biscuit BN » par inclusion…
        self::assertNull(StockPublic::stockForMenuProduct('Biscuit BN', ['bn' => 3]));
        // … ni une clé courte incluse dans un nom plus long.
        self::assertNull(StockPublic::stockForMenuProduct('Eau de source', ['eau' => 9]));
    }

    public function test_match_le_plus_long_gagne_en_cas_d_ambiguite(): void
    {
        // « coca » et « cocacherry » sont tous deux inclus dans « Coca Cherry 33cl » :
        // la correspondance la plus longue doit l'emporter.
        $map = ['coca' => 5, 'cocacherry' => 2];

        self::assertSame(2, StockPublic::stockForMenuProduct('Coca Cherry 33cl', $map));
    }

    public function test_aucun_match_renvoie_null_pas_de_repli_sur_le_stock_produits(): void
    {
        self::assertNull(StockPublic::stockForMenuProduct('Produit Inconnu', ['redbull' => 23]));
        self::assertNull(StockPublic::stockForMenuProduct('Red Bull', []));
        self::assertNull(StockPublic::stockForMenuProduct('', ['redbull' => 23]));
    }

    public function test_quantite_negative_bornee_a_zero(): void
    {
        // Stock théorique négatif (survente/pertes non journalisées) → 0, jamais négatif.
        self::assertSame(0, StockPublic::stockForMenuProduct('Red Bull', ['redbull' => -3]));
        self::assertSame(0, StockPublic::stockForMenuProduct('Coca-Cola', ['coca' => -12]));
    }

    public function test_normalizekey_converge_les_variantes_de_libelle(): void
    {
        self::assertSame('redbull', StockPublic::normalizeKey('Red Bull'));
        self::assertSame('redbull', StockPublic::normalizeKey('RedBull'));
        self::assertSame('redbull', StockPublic::normalizeKey(' red-bull '));
        self::assertSame('cristaline', StockPublic::normalizeKey('CRISTALINE'));
        self::assertSame('cafeglace', StockPublic::normalizeKey('Café Glacé'));
        self::assertSame('', StockPublic::normalizeKey('  _-  '));
    }
}
