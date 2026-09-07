<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\SumUpCsvParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests du parseur CSV SumUp (logique pure, sans base de données).
 */
final class SumUpCsvParserTest extends TestCase
{
    private function parser(): SumUpCsvParser
    {
        return new SumUpCsvParser();
    }

    private function header(): string
    {
        return "Date,Type,Réf. transaction,Moyen de paiement,Quantité,Description,Catégorie,SKU,Devise,Prix avant réduction,Réduction,Prix (TTC),Prix (HT),TVA,Taux de TVA,Compte";
    }

    public function test_parse_french_date_sur_plusieurs_mois(): void
    {
        $p = $this->parser();

        self::assertSame('2026-06-01 09:59:00', $p->parseFrenchDate('1 juin 2026 09:59'));
        self::assertSame('2026-01-15 00:00:00', $p->parseFrenchDate('15 janvier 2026'));
        self::assertSame('2026-12-31 23:59:00', $p->parseFrenchDate('31 décembre 2026 23:59'));
        self::assertSame('2026-08-05 08:00:00', $p->parseFrenchDate('5 août 2026 08:00'));
        self::assertSame('2026-02-10 12:30:00', $p->parseFrenchDate('10 février 2026 12:30'));
    }

    public function test_parse_french_date_invalide_renvoie_null(): void
    {
        $p = $this->parser();

        self::assertNull($p->parseFrenchDate(''));
        self::assertNull($p->parseFrenchDate('not a date'));
        self::assertNull($p->parseFrenchDate('15 sploutch 2026'));
    }

    public function test_parse_french_date_mois_abreges(): void
    {
        $p = $this->parser();

        self::assertSame('2026-09-01 10:08:00', $p->parseFrenchDate('1 sept. 2026 10:08'));
        self::assertSame('2026-01-15 00:00:00', $p->parseFrenchDate('15 janv. 2026'));
        self::assertSame('2026-02-10 12:30:00', $p->parseFrenchDate('10 févr. 2026 12:30'));
        self::assertSame('2026-07-21 09:00:00', $p->parseFrenchDate('21 juil. 2026 09:00'));
        self::assertSame('2026-12-31 23:59:00', $p->parseFrenchDate('31 déc. 2026 23:59'));
    }

    public function test_parse_french_float_via_prix_ttc(): void
    {
        $csv = $this->header()
            . "\n1 juin 2026 10:27,Vente,T1,Visa - Débit,1,Bonbon,Nourriture,,EUR,\"0,5\",0,\"0,5\",\"0,5\",0,,Alex";

        $parsed = $this->parser()->parse($csv);

        self::assertCount(1, $parsed['rows']);
        self::assertSame(0.5, (float) $parsed['rows'][0]['price_ttc']);
        self::assertSame(0.5, (float) $parsed['rows'][0]['price_ht']);
    }

    public function test_normalise_paiement_carte_pour_visas_mastercard_amex(): void
    {
        $p = $this->parser();

        self::assertSame('CARTE', $p->normalizePayment('Visa - Débit'));
        self::assertSame('CARTE', $p->normalizePayment('Visa - Crédit'));
        self::assertSame('CARTE', $p->normalizePayment('Mastercard - Débit'));
        self::assertSame('CARTE', $p->normalizePayment('Mastercard - Crédit'));
        self::assertSame('CARTE', $p->normalizePayment('American Express - Crédit'));
    }

    public function test_normalise_paiement_liquide_pour_especes(): void
    {
        $p = $this->parser();

        self::assertSame('LIQUIDE', $p->normalizePayment('Espèces'));
        self::assertSame('LIQUIDE', $p->normalizePayment('espèces'));
    }

    public function test_detecte_montant_personnalise_fr_et_en(): void
    {
        $p = $this->parser();

        self::assertTrue($p->isCustomAmount('Montant personnalisé'));
        self::assertTrue($p->isCustomAmount('Custom amount'));
        self::assertFalse($p->isCustomAmount('Bueno'));
    }

    public function test_skip_lignes_non_vente(): void
    {
        $csv = $this->header()
            . "\n1 juin 2026 10:00,Vente,T1,Visa - Débit,1,Bueno,Nourriture,,EUR,1,0,1,1,0,,Alex"
            . "\n1 juin 2026 10:01,Remboursement,T2,Visa - Débit,1,Bueno,Nourriture,,EUR,1,0,1,1,0,,Alex"
            . "\n1 juin 2026 10:02,Remboursement,T3,Visa - Débit,1,Bueno,Nourriture,,EUR,1,0,1,1,0,,Alex";

        $parsed = $this->parser()->parse($csv);

        self::assertSame(1, $parsed['meta']['total'], 'Seules les ventes sont importées.');
    }

    public function test_is_custom_amount_marque_la_ligne(): void
    {
        $csv = $this->header()
            . "\n1 juin 2026 11:27,Vente,T1,Visa - Débit,1,Montant personnalisé,,,EUR,1,0,1,1,0,,Alex"
            . "\n1 juin 2026 12:32,Vente,T2,Visa - Débit,1,CocaCola,Boisson,,EUR,1,0,1,1,0,,Alex";

        $parsed = $this->parser()->parse($csv);

        self::assertSame(1, (int) $parsed['rows'][0]['is_custom_amount']);
        self::assertSame(0, (int) $parsed['rows'][1]['is_custom_amount']);
    }

    public function test_quantite_defaut_a_1_si_vide(): void
    {
        $csv = "Date,Type,Réf. transaction,Moyen de paiement,Quantité,Description,Prix (TTC)"
            . "\n1 juin 2026 10:00,Vente,T1,Visa - Débit,,Bueno,1";

        $parsed = $this->parser()->parse($csv);

        self::assertSame(1, (int) $parsed['rows'][0]['quantity']);
    }

    public function test_resolver_injecte_product_key(): void
    {
        $csv = $this->header()
            . "\n1 juin 2026 10:00,Vente,T1,Visa - Débit,1,Bueno_white,Nourriture,,EUR,1,0,1,1,0,,Alex";

        $resolver = static fn (string $d): ?string => $d === 'Bueno_white' ? 'Bueno' : null;
        $parsed = $this->parser()->parse($csv, $resolver);

        self::assertSame('Bueno', $parsed['rows'][0]['product_key']);
    }

    public function test_meta_periode_calculee(): void
    {
        $csv = $this->header()
            . "\n1 juin 2026 10:00,Vente,T1,Visa - Débit,1,Bueno,Nourriture,,EUR,1,0,1,1,0,,Alex"
            . "\n19 juin 2026 10:00,Vente,T2,Visa - Débit,1,CocaCola,Boisson,,EUR,1,0,1,1,0,,Alex";

        $parsed = $this->parser()->parse($csv);

        self::assertSame('2026-06-01', $parsed['meta']['period_start']);
        self::assertSame('2026-06-19', $parsed['meta']['period_end']);
        self::assertSame(2, $parsed['meta']['total']);
    }
}
