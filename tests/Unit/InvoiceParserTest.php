<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\InvoiceParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests de l'aiguillage InvoiceParser : facture METRO vs ticket de caisse,
 * clé kind ajoutée au contrat « invoice » partagé avec le frontend.
 */
final class InvoiceParserTest extends TestCase
{
    public function test_facture_metro_renvoie_kind_metro(): void
    {
        $text = (string) file_get_contents(__DIR__ . '/../Fixtures/metro_invoice_mixed_vat.txt');
        $r = InvoiceParser::parse($text);

        self::assertSame('metro', $r['kind']);
        self::assertSame('METRO', $r['supplier']);
        self::assertSame('ht', $r['amount_basis']);
        self::assertSame('0/0(087)0051/013502', $r['invoice_number']);
        self::assertSame([], array_diff(
            ['kind', 'supplier', 'invoice_number', 'purchased_at', 'vat_rate', 'amount_basis',
                'total_ht', 'total_ttc', 'vat_rates', 'lines', 'warnings'],
            array_keys($r)
        ), 'Le contrat étendu est complet.');
    }

    public function test_ticket_auchan_renvoie_kind_ticket(): void
    {
        $text = (string) file_get_contents(__DIR__ . '/../Fixtures/ticket_auchan.txt');
        $r = InvoiceParser::parse($text);

        self::assertSame('ticket', $r['kind']);
        self::assertSame('AUCHAN', $r['supplier']);
        self::assertSame('ttc', $r['amount_basis']);
        self::assertSame(37.24, $r['total_ttc']);
    }

    public function test_texte_vide_renvoie_un_ticket_sans_exception(): void
    {
        $r = InvoiceParser::parse('');

        self::assertSame('ticket', $r['kind']);
        self::assertSame([], $r['lines']);
        self::assertNotSame([], $r['warnings']);
    }

    public function test_texte_hors_facture_renvoie_un_ticket(): void
    {
        // Ni logo METRO ni vocabulaire facture : aiguillé vers le ticket.
        $r = InvoiceParser::parse("bonjour ceci n'est pas un document exploitable");

        self::assertSame('ticket', $r['kind']);
        self::assertSame([], $r['lines']);
    }
}
