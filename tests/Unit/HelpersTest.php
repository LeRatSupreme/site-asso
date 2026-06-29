<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests des fonctions utilitaires globales (helpers.php).
 */
final class HelpersTest extends TestCase
{
    public function test_e_echappe_le_html(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            e('<script>alert(1)</script>')
        );
    }

    public function test_e_echappe_les_quotes(): void
    {
        self::assertSame(
            '&quot;test&quot; &apos;ok&apos;',
            e('"test" \'ok\'')
        );
    }

    public function test_format_date_au_format_francais(): void
    {
        self::assertSame('28/06/2026', formatDate('2026-06-28 10:00:00'));
        self::assertSame('28/06/2026', formatDate('2026-06-28'));
    }

    public function test_format_date_avec_format_personnalise(): void
    {
        self::assertSame('2026-06', formatDate('2026-06-28', 'Y-m'));
    }

    public function test_format_date_renvoie_chaine_vide_si_nul(): void
    {
        self::assertSame('', formatDate(null));
        self::assertSame('', formatDate(''));
    }

    public function test_format_date_time(): void
    {
        self::assertSame('28/06/2026 10:30', formatDateTime('2026-06-28 10:30:00'));
    }

    public function test_format_price_format_euro_francais(): void
    {
        self::assertSame('1,50 €', formatPrice(1.5));
        self::assertSame('0,99 €', formatPrice('0.99'));
        self::assertSame('1 200,00 €', formatPrice(1200));
    }

    public function test_format_price_valeur_nulle(): void
    {
        self::assertSame('0,00 €', formatPrice(null));
        self::assertSame('0,00 €', formatPrice(''));
    }

    public function test_parse_french_float_avec_virgule(): void
    {
        self::assertSame(1.75, parseFrenchFloat('1,75'));
        self::assertSame(0.99, parseFrenchFloat('0,99'));
    }

    public function test_parse_french_float_avec_point(): void
    {
        self::assertSame(1.75, parseFrenchFloat('1.75'));
    }

    public function test_parse_french_float_avec_separateur_milliers(): void
    {
        self::assertSame(1234.5, parseFrenchFloat('1 234,5'));
    }

    public function test_parse_french_float_chaine_vide(): void
    {
        self::assertSame(0.0, parseFrenchFloat(''));
    }
}
