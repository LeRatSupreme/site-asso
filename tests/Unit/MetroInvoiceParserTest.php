<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\MetroInvoiceParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests du parseur de factures METRO (logique pure, sans DB ni OCR).
 *
 * La fixture est la transcription réelle d'une facture METRO Calais
 * (13 lignes produits, totaux HT/TTC cohérents).
 *
 * @phpstan-import-type Invoice from \App\Core\Compta\MetroInvoiceParser
 */
final class MetroInvoiceParserTest extends TestCase
{
    public function test_parse_fixture_complete(): void
    {
        $r = MetroInvoiceParser::parse($this->fixture());

        // En-tête de facture.
        self::assertSame('METRO', $r['supplier']);
        self::assertSame('0/0(087)0054/033871', $r['invoice_number']);
        self::assertSame('2026-10-02', $r['purchased_at']);
        self::assertSame(5.5, $r['vat_rate']);
        self::assertSame('ht', $r['amount_basis']);
        self::assertSame(250.46, $r['total_ht']);
        self::assertSame(264.24, $r['total_ttc']);

        // 13 lignes produits (les « PRIX AU KG », totaux et mentions sont ignorés).
        self::assertCount(13, $r['lines']);

        $minute = $this->lineByLabel($r['lines'], 'MINUTE MAID');
        self::assertNotNull($minute);
        self::assertSame('5449000340085', $minute['ean']);
        self::assertSame('3162401', $minute['article']);
        self::assertSame(0.72, $minute['unit_price']);
        self::assertSame(24, $minute['units']);
        self::assertSame(17.28, $minute['total']);
        self::assertSame('EAN 5449000340085 · art. 3162401', $minute['notes']);

        $monster = $this->lineByLabel($r['lines'], 'MONSTER ULTRA');
        self::assertNotNull($monster);
        self::assertSame(24, $monster['units'], 'Monster : colisage 12 × qté 2 = 24 unités.');
        self::assertSame(30.44, $monster['total']);

        $redBullIce = $this->lineByLabel($r['lines'], 'RED BULL ICE');
        self::assertNotNull($redBullIce);
        self::assertSame(48, $redBullIce['units'], 'Red Bull Ice : colisage 24 × qté 2 = 48 unités.');
        self::assertSame(59.16, $redBullIce['total']);

        $cristali = $this->lineByLabel($r['lines'], 'CRISTALI');
        self::assertNotNull($cristali);
        self::assertSame(96, $cristali['units'], 'Cristali : colisage 24 × qté 4 = 96 unités.');
        self::assertSame(14.88, $cristali['total']);

        $nutella = $this->lineByLabel($r['lines'], 'NUTELLA');
        self::assertNotNull($nutella);
        self::assertSame(3, $nutella['units'], 'Nutella : colisage 1 × qté 3 = 3 unités.');
        self::assertSame(8.07, $nutella['total']);

        // Σ des montants de lignes = Total H.T. de la facture (242,39 + 8,07).
        $sum = 0.0;
        foreach ($r['lines'] as $line) {
            $sum += (float) $line['total'];
        }
        self::assertEqualsWithDelta(250.46, $sum, 0.001);
    }

    public function test_parse_variante_degradee_ocr(): void
    {
        $fixture = $this->fixture();
        // Prix unitaire lu « O,720 » au lieu de « 0,720 » (1re ligne)…
        $degraded = str_replace('0,720', 'O,720', $fixture);
        // … marqueur TVA « B » perdu sur la ligne FANTA…
        $degraded = str_replace('13,92 B P', '13,92 P', $degraded);
        // … et doubles espaces un peu partout (artefact OCR typique).
        $degraded = str_replace(' ', '  ', $degraded);

        $r = MetroInvoiceParser::parse($degraded);

        // Aucun fatal, la quasi-totalité des lignes est récupérée.
        self::assertGreaterThanOrEqual(11, count($r['lines']));
        self::assertNotSame([], $r['warnings'], 'Des avertissements sont attendus sur une variante dégradée.');
        foreach ($r['lines'] as $line) {
            self::assertGreaterThanOrEqual(1, $line['units']);
            self::assertGreaterThan(0.0, $line['total']);
        }
    }

