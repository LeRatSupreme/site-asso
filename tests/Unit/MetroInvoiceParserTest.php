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

        // TOUTES les lignes sont récupérées (colisage relibéré entre le PU
        // et le montant, lettres TVA tolérantes), sans aucun avertissement.
        self::assertCount(13, $r['lines']);
        foreach ($r['lines'] as $line) {
            self::assertGreaterThanOrEqual(1, $line['units']);
            self::assertGreaterThan(0.0, $line['total']);
        }
        $sum = 0.0;
        foreach ($r['lines'] as $line) {
            $sum += (float) $line['total'];
        }
        self::assertEqualsWithDelta(250.46, $sum, 0.001);
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
     * PHOTO RÉELLE (facture_2, OCR Tesseract psm 6 + 2e passe) : le tableau
     * est bien lu, une ligne sur huit perdue (montant illisible), en-têtes
     * complets y compris le n° légèrement corrompu par l'OCR.
     */
    public function test_ocr_reel_photo_facture_2(): void
    {
        $r = MetroInvoiceParser::parse($this->fixture('metro_ocr_psm6_facture_2.txt'));

        self::assertSame('METRO', $r['supplier']);
        self::assertSame('0/0(087)0054/020883', $r['invoice_number']);
        self::assertSame('2026-09-01', $r['purchased_at']);
        self::assertSame(5.5, $r['vat_rate']);
        self::assertSame(179.84, $r['total_ht']);
        self::assertSame(189.73, $r['total_ttc']);

        // 7 lignes sur 8 (RED BULL PEACH : montant illisible sur la photo).
        self::assertCount(7, $r['lines']);
        $ice = $this->lineByLabel($r['lines'], 'RED BULL ICE');
        self::assertNotNull($ice);
        self::assertSame(48, $ice['units']);
        self::assertSame(50.28, $ice['total']);

        $sum = 0.0;
        foreach ($r['lines'] as $line) {
            $sum += (float) $line['total'];
        }
        self::assertEqualsWithDelta(150.26, $sum, 0.001);
        self::assertTrue($this->hasWarning($r['warnings'], 'des lignes ont pu être manquées'));
    }

    /**
     * PHOTO RÉELLE (facture_1, photo sombre, tableau éclaté) : 15 lignes sur
     * 20 récupérées par réappariement tête/colonnes, lettres B et D lues,
     * table TVA coupée → taux null + avertissements, n° de facture complété
     * par la 2e passe OCR, Total H.T. tronqué sur la photo → null.
     */
    public function test_ocr_reel_photo_facture_1(): void
    {
        $r = MetroInvoiceParser::parse($this->fixture('metro_ocr_psm6_facture_1.txt'));

        self::assertSame('METRO', $r['supplier']);
        self::assertSame('0/0(087)0054/032004', $r['invoice_number']);
        self::assertSame('2026-09-18', $r['purchased_at']);
        self::assertNull($r['total_ht']);
        self::assertNull($r['total_ttc']);
        self::assertNull($r['vat_rate']);

        self::assertCount(15, $r['lines']);
        $ultra = $this->lineByLabel($r['lines'], 'D M OM');
        self::assertNotNull($ultra, 'MONSTER ULTRA : montant entier « 4566 » validé par PU×colisage×qté.');
        self::assertSame(36, $ultra['units']);
        self::assertSame(45.66, $ultra['total']);

        $letters = [];
        foreach ($r['lines'] as $line) {
            if ($line['vat_letter'] !== null) {
                $letters[$line['vat_letter']] = true;
            }
        }
        self::assertSame(['B' => true, 'D' => true], $letters, 'Lettres B et D lues sur les lignes.');

        $sum = 0.0;
        foreach ($r['lines'] as $line) {
            $sum += (float) $line['total'];
        }
        self::assertEqualsWithDelta(227.20, $sum, 0.001);
    }

    /**
     * PHOTO RÉELLE (image_a_scan, ticket METRO scanné) : 9 lignes sur 13,
     * n° et date récupérés (la date par la 2e passe OCR), totaux HT/TTC
     * exacts et taux 5,5 % confirmé par la table TVA partiellement lue.
     */
    public function test_ocr_reel_photo_image_a_scan(): void
    {
        $r = MetroInvoiceParser::parse($this->fixture('metro_ocr_psm6_image_a_scan.txt'));

        self::assertSame('METRO', $r['supplier']);
        self::assertSame('0/0(087)0054/033871', $r['invoice_number']);
        self::assertSame('2026-10-02', $r['purchased_at']);
        self::assertSame(5.5, $r['vat_rate']);
        self::assertSame(250.46, $r['total_ht']);
        self::assertSame(264.24, $r['total_ttc']);

        self::assertCount(9, $r['lines']);
        $nutella = $this->lineByLabel($r['lines'], 'NUTELLA');
        self::assertNotNull($nutella);
        self::assertSame(3, $nutella['units']);
        self::assertSame(8.07, $nutella['total']);

        $sum = 0.0;
        foreach ($r['lines'] as $line) {
            $sum += (float) $line['total'];
        }
        self::assertEqualsWithDelta(118.60, $sum, 0.001);
        self::assertTrue($this->hasWarning($r['warnings'], 'des lignes ont pu être manquées'));
    }

    /**
     * PHOTO RÉELLE (facture_3, photo très dégradée) : en-têtes sauvés (date,
     * n° partiel, TTC via « Total à payer »), TVA mixte B/D non résoluble
     * (table illisible) → null + avertissements, quelques lignes seulement.
     */
    public function test_ocr_reel_photo_facture_3(): void
    {
        $r = MetroInvoiceParser::parse($this->fixture('metro_ocr_psm6_facture_3.txt'));

        self::assertSame('METRO', $r['supplier']);
        self::assertSame('2026-06-03', $r['purchased_at']);
        self::assertSame(176.12, $r['total_ttc']);
        self::assertStringContainsString('005/013502', (string) $r['invoice_number']);
        self::assertNull($r['vat_rate']);
        self::assertTrue($this->hasWarning($r['warnings'], 'non résolues par la table des taux'));
        self::assertGreaterThanOrEqual(4, count($r['lines']));
    }

    /**
     * Lignes dégradées relevées sur les vraies photos (bench OCR) : PU
     * entier à 4 chiffres dont la virgule est perdue (« 1268 » = 1,268),
     * déchets OCR après le montant (« 30, 44 É ») et bloc produit à plus
     * de six mots parasites avant les colonnes — extraites sans inventer
     * les montants (validation arithmétique 12 × 2 × 1,268 ≈ 30,44).
     */
    public function test_lignes_ocr_pu_quatre_chiffres_dechets_et_prefixe_long(): void
    {
        $text = "METRO\n"
            . "Date facture : 03-06-2026\n"
            . "06 05284 3 JONSTER ULTRA ZEROMBTE 50CL 1268 12 2 30, 44 É\n"
            . "pu Ut Base Cut SE GENE OIL E 0.477 30 1 14,31 B P\n"
            . "Total H.T. : 44,75\n";

        $r = MetroInvoiceParser::parse($text);

        $ultra = $this->lineByLabel($r['lines'], '06 05284 3 JONSTER');
        self::assertNotNull($ultra, 'PU « 1268 » (virgule perdue) + déchet « É » : la ligne doit être extraite.');
        self::assertSame(24, $ultra['units'], 'Colisage 12 × qté 2, PAS colisage 1268.');
        self::assertSame(1.268, $ultra['unit_price']);
        self::assertSame(30.44, $ultra['total']);

        $coca = $this->lineByLabel($r['lines'], 'pu Ut Base');
        self::assertNotNull($coca, 'Bloc à 8 mots parasites avant les colonnes : la ligne doit être extraite.');
        self::assertSame(30, $coca['units']);
        self::assertSame(14.31, $coca['total']);
        self::assertSame('B', $coca['vat_letter']);
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

    /** Un avertissement contenant ce fragment est-il présent ? */
    private function hasWarning(array $warnings, string $fragment): bool
    {
        foreach ($warnings as $warning) {
            if (str_contains($warning, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
