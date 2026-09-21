<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests des fonctions utilitaires globales (helpers.php).
 */
final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
    }

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

    public function test_format_price_avec_3_decimales(): void
    {
        self::assertSame('0,155 €', formatPrice(0.155, 3));
        self::assertSame('1,055 €', formatPrice('1.055', 3));
        self::assertSame('0,000 €', formatPrice(null, 3));
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

    public function test_datetime_selects_value_tout_vide_signifie_maintenant(): void
    {
        $res = datetime_selects_value();

        self::assertTrue($res['ok']);
        self::assertNull($res['value']);
    }

    public function test_datetime_selects_value_31_fevrier_invalide(): void
    {
        $_POST = [
            'date_d' => '31',
            'date_m' => '2',
            'date_y' => (string) (int) date('Y'),
        ];

        $res = datetime_selects_value();

        self::assertFalse($res['ok']);
        self::assertNull($res['value']);
    }

    public function test_datetime_selects_value_date_complete_sans_heure(): void
    {
        $_POST = [
            'date_d' => '21',
            'date_m' => '9',
            'date_y' => (string) (int) date('Y'),
        ];

        $res = datetime_selects_value();

        self::assertTrue($res['ok']);
        self::assertSame(date('Y-m-d', mktime(0, 0, 0, 9, 21, (int) date('Y'))) . ' 00:00:00', $res['value']);
    }

    public function test_datetime_selects_value_sans_jour_est_partiel(): void
    {
        $_POST = ['date_m' => '9'];

        $res = datetime_selects_value();

        self::assertFalse($res['ok']);
        self::assertNull($res['value']);
    }

    public function test_datetime_selects_value_hors_bornes_2019(): void
    {
        $_POST = [
            'date_d' => '1',
            'date_m' => '1',
            'date_y' => '2019',
        ];

        $res = datetime_selects_value();

        self::assertFalse($res['ok']);
        self::assertNull($res['value']);
    }
}
