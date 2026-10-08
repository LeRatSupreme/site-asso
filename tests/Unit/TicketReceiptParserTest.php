<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\TicketReceiptParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests du parseur de tickets de caisse (logique pure, sans DB ni OCR).
 *
 * La fixture ticket_auchan.txt est la transcription réelle d'un ticket
 * Auchan tronqué (seule la partie paiement visible sur la photo) — cas
 * NORMAL : lignes vides, avertissement informatif, jamais d'exception.
 */
final class TicketReceiptParserTest extends TestCase
{
    public function test_parse_ticket_auchan_tronque(): void
    {
        $r = TicketReceiptParser::parse($this->fixture('ticket_auchan.txt'));

        self::assertSame('ticket', $r['kind']);
        self::assertSame('AUCHAN', $r['supplier']);
        self::assertSame('67647', $r['invoice_number']);
        self::assertSame('2026-08-31', $r['purchased_at'], 'Date « Le 31 août 2026 » prioritaire sur « le 31/08/26 ».');
        self::assertSame(37.24, $r['total_ttc'], '« MONTANT= 37,24 EUR » fait foi.');
        self::assertSame('ttc', $r['amount_basis']);
        self::assertNull($r['total_ht']);
        self::assertNull($r['vat_rate']);
        self::assertSame([], $r['vat_rates']);
        self::assertSame([], $r['lines'], 'Ticket tronqué : aucune ligne produit, sans exception.');
        self::assertNotSame([], $r['warnings'], 'Avertissement informatif attendu (ticket tronqué).');
    }

    public function test_parse_ticket_complet_lignes_quantite_et_tva(): void
    {
        $text = <<<'TXT'
LIDL
12 RUE DE LA GARE
TICKET : 424242
le 05/09/2026 a 10:14
EAU MINERALE 1,5L 0,89 C
CROISSANTS 2 X 1,33 2,66 C
NETTOYANT SOL 9,99 A
SAC REUTILISABLE 2,10
TOTAL 15,64
TOTAL A PAYER 15,64
CARTE BANCAIRE 15,64
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame('ticket', $r['kind']);
        self::assertSame('LIDL', $r['supplier']);
        self::assertSame('424242', $r['invoice_number']);
        self::assertSame('2026-09-05', $r['purchased_at']);
        self::assertSame(15.64, $r['total_ttc'], '« TOTAL A PAYER » prioritaire sur « TOTAL ».');
        self::assertSame('ttc', $r['amount_basis']);

        // 4 lignes produits (totaux et paiement ignorés).
        self::assertCount(4, $r['lines']);

        $eau = $this->lineByLabel($r['lines'], 'EAU MINERALE');
        self::assertNotNull($eau);
        self::assertSame(1, $eau['units']);
        self::assertSame(0.89, $eau['unit_price']);
        self::assertSame(0.89, $eau['total']);
        self::assertSame('C', $eau['vat_letter']);
        self::assertSame(5.5, $eau['vat_rate']);

        $croissants = $this->lineByLabel($r['lines'], 'CROISSANTS');
        self::assertNotNull($croissants);
        self::assertSame(2, $croissants['units'], 'Quantité « 2 X 1,33 » lue.');
        self::assertSame(1.33, $croissants['unit_price']);
        self::assertSame(2.66, $croissants['total']);
        self::assertSame('C', $croissants['vat_letter']);
        self::assertSame(5.5, $croissants['vat_rate']);

        $nettoyant = $this->lineByLabel($r['lines'], 'NETTOYANT SOL');
        self::assertNotNull($nettoyant);
        self::assertSame(20.0, $nettoyant['vat_rate'], 'Classe standard A = 20 %.');

        $sac = $this->lineByLabel($r['lines'], 'SAC REUTILISABLE');
        self::assertNotNull($sac);
        self::assertNull($sac['vat_letter']);
        self::assertNull($sac['vat_rate']);

        // Taux multiples (5,5 % + 20 %) : pas de taux global, HT indisponible.
        self::assertNull($r['vat_rate']);
        self::assertNull($r['total_ht']);
        $warnings = implode(' | ', $r['warnings']);
        self::assertStringContainsString('TVA multiple', $warnings);
        self::assertStringContainsString('classes standard', $warnings);
        self::assertStringContainsString('à vérifier sur le ticket', $warnings);
    }

    public function test_parse_ticket_mono_taux_calcule_le_ht(): void
    {
        $text = <<<'TXT'
CARREFOUR
MARKET CALAIS
Ticket : 998877
Le 2 octobre 2026 à 18:30
EAU 1,5L 0,89 C
PAIN 350G 1,10 C
TOTAL A PAYER 1,99
CARTE BANCAIRE
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame('CARREFOUR', $r['supplier']);
        self::assertSame('2026-10-02', $r['purchased_at'], 'Date « Le 2 octobre 2026 » (mois texte).');
        self::assertSame(1.99, $r['total_ttc']);
        self::assertCount(2, $r['lines']);
        self::assertSame(5.5, $r['vat_rate'], 'Un seul taux distinct → taux global.');
        self::assertSame(1.89, $r['total_ht'], 'HT = TTC ÷ (1 + taux).');
        self::assertSame('ttc', $r['amount_basis']);
    }

    public function test_texte_sans_ticket_renvoie_structure_vide_et_warnings(): void
    {
        $r = TicketReceiptParser::parse('');

        self::assertSame('ticket', $r['kind']);
        self::assertNull($r['supplier']);
        self::assertNull($r['invoice_number']);
        self::assertNull($r['purchased_at']);
        self::assertNull($r['total_ttc']);
        self::assertSame([], $r['lines']);
        self::assertSame([], $r['vat_rates']);
        self::assertNotSame([], $r['warnings']);
    }

    public function test_fournisseur_marque_recherchee_sur_toutes_les_lignes(): void
    {
        // La marque peut apparaître plus bas (bord de photo illisible) :
        // elle prime sur la première ligne courte éligible au repli.
        $r = TicketReceiptParser::parse("MON MAGASIN\nTicket : 123\nBOUTIQUE LEROY MERLIN 62\n");

        self::assertSame('LEROY MERLIN', $r['supplier']);
        self::assertSame('123', $r['invoice_number']);
    }

    /**
     * Contenu d'une fixture.
     */
    private function fixture(string $name): string
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
