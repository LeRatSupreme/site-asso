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
        // Saisie Paris 10:30 → stockée UTC → affichée à nouveau 10:30.
        self::assertSame('28/06/2026 10:30', formatDateTime((string) parisToUtc('2026-06-28 10:30:00')));
        // Entrée brute UTC : 10:30 UTC = 12:30 Paris (juin = toujours UTC+2).
        self::assertSame('28/06/2026 12:30', formatDateTime('2026-06-28 10:30:00'));
    }

    public function test_format_date_time_convertit_utc_vers_paris_ete(): void
    {
        // 2026-09-22 = CEST (UTC+2, horaire d'été français) : 06:32 UTC → 08:32 Paris.
        self::assertSame('22/09/2026 08:32', formatDateTime('2026-09-22 06:32:00'));
        self::assertStringContainsString('08:32', formatDateTime('2026-09-22 06:32:00'));
    }

    public function test_utc_to_paris_chaine_vide_inchangee(): void
    {
        self::assertSame('', utcToParis(''));
        self::assertSame('', utcToParis('   '));
    }

    public function test_paris_to_utc_chaine_vide_ou_invalide_renvoie_null(): void
    {
        self::assertNull(parisToUtc(''));
        self::assertNull(parisToUtc('   '));
        self::assertNull(parisToUtc('garbage'));
    }

    public function test_aller_retour_paris_utc(): void
    {
        self::assertSame('2026-09-22 06:32:00', parisToUtc(utcToParis('2026-09-22 06:32:00')));
        self::assertSame('2026-09-22 08:32:00', utcToParis(parisToUtc('2026-09-22 08:32:00')));
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

        // La saisie murale (Paris, minuit) est stockée en UTC :
        // en septembre (CEST) minuit Paris = 22:00 UTC la veille.
        self::assertTrue($res['ok']);
        $year = (int) date('Y');
        self::assertSame(
            parisToUtc(sprintf('%04d-09-21 00:00:00', $year)),
            $res['value']
        );
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

    public function test_datetime_selects_value_borne_haute_parametrable_accepte_le_futur(): void
    {
        [$datePart] = explode(' ', date('Y-m-d H:i:s', strtotime('+2 years')));
        [$y, $m, $d] = explode('-', $datePart);
        $_POST = [
            'date_d' => $d,
            'date_m' => $m,
            'date_y' => $y,
        ];

        $res = datetime_selects_value('date', '+5 years');

        self::assertTrue($res['ok']);
        self::assertSame(
            parisToUtc(sprintf('%04d-%02d-%02d 00:00:00', (int) $y, (int) $m, (int) $d)),
            $res['value']
        );
    }

    public function test_datetime_selects_value_borne_par_defaut_rejete_le_futur_lointain(): void
    {
        [$datePart] = explode(' ', date('Y-m-d H:i:s', strtotime('+2 years')));
        [$y, $m, $d] = explode('-', $datePart);
        $_POST = [
            'date_d' => $d,
            'date_m' => $m,
            'date_y' => $y,
        ];

        $res = datetime_selects_value();

        self::assertFalse($res['ok']);
        self::assertNull($res['value']);
    }
}