    public function test_texte_sans_facture_renvoie_lignes_vides_et_warnings(): void
    {
        $r = MetroInvoiceParser::parse("bonjour ceci n'est pas une facture");

        self::assertSame([], $r['lines']);
        self::assertNotSame([], $r['warnings']);
        self::assertNull($r['supplier']);
        self::assertNull($r['invoice_number']);
        self::assertNull($r['purchased_at']);

        // Texte vide : même comportement, sans exception.
        $vide = MetroInvoiceParser::parse('');
        self::assertSame([], $vide['lines']);
        self::assertNotSame([], $vide['warnings']);
    }

    public function test_mise_en_attente_facturee_n_est_pas_un_numero_de_facture(): void
    {
        // « facturée » ≠ « N° FACTURE » : le token qui suit ne doit jamais
        // être pris pour le numéro de la facture courante.
        $r = MetroInvoiceParser::parse(
            "METRO\nMise en attente rappelée et facturée 0/0(087)0051/023171 (051-033371)\n"
        );

        self::assertNull($r['invoice_number']);
    }

    public function test_numero_facture_nettoie_les_erreurs_ocr(): void
    {
        // O et puces de note ① collées : normalisés/retirés dans le token.
        $r = MetroInvoiceParser::parse(
            "METRO\nN° FACTURE O/0(087)0054/033871① (054-052687)\n"
        );

        self::assertSame('0/0(087)0054/033871', $r['invoice_number']);
    }

    public function test_taux_tva_non_reconnu_renvoie_null_avec_warning(): void
    {
        $r = MetroInvoiceParser::parse("METRO\n250,46 B = 7,00% 13,78 264,24\n");

        self::assertNull($r['vat_rate']);
        self::assertNotSame([], $r['warnings']);
    }

    public function test_date_invalide_renvoie_null(): void
    {
        $r = MetroInvoiceParser::parse("METRO\nDate facture : 32-13-2026\n");

        self::assertNull($r['purchased_at']);
    }

    public function test_ligne_degradee_sans_colisage_deduit_la_quantite(): void
    {
        // Ligne sans colisage/qté lisibles : unités = total ÷ prix unitaire,
        // avec avertissement « quantité déduite ».
        $r = MetroInvoiceParser::parse(
            "METRO\n5449000340085 3162401 JUS DE POMME 0,720 17,28 B\n"
        );

        self::assertCount(1, $r['lines']);
        self::assertSame(24, $r['lines'][0]['units']);
        self::assertSame(0.72, $r['lines'][0]['unit_price']);
        self::assertSame(17.28, $r['lines'][0]['total']);
        self::assertNotSame([], $r['warnings']);
    }

    public function test_ligne_incoherente_genere_un_warning(): void
    {
        // 24 × 1 × 0,720 = 17,28 attendu, mais 19,99 affiché sur la facture.
        $r = MetroInvoiceParser::parse(
            "METRO\n5449000340085 3162401 MINUTE MAID POMME 0,720 24 1 19,99 B\n"
        );

        self::assertCount(1, $r['lines']);
        $incoherent = false;
        foreach ($r['warnings'] as $warning) {
            if (str_contains($warning, 'somme incohérente')) {
                $incoherent = true;
            }
        }
        self::assertTrue($incoherent, 'Un avertissement « somme incohérente » est attendu.');
    }

