<?php

declare(strict_types=1);

namespace App\Core\Compta;

/**
 * Aiguillage des documents fournisseur : facture METRO ou ticket de caisse.
 *
 * Un seul point d'entrée pour les deux scanneurs (achats ET dépenses) :
 *  - un texte qui ressemble à une facture METRO (logo METRO + vocabulaire
 *    spécifique : FACTURE, BRASSERIE, Colisage, Désignation…) est confié à
 *    MetroInvoiceParser (structure « ht », table TVA multi-taux) ;
 *  - tout le reste est traité comme un ticket thermique par
 *    TicketReceiptParser (structure « ttc », tolérante aux tickets tronqués).
 *
 * La sortie est le contrat « invoice » partagé avec le frontend, enrichi de
 * la clé kind ('metro' | 'ticket').
 */
final class InvoiceParser
{
    /**
     * Analyse un document et renvoie la structure « invoice » typée par kind.
     *
     * Ne lève jamais d'exception métier : les deux parseurs renvoient des
     * avertissements explicites et des structures vides sur une entrée
     * inexploitable.
     *
     * @return array{
     *     kind:'metro'|'ticket', supplier:?string, invoice_number:?string,
     *     purchased_at:?string, vat_rate:?float, amount_basis:'ht'|'ttc',
     *     total_ht:?float, total_ttc:?float, vat_rates:mixed[],
     *     lines:list<array<string,mixed>>, warnings:list<string>
     * }
     */
    public static function parse(string $text): array
    {
        if (preg_match('/METRO/i', $text) === 1
            && preg_match('/FACTURE|BRASSERIE|Colisage|D[ée]signation/i', $text) === 1
        ) {
            $invoice = MetroInvoiceParser::parse($text);
            $invoice['kind'] = 'metro';

            return $invoice;
        }

        return TicketReceiptParser::parse($text);
    }
}
