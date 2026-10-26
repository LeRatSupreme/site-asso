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

    /**
     * « TOTAL GÉNÉRAL 12,50 € » : libellé élargi reconnu (accent
     * neutralisé, symbole € ignoré), même avec la ligne de paiement
     * derrière.
     */
    public function test_libelle_total_general_avec_symbole_euro(): void
    {
        $text = <<<'TXT'
PICARD
Ticket : 31415
le 21/09/2026
LASAGNES 7,20
ECLAIRS 5,30
TOTAL GÉNÉRAL 12,50 €
CARTE 12,50
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame(12.50, $r['total_ttc'], '« TOTAL GÉNÉRAL » avec « € » : libellé fort reconnu.');
        self::assertNotContains('Total non étiqueté — valeur la plus probable retenue, vérifie le montant.', $r['warnings']);
    }

    /**
     * « A PAYER 9.99EUR » : point décimal + suffixe EUR collé.
     */
    public function test_libelle_a_payer_avec_point_decimal_et_suffixe_eur(): void
    {
        $text = <<<'TXT'
MONOPRIX
Ticket : 27182
le 08/10/2026
CAFE LONG 9,99
A PAYER 9.99EUR
CB 9,99
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame(9.99, $r['total_ttc'], '« A PAYER » avec « 9.99EUR » (point décimal, EUR collé).');
    }

    /**
     * « MONTANT DÛ 4,50 » : libellé fort sans « = » (seule la forme
     * « MONTANT = X » de la zone paiement reste prioritaire absolue).
     */
    public function test_libelle_montant_du_sans_egal(): void
    {
        $text = <<<'TXT'
FRANPRIX
Ticket : 3355
le 09/10/2026
CAFE 250G 4,50
MONTANT DÛ 4,50
CB 4,50
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame(4.50, $r['total_ttc'], '« MONTANT DÛ » (sans « = ») est une ligne de total forte.');
    }

    /**
     * Milliers à espace fin : « 1 250,00 » lu 1250,00 — pas « 250,00 ».
     */
    public function test_montants_milliers_avec_espace_lus_entierement(): void
    {
        $text = <<<'TXT'
BRICOMARCHE
Ticket : 90807
le 17/10/2026
PERCEUSE 1 250,00 A
TOTAL A PAYER 1 250,00
CB 1 250,00
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame(1250.00, $r['total_ttc'], '« 1 250,00 » (espace des milliers) ≠ « 250,00 ».');
        self::assertSame(20.0, $r['vat_rate'], 'Lettre A de la ligne produit → 20 %.');
        self::assertSame(1041.67, $r['total_ht'], 'HT = 1250,00 ÷ 1,20.');
    }

    /**
     * OCR : « 12,5O » — la lettre O collée aux chiffres est un zéro.
     */
    public function test_o_majuscule_lu_pour_zero_dans_les_montants(): void
    {
        $text = <<<'TXT'
DECATHLON
Ticket : 11041
le 05/10/2026
GOURDE 12,5O
TOTAL TTC 12,5O
CB 12,5O
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame(12.50, $r['total_ttc'], '« 12,5O » → 12,50 (O majuscule mal lu pour 0).');
    }

    /**
     * « TOTAL » puis zone de paiement (CB avec billet, monnaie rendue) :
     * le TOTAL avant les paiements fait foi — ni le montant payé, ni le
     * rendu ; SOUS-TOTAL et REMISE ne sont pas des totaux à payer.
     */
    public function test_total_avant_paiement_sous_total_et_rendu_ecartes(): void
    {
        $text = <<<'TXT'
NETTO
Ticket : 58433
le 14/10/2026
EAU 1,5L 0,89
CHIPS 2,10
SOUS-TOTAL 2,99
REMISE 1,00
TOTAL 1,99
CB 5,00
RENDU 3,01
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame(1.99, $r['total_ttc'], 'TOTAL avant CB : ni le SOUS-TOTAL (2,99), ni la REMISE, ni « CB 5,00 », ni le RENDU (3,01).');
    }

    /**
     * Ticket sans AUCUNE ligne de total étiquetée (cas de la plainte :
     * transcription « CB 15,64 » seule) : heuristique du dernier
     * recours — le montant le plus plausible est retenu, TOUJOURS avec
     * l'avertissement exact demandé.
     */
    public function test_sans_ligne_total_dernier_recours_avec_warning(): void
    {
        $text = <<<'TXT'
KIABI
Ticket : 60606
le 10/10/2026 a 18:22
TSHIRT ADULTE 15,64
CB 15,64
TICKET DU 10.10.2026 18:22
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame(15.64, $r['total_ttc'], 'Dernier recours : « CB 15,64 » retenu plutôt que null.');
        self::assertContains(
            'Total non étiqueté — valeur la plus probable retenue, vérifie le montant.',
            $r['warnings'],
            'Le dernier recours est toujours signalé, mot pour mot.'
        );
    }

    /**
     * Ticket TTC sans AUCUN détail de TVA (ni lettres A-E, ni ligne
     * « TVA x % ») : état NORMAL — aucun taux ni HT inventé, simple
     * avertissement informatif.
     */
    public function test_ttc_sans_tva_detaillee_aucune_tva_inventee(): void
    {
        $text = <<<'TXT'
ACTION
Ticket : 77001
le 03/10/2026
EAU 0,89
SACS 0,30
TOTAL A PAYER 1,19
CB 1,19
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame(1.19, $r['total_ttc']);
        self::assertNull($r['vat_rate'], 'TVA jamais estimée quand rien n\'est imprimé.');
        self::assertNull($r['total_ht'], 'Pas de HT calculé sans taux.');
        self::assertContains(
            'TVA non détaillée sur ce ticket — montant TTC seul, taux à saisir manuellement si besoin.',
            $r['warnings'],
            'Avertissement informatif exact.'
        );
    }

    /**
     * « dont TVA 20% 1,67 » : taux explicitement imprimé (sans lettres
     * de classe) → taux global + HT déduit ; la ligne n'est ni un total
     * ni un produit.
     */
    public function test_dont_tva_imprimee_extraite_meme_sans_lettres_de_classe(): void
    {
        $text = <<<'TXT'
PAUL
Ticket : 4455
le 06/10/2026
SANDWICH 10,00
BOISSON 2,00
TOTAL A PAYER 12,00
dont TVA 20% 1,67
CB 12,00
TXT;

        $r = TicketReceiptParser::parse($text);

        self::assertSame(12.00, $r['total_ttc']);
        self::assertSame(20.0, $r['vat_rate'], '« dont TVA 20% » imprimé → taux retenu (jamais deviné).');
        self::assertSame(10.00, $r['total_ht'], 'HT = 12,00 ÷ 1,20.');
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
     * PHOTO RÉELLE (ticket Auchan photo dégradée, OCR psm 6 + 2e passe) :
     * seule la partie paiement est visible — montant lu, numéro récupéré
     * depuis la 2e passe OCR (« 1Cket : 67647 » y est redevenu « Ticket »),
     * ligne parasite du téléphone écartée.
     */
    public function test_ocr_reel_photo_auchan(): void
    {
        $r = TicketReceiptParser::parse($this->fixture('ticket_ocr_psm6_auchan.txt'));

        self::assertSame('ticket', $r['kind']);
        self::assertSame('AUCHAN', $r['supplier']);
        self::assertSame('67647', $r['invoice_number']);
        self::assertSame('2026-08-31', $r['purchased_at']);
        self::assertSame(37.24, $r['total_ttc'], '« MONTANT= 37,24 EUR » fait foi.');
        self::assertSame([], $r['lines'], 'Partie produits non visible sur la photo.');
        self::assertNull($r['total_ht']);
        self::assertNull($r['vat_rate']);
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