    /**
     * Facture réelle multi-taux (fixture facture_3) : les lettres METRO ne
     * suivent PAS le standard (B = 5,5 %, D = 20 %) — la table TVA imprimée
     * fait foi.
     */
    public function test_facture_multi_taux_deux_lettres(): void
    {
        $r = MetroInvoiceParser::parse($this->fixture('metro_invoice_mixed_vat.txt'));

        // En-tête : le n° de la facture courante, pas le « Mise en attente
        // rappelée et facturée 0/0(087)0051/010203 » (leurre).
        self::assertSame('METRO', $r['supplier']);
        self::assertSame('0/0(087)0051/013502', $r['invoice_number']);
        self::assertSame('2026-06-03', $r['purchased_at']);
        self::assertSame(165.32, $r['total_ht']);
        self::assertSame('ht', $r['amount_basis']);

        // 11 lignes produits (PRIX AU KG, totaux *** et mentions ignorés).
        self::assertCount(11, $r['lines']);

        // Table TVA : 2 entrées, ordre d'apparition, colonnes complètes.
        self::assertCount(2, $r['vat_rates']);
        self::assertSame([
            'letter'    => 'B',
            'rate'      => 5.5,
            'base_ht'   => 153.57,
            'vat'       => 8.45,
            'total_ttc' => 162.02,
        ], $r['vat_rates'][0]);
        self::assertSame([
            'letter'    => 'D',
            'rate'      => 20.0,
            'base_ht'   => 11.75,
            'vat'       => 2.35,
            'total_ttc' => 14.10,
        ], $r['vat_rates'][1]);

        // Taux global impossible (2 taux) : null + avertissement explicite.
        self::assertNull($r['vat_rate']);
        $multiple = false;
        foreach ($r['warnings'] as $warning) {
            if (str_contains($warning, 'TVA multiple')) {
                $multiple = true;
                self::assertStringContainsString('5,5 % (base 153,57)', $warning);
                self::assertStringContainsString('20 % (base 11,75)', $warning);
                self::assertStringContainsString('à répartir manuellement', $warning);
            }
        }
        self::assertTrue($multiple, 'Un avertissement « TVA multiple » est attendu.');

        // Lettre de ligne → taux via la table (B = 5,5 %, D = 20 % chez METRO).
        $oasis = $this->lineByLabel($r['lines'], 'OASIS TROPICAL');
        self::assertNotNull($oasis);
        self::assertSame('B', $oasis['vat_letter']);
        self::assertSame(5.5, $oasis['vat_rate']);
        self::assertSame(6, $oasis['units']);

        $mpro = $this->lineByLabel($r['lines'], 'MPRO');
        self::assertNotNull($mpro);
        self::assertSame('D', $mpro['vat_letter']);
        self::assertSame(20.0, $mpro['vat_rate']);
        self::assertSame(6.05, $mpro['total']);

        // Total TTC : « Total à payer » prioritaire.
        self::assertSame(176.12, $r['total_ttc']);
    }

    /**
     * Facture mono-taux (fixture historique) : vat_rate global rétrocompatible
     * et table TVA lue en une entrée complète (base HT, TVA, TTC).
     */
    public function test_facture_mono_taux_avec_table_complete(): void
    {
        $r = MetroInvoiceParser::parse($this->fixture());

        self::assertSame(5.5, $r['vat_rate']);
        self::assertSame([
            [
                'letter'    => 'B',
                'rate'      => 5.5,
                'base_ht'   => 250.46,
                'vat'       => 13.78,
                'total_ttc' => 264.24,
            ],
        ], $r['vat_rates']);
        self::assertSame(264.24, $r['total_ttc']);
    }

    /**
     * Lignes portant des lettres TVA mais table illisible (photo coupée) :
     * chaque lettre est signalée et le taux global reste null.
     */
    public function test_lettres_tva_sans_table_genere_warnings(): void
    {
        $r = MetroInvoiceParser::parse(
            "METRO\n5449000340085 3162401 MINUTE MAID 0,720 24 1 17,28 B\n"
            . "5449000032607 2023125 COCA COLA 0,634 24 1 15,21 D\n"
        );

        self::assertCount(2, $r['lines']);
        self::assertNull($r['vat_rate']);
        self::assertSame([], $r['vat_rates']);

        $warnings = implode(' | ', $r['warnings']);
        self::assertStringContainsString('Lettre TVA "B" sans taux lu', $warnings);
        self::assertStringContainsString('Lettre TVA "D" sans taux lu', $warnings);
        self::assertStringContainsString('table coupée sur la photo', $warnings);
    }

    /**
     * Contenu d'une fixture (transcription réelle d'une facture METRO).
     */
    private function fixture(string $name = 'metro_invoice.txt'): string
    {
        $content = file_get_contents(__DIR__ . '/../Fixtures/' . $name);
        self::assertNotFalse($content, 'Fixture tests/Fixtures/' . $name . ' introuvable.');

        return $content;
    }

    /**
     * Retrouve une ligne extraite par préfixe de libellé.
     *
     * @param list<array<string,mixed>> $lines
     *
     * @return array<string,mixed>|null
     */
    private function lineByLabel(array $lines, string $prefix): ?array
    {
        foreach ($lines as $line) {
            if (str_starts_with((string) $line['label'], $prefix)) {
                return $line;
            }
        }

        return null;
    }
}
