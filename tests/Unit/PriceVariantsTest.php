<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\PriceVariants;
use PHPUnit\Framework\TestCase;

/**
 * Tests purs de PriceVariants (variantes de produits distinguées par
 * prix à l'import) : la méthode variantFor() est déterministe et sans
 * aucune base de données. Couvre la règle métier du café : « cafe » à
 * 0,70 € = allongé (2 dosettes) → « cafe allonge » / « Spécial », et
 * les garde-fous : tolérance de prix, clé exacte uniquement.
 */
final class PriceVariantsTest extends TestCase
{
    /** Clé exacte « cafe » + prix exact 0,70 → variante allongé. */
    public function test_cle_exacte_prix_exact(): void
    {
        self::assertSame(
            ['key' => 'cafe allonge', 'category' => 'Spécial'],
            PriceVariants::variantFor('cafe', 0.70)
        );
    }

    /** Prix hors tolérance par le bas (0,69) : café simple, pas de variante. */
    public function test_prix_sous_tolerance_null(): void
    {
        self::assertNull(PriceVariants::variantFor('cafe', 0.69));
    }

    /** Prix hors tolérance par le haut (0,71) : pas de variante non plus. */
    public function test_prix_sur_tolerance_null(): void
    {
        self::assertNull(PriceVariants::variantFor('cafe', 0.71));
    }

    /** Autre clé au même prix : la règle ne s'applique qu'à « cafe ». */
    public function test_autre_cle_null(): void
    {
        self::assertNull(PriceVariants::variantFor('coca', 0.70));
    }

    /** Clé approchée (« cafe machin ») : correspondance EXACTE requise. */
    public function test_cle_approchee_null(): void
    {
        self::assertNull(PriceVariants::variantFor('cafe machin', 0.70));
    }

    /** Clé vide : aucun risque de faux positif. */
    public function test_cle_vide_null(): void
    {
        self::assertNull(PriceVariants::variantFor('', 0.70));
        self::assertNull(PriceVariants::variantFor('   ', 0.70));
    }

    /** Espaces superflus autour de la clé : trim avant comparaison. */
    public function test_espaces_autour_de_la_cle(): void
    {
        self::assertSame(
            ['key' => 'cafe allonge', 'category' => 'Spécial'],
            PriceVariants::variantFor(' Cafe ', 0.70)
        );
    }

    /** Casse quelconque : comparaison en minuscules. */
    public function test_casse_majuscules(): void
    {
        self::assertSame(
            ['key' => 'cafe allonge', 'category' => 'Spécial'],
            PriceVariants::variantFor('CAFE', 0.7)
        );
    }

    /** Borne basse de la tolérance (0,695) : toujours la variante. */
    public function test_borne_basse_tolerance(): void
    {
        self::assertSame(
            ['key' => 'cafe allonge', 'category' => 'Spécial'],
            PriceVariants::variantFor('cafe', 0.695)
        );
    }

    /** Borne haute de la tolérance (0,705) : toujours la variante. */
    public function test_borne_haute_tolerance(): void
    {
        self::assertSame(
            ['key' => 'cafe allonge', 'category' => 'Spécial'],
            PriceVariants::variantFor('cafe', 0.705)
        );
    }
}
