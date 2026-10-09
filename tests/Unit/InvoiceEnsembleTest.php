<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\InvoiceEnsemble;
use App\Core\Compta\InvoiceOcr;
use App\Core\Compta\InvoiceParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la fusion ensembliste des OCR (InvoiceEnsemble) sur les
 * FIXTURES OCR RÉELLES du projet (sorties Tesseract de vraies photos) :
 *  - metro_ocr_psm6_image_a_scan.txt : 9 lignes lues sur 13 (psm 6) ;
 *  - metro_ocr_psm6_facture_2.txt    : 7 lignes lues sur 8 ;
 *  - metro_ocr_psm6_facture_3.txt    : photo très dégradée (ombre + bord) ;
 *  - metro_ocr_psm6_facture_1.txt    : 15 lignes lues sur 20 ;
 *  - ticket_ocr_psm6_auchan.txt      : ticket Auchan (paiement seul).
 *
 * Les variantes « v1 » synthétiques sont des dégradations RÉALISTES du
 * texte propre de la MÊME facture (doubles espaces, montant éclaté
 * « 15, 90 ») : elles simulent ce qu'une passe de prétraitement
 * ImageMagick lit différemment — la fusion doit compléter sans rien
 * inventer (aucune ligne absente des passes ne peut apparaître).
 *
 * @phpstan-import-type Invoice from \App\Core\Compta\MetroInvoiceParser
 */
final class InvoiceEnsembleTest extends TestCase
{
    // ————————————————————————————————————————————————————————————
    // Fusion sur données réelles : la ligne perdue d'une passe survit
    // ————————————————————————————————————————————————————————————

    /**
     * PHOTO RÉELLE (image_a_scan) + variante propre dégradée : 9 lignes
     * (psm 6) + les 4 manquantes (MINUTE MAID, RED BULL, MONSTER ULTRA,
     * RED BULL ICE lues par l'autre passe) = 13/13, totaux exacts, table
     * TVA complète, ordre du tableau restitué — sans aucun avertissement.
     */
    public function test_fusion_image_a_scan_complete_les_13_lignes(): void
    {
        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $this->fixture('metro_ocr_psm6_image_a_scan.txt')],
            ['name' => 'v1', 'text' => $this->degrade($this->fixture('metro_invoice.txt'), '15,90 B')],
        ]);

        self::assertSame(2, $r['variants']);
        self::assertSame(13, $r['groups']);
        self::assertSame(13, count($r['invoice']['lines']), '9 lignes (v0) complétées par les 4 de v1.');

        // En-têtes votés entre variantes.
        $inv = $r['invoice'];
        self::assertSame('METRO', $inv['supplier']);
        self::assertSame('0/0(087)0054/033871', $inv['invoice_number']);
        self::assertSame('2026-10-02', $inv['purchased_at']);
        self::assertSame(5.5, $inv['vat_rate']);
        self::assertSame(250.46, $inv['total_ht']);
        self::assertSame(264.24, $inv['total_ttc']);
        self::assertSame([[
            'letter'    => 'B',
            'rate'      => 5.5,
            'base_ht'   => 250.46,
            'vat'       => 13.78,
            'total_ttc' => 264.24,
        ]], $inv['vat_rates']);

        // Lignes retrouvées grâce à la fusion.
        $minute = $this->lineByLabel($inv['lines'], 'MINUTE MAID');
        self::assertNotNull($minute, 'Ligne lisible seulement dans v1 : elle doit survivre.');
        self::assertSame(17.28, $minute['total']);
        self::assertSame(24, $minute['units']);
        $ice = $this->lineByLabel($inv['lines'], 'RED BULL ICE');
        self::assertNotNull($ice, 'Ligne complète lisible seulement dans la passe alt de v0.');
        self::assertSame(59.16, $ice['total']);
        self::assertSame(48, $ice['units']);
        self::assertSame('9002490274658', $ice['ean']);

        // Rien d'inventé : exactement les 13 libellés de la facture, dans
        // l'ordre du tableau (vote majoritaire des positions).
        self::assertSame([
            'MINUTE MAID NECT POMME BTE33CL',
            'RED BULL BOITE 25CL',
            'MONSTER ULTRA ZERO BTE 50CL',
            'RED BULL ICE BOITE 25CL',
            'COCA SANS SUCRES 30X33CL OS',
            'COCA COLA BOITE FAT 33CL',
            'ORANGINA SLIM BOITE JAUN 33CL',
            'COCACOLA CHERRY SLIM 33CL',
            'PEPSI ZERO SLIM 33CL',
            'PEPSI REGULAR SLIM 33CL',
            'FANTA ORANGE BTE 33CL',
            'CRISTALI 50CL PET',
            'NUTELLA B READY T10',
        ], array_map(static fn (array $l): string => (string) $l['label'], $inv['lines']));

        // Σ lignes = Total H.T. : plus aucun « lignes manquées ».
        $sum = 0.0;
        foreach ($inv['lines'] as $line) {
            $sum += (float) $line['total'];
        }
        self::assertEqualsWithDelta(250.46, $sum, 0.001);
        self::assertSame([], $inv['warnings']);
    }

    /**
     * LIBELLÉ DU GROUPE (photo de biais, facture_3) : la variante qui
     * gagne au score (colonnes valides) peut avoir un libellé écrasé.
     * Un gagnant MAL LU (token fusionné « EVBOITE » ou salade OCR en
     * casse mélangée « oure CHURES Arc HdBen ») cède la place au
     * libellé le mieux lu des membres ; un gagnant au libellé sain
     * (multi-mots, capitales) reste seul juge — même face à un membre
     * au libellé plausible (mauvais produit apparié). Et le libellé
     * retenu est nettoyé des débris de colonnes (« 9 OASIS TROPICAL
     * 2,080 » -> « OASIS TROPICAL », lettre TVA finale retirée,
     * « NUTELLA B READY T10 » conserve son « B »).
     */
    public function test_libelle_du_groupe_prend_la_meilleure_lecture(): void
    {
        $v0 = "METRO\nN° FACTURE 0/0(087)0054/033871\nDate facture : 02-10-2026\n"
            . "5060517889869 1234567 oure CHURES Arc HdBen Lost Hole Sel 0,850 20 1 16,95 B\n"
            . "Total H.T. : 16,95\n";
        $v1 = "METRO\nN° FACTURE 0/0(087)0054/033871\nDate facture : 02-10-2026\n"
            . "5060517889869 1234567 MONSTER MANGO LOCO BOITE 50CL 0,850 20 1 16,95 B\n"
            . "Total H.T. : 16,95\n";

        // Gagnant en salade (casse mélangée) : la lecture en capitales
        // d'un membre prend le dessus.
        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $v0],
            ['name' => 'v1', 'text' => $v1],
        ]);
        self::assertSame(1, $r['groups']);
        self::assertSame('MONSTER MANGO LOCO BOITE 50CL', $r['invoice']['lines'][0]['label']);

        // Gagnant au token fusionné (un seul mot de contenu) : même
        // comportement, avec nettoyage de la lettre TVA finale.
        $v0Fused = "METRO\nN° FACTURE 0/0(087)0054/033871\nDate facture : 02-10-2026\n"
            . "3124488194017 1234567 EVBOITE 250 1239. 24 1 12,48 B\n"
            . "Total H.T. : 12,48\n";
        $v1Clean = "METRO\nN° FACTURE 0/0(087)0054/033871\nDate facture : 02-10-2026\n"
            . "3124488194017 1234567 9 OASIS TROPICAL 2,080 0,520 24 1 12,48 B\n"
            . "Total H.T. : 12,48\n";
        $r2 = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $v0Fused],
            ['name' => 'v1', 'text' => $v1Clean],
        ]);
        self::assertSame(1, $r2['groups']);
        self::assertSame('OASIS TROPICAL', $r2['invoice']['lines'][0]['label'], 'Débris de tête/chiffres/lettre TVA nettoyés.');
    }

    /**
     * PHOTO RÉELLE (facture_2) : RED BULL PEACH n'a ses colonnes lisibles
     * que dans la passe psm par défaut — deux variantes (psm 6 seule, alt
     * seule) fusionnées = 8/8 lignes et Σ lignes = Total H.T. exact.
     */
    public function test_fusion_facture_2_la_ligne_de_la_passe_alt_survit(): void
    {
        $passes = $this->passes($this->fixture('metro_ocr_psm6_facture_2.txt'));

        // Seule, la passe psm 6 perd RED BULL PEACH (montant illisible).
        $solo = InvoiceEnsemble::consolidate([['name' => 'v0', 'text' => $passes[0]]]);
        self::assertNull($this->lineByLabel($solo['invoice']['lines'], 'RED BULL PEACH'));

        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $passes[0]],
            ['name' => 'v1', 'text' => $passes[1]],
        ]);

        self::assertSame(8, $r['groups']);
        $inv = $r['invoice'];
        self::assertSame('METRO', $inv['supplier']);
        self::assertSame('0/0(087)0054/020883', $inv['invoice_number']);
        self::assertSame('2026-09-01', $inv['purchased_at']);
        self::assertSame(5.5, $inv['vat_rate']);
        self::assertSame(179.84, $inv['total_ht']);
        self::assertSame(189.73, $inv['total_ttc']);
        self::assertSame([[
            'letter'    => 'B',
            'rate'      => 5.5,
            'base_ht'   => 179.84,
            'vat'       => 9.89,
            'total_ttc' => 189.73,
        ]], $inv['vat_rates']);

        $peach = $this->lineByLabel($inv['lines'], 'RED BULL PEACH');
        self::assertNotNull($peach, 'La ligne de la passe alt doit survivre à la fusion.');
        self::assertSame(29.58, $peach['total']);
        self::assertSame(24, $peach['units']);

        // Σ lignes = Total H.T. exact : plus d'avertissement « manquées ».
        $sum = 0.0;
        foreach ($inv['lines'] as $line) {
            $sum += (float) $line['total'];
        }
        self::assertEqualsWithDelta(179.84, $sum, 0.001);
        self::assertSame([], $inv['warnings']);

        // Ordre : celui du tableau photographié (PEACH à sa place).
        self::assertSame(
            'RED BULL ABRICOT 25CL',
            (string) $inv['lines'][0]['label']
        );
        self::assertSame('RED BULL PEACH BTE 25CL', (string) $inv['lines'][2]['label']);
    }

    /**
     * PHOTO RÉELLE (facture_3, ombre + bord coupé) : 4 lignes seulement
     * lues et table TVA coupée — la fusion avec la variante propre rétablit
     * les 11 lignes et la table TVA mixte B/D complète (vérité terrain :
     * B = 5,5 % base 153,57, TVA 8,45 ; D = 20 % base 11,75, TVA 2,35).
     */
    public function test_fusion_facture_3_retablit_les_11_lignes_et_la_tva_mixte(): void
    {
        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $this->fixture('metro_ocr_psm6_facture_3.txt')],
            ['name' => 'v1', 'text' => $this->degrade($this->fixture('metro_invoice_mixed_vat.txt'), '8,25 B')],
        ]);

        self::assertSame(11, $r['groups']);
        $inv = $r['invoice'];
        self::assertSame('METRO', $inv['supplier']);
        self::assertSame('0/0(087)0051/013502', $inv['invoice_number']);
        self::assertSame('2026-06-03', $inv['purchased_at']);
        self::assertSame(165.32, $inv['total_ht']);
        self::assertSame(176.12, $inv['total_ttc']);

        // Table TVA mixte : les DEUX entrées complètes, lettres B et D.
        $byLetter = [];
        foreach ($inv['vat_rates'] as $entry) {
            $byLetter[$entry['letter']] = $entry;
        }
        self::assertSame(['B', 'D'], array_keys($byLetter), 'La lettre parasite « E » (lecture dégradée) ne doit pas dupliquer le 5,5 %.');
        self::assertSame([
            'letter'    => 'B',
            'rate'      => 5.5,
            'base_ht'   => 153.57,
            'vat'       => 8.45,
            'total_ttc' => 162.02,
        ], $byLetter['B']);
        self::assertSame([
            'letter'    => 'D',
            'rate'      => 20.0,
            'base_ht'   => 11.75,
            'vat'       => 2.35,
            'total_ttc' => 14.10,
        ], $byLetter['D']);

        // Multi-taux : taux global null + avertissement explicite.
        self::assertNull($inv['vat_rate']);
        self::assertTrue($this->hasWarning($inv['warnings'], 'TVA multiple'));

        // Rien d'inventé : Σ lignes = Total H.T. et pas de doublon OASIS
        // (le fragment dégradé « 9 OASIS TROPICAL 2L 2,080 » de v0 doit
        // fusionner avec la ligne propre de v1, pas créer une 12e ligne).
        $sum = 0.0;
        foreach ($inv['lines'] as $line) {
            $sum += (float) $line['total'];
        }
        self::assertEqualsWithDelta(165.32, $sum, 0.001);
        self::assertCount(1, array_filter(
            $inv['lines'],
            static fn (array $l): bool => str_starts_with((string) $l['label'], 'OASIS TROPICAL')
        ));
    }

    // ————————————————————————————————————————————————————————————
    // Déduplication, complétion, réparation EAN
    // ————————————————————————————————————————————————————————————

    /**
     * Même ligne lue par deux variantes (EAN corrompu « 0449… » réparé par
     * clé de contrôle puis CONFIRMÉ par l'EAN valide lu ailleurs) : une
     * seule ligne en sortie, au meilleur libellé, avec l'EAN réparé.
     */
    public function test_deduplication_par_ean_et_reparation_confirmee(): void
    {
        $clean = "METRO\nN° FACTURE 0/0(087)0054/033871\nDate facture : 02-10-2026\n"
            . "5449000340085 3162401 MINUTE MAID NECT POMME BTE33CL 0,720 24 1 17,28 B\n"
            . "250,46 B = 5,50% 13,78 264,24\nTotal H.T. : 17,28\nTotal à payer 18,23\n";
        $broken = "METRO\nN° FACTURE 0/0(087)0054/033871\nDate facture : 02-10-2026\n"
            . "0449000340085 3162401 MINUTE MAID NECT POMME BTE33CL 0,720 24 1 17,28 B\n"
            . "Total H.T. : 17,28\n";

        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $broken],
            ['name' => 'v1', 'text' => $clean],
        ]);

        self::assertSame(1, $r['groups'], 'Deux lectures de la MÊME ligne = un seul groupe.');
        self::assertCount(1, $r['invoice']['lines']);
        $line = $r['invoice']['lines'][0];
        self::assertSame('5449000340085', $line['ean'], 'EAN réparé (0→5) puis confirmé par v1.');
        self::assertSame(17.28, $line['total']);
        self::assertSame(24, $line['units']);
    }

    /**
     * Deux produits DISTINCTS au même montant ne fusionnent jamais (EAN-13
     * différents, libellés dissemblables) : RED BULL ICE et RED BULL SUMMER
     * AGRUM de facture_3 restent deux lignes.
     */
    public function test_deux_produits_distincts_au_meme_montant_ne_fusionnent_pas(): void
    {
        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $this->fixture('metro_ocr_psm6_facture_3.txt')],
            ['name' => 'v1', 'text' => $this->degrade($this->fixture('metro_invoice_mixed_vat.txt'), '8,25 B')],
        ]);

        $ice = null;
        $summer = null;
        foreach ($r['invoice']['lines'] as $line) {
            if (str_starts_with((string) $line['label'], 'RED BULL ICE')) {
                $ice = $line;
            }
            if (str_starts_with((string) $line['label'], 'RED BULL SUMMER')) {
                $summer = $line;
            }
        }
        self::assertNotNull($ice);
        self::assertNotNull($summer, 'Deux lignes à 29,58 € : ICE et SUMMER AGRUM restent distinctes.');
        self::assertSame('9002490274658', $ice['ean']);
        self::assertSame('9002490290283', $summer['ean']);
    }

    /**
     * MAUVAIS APPARIEMENT tête/colonnes (réel, photo facture_1) : une
     * variante lit la tête RED BULL (EAN valide) avec les colonnes de la
     * ligne suivante (AGITATEUR, 3,47 €). Le PU d'un produit étant fixe,
     * l'EAN lu à deux montants est démoté sur la lecture minoritaire :
     * RED BULL reste à 24,98 € avec son EAN, AGITATEUR garde sa ligne —
     * pas de troisième ligne fantôme à 3,47 €.
     */
    public function test_ean_misapparie_est_demote_et_les_lignes_restent_propres(): void
    {
        $v0 = "METRO\nN° FACTURE 0/0(087)0054/032004\nDate facture : 18-09-2026\n"
            . "9002490205997 2022838 RED BULL BOITE 25CL 3,470 1 1 3,47 D\n"
            . "9002490205997 2022838 RED BULL BOITE 25CL 1,041 24 1 24,98 B\n"
            . "Total H.T. : 28,45\n";
        $v1 = "METRO\nN° FACTURE 0/0(087)0054/032004\nDate facture : 18-09-2026\n"
            . "DOMAGITATEUR BOIS 11CM 3,470 1 1 3,47 D\n"
            . "9002490205997 2022838 RED BULL BOITE 25CL 1,041 24 1 24,98 B\n"
            . "Total H.T. : 28,45\n";

        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $v0],
            ['name' => 'v1', 'text' => $v1],
        ]);

        self::assertSame(2, $r['groups'], 'Deux produits = deux lignes, le mispair ne crée rien.');
        $redBull = $this->lineByLabel($r['invoice']['lines'], 'RED BULL');
        $agitateur = $this->lineByLabel($r['invoice']['lines'], 'DOMAGITATEUR');
        self::assertNotNull($redBull);
        self::assertNotNull($agitateur, 'AGITATEUR ne doit pas être écrasé par le mispair RED BULL.');
        self::assertSame(24.98, $redBull['total']);
        self::assertSame('9002490205997', $redBull['ean']);
        self::assertSame(3.47, $agitateur['total']);
        self::assertSame(1, $agitateur['units']);
    }

    /**
     * EAN TRONQUÉ par l'OCR (12 chiffres lus, bench image_a_scan) : la
     * réparation par insertion d'un chiffre, confirmée par l'EAN valide lu
     * sur une autre lecture de la même ligne, regroupe les deux lectures
     * (montants légèrement différents 15,50/15,90, même PU) en UNE ligne
     * au montant majoritaire.
     */
    public function test_ean_tronque_repare_par_insertion_fusionne_les_lectures(): void
    {
        $v0 = "METRO\nN° FACTURE 0/0(087)0054/033871\nDate facture : 02-10-2026\n"
            . "5000112617979 1858984 COCA SANS SUCRES 30X33CL OS 0,530 30 1 15,90 B\n"
            . "Total H.T. : 15,90\n";
        $v1 = "METRO\nN° FACTURE 0/0(087)0054/033871\nDate facture : 02-10-2026\n"
            . "500112617979 1858984 COCA SANS SUCRES 30X330L 08 0,530 29 1 15,50 B\n"
            . "Total H.T. : 15,90\n";

        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $v0],
            ['name' => 'v1', 'text' => $v1],
        ]);

        self::assertSame(1, $r['groups'], 'Même produit (EAN réparé 5000112617979, même PU) = une seule ligne.');
        $line = $r['invoice']['lines'][0];
        self::assertSame(15.90, $line['total'], 'La lecture au meilleur score (15,90) fait foi.');
        self::assertSame('5000112617979', $line['ean']);
        self::assertSame(30, $line['units']);
    }

    // ————————————————————————————————————————————————————————————
    // Rien d'inventé : fragments et déchets OCR abandonnés
    // ————————————————————————————————————————————————————————————

    /**
     * Fragments de colonnes sans identité produit (montant isolé, déchets
     * « MMERSAGRUM 250| 1,233 24 » d'une vraie photo) : jamais émis comme
     * ligne — consolidate renvoie des avertissements et zéro ligne.
     */
    public function test_fragments_ocr_jamais_emis_comme_lignes(): void
    {
        $v0 = "METRO\nMM EAN Colisage Désignation\nMMERSAGRUM 250| 1,233 24 29,58 B\nHER al H 165. 32\nE 5, 50% 8,45 162, (\n";
        $v1 = "METRO\nMM EAN Colisage Désignation\nCRES 30X33CL 0\$ 0,477 30 1 lat. EL FE\n40) 6,050 h À 6,05 D\n";

        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $v0],
            ['name' => 'v1', 'text' => $v1],
        ]);

        self::assertSame(0, $r['groups'], 'Aucune ligne fabriquée à partir de fragments.');
        self::assertSame([], $r['invoice']['lines']);
        self::assertNotSame([], $r['invoice']['warnings']);
    }

    // ————————————————————————————————————————————————————————————
    // En-têtes : vote majoritaire entre variantes
    // ————————————————————————————————————————————————————————————

    /**
     * Le n° de facture lu faux par une variante (mais deux fois juste par
     * les autres) perd le vote ; les totaux illisibles d'une variante ne
     * masquent jamais ceux des autres (les null ne votent pas).
     */
    public function test_en_tetes_valeur_la_plus_frequente(): void
    {
        $clean = $this->fixture('metro_invoice.txt');
        $wrong = str_replace('0/0(087)0054/033871', '0/0(087)0054/999999', $clean);
        $wrong = str_replace('Total H.T. : 250,46', 'Total H.T. : illisible', $wrong);

        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $wrong],
            ['name' => 'v1', 'text' => $this->degrade($clean, '15,90 B')],
            ['name' => 'v2', 'text' => $clean],
        ]);

        self::assertSame(3, $r['variants']);
        self::assertSame('0/0(087)0054/033871', $r['invoice']['invoice_number'], 'Le n° majoritaire gagne.');
        self::assertSame(250.46, $r['invoice']['total_ht'], 'Le null de v0 ne vote pas.');
        self::assertSame(264.24, $r['invoice']['total_ttc']);
    }

    // ————————————————————————————————————————————————————————————
    // Tickets (Auchan) et repli historique
    // ————————————————————————————————————————————————————————————

    /**
     * TICKET RÉEL (facture_4, Auchan, partie paiement seule) : pas de
     * reconstruction METRO — la meilleure variante cohérente fait foi
     * (fournisseur AUCHAN, date, TTC 37,24 € préservés) et un fragment
     * OCR isolé « à 92,92 € » ne peut pas passer pour une lecture plus
     * riche que le ticket complet. Les textes restent concaténés avec le
     * marqueur de variante (audit).
     */
    public function test_ticket_auchan_meilleure_variante_sans_fantome(): void
    {
        $fixture = $this->fixture('ticket_ocr_psm6_auchan.txt');
        $passes = $this->passes($fixture);

        $r = InvoiceEnsemble::consolidate([
            ['name' => 'v0', 'text' => $fixture],
            ['name' => 'v1', 'text' => $passes[0]],
            ['name' => 'v2', 'text' => $passes[1]],
        ]);

        self::assertSame(3, $r['variants']);
        self::assertSame('ticket', $r['invoice']['kind']);
        self::assertSame('AUCHAN', $r['invoice']['supplier']);
        self::assertSame('2026-08-31', $r['invoice']['purchased_at']);
        self::assertSame(37.24, $r['invoice']['total_ttc']);
        self::assertSame([], $r['invoice']['lines'], 'Aucune ligne fantôme (le fragment à 92,92 € dépasse le TTC).');

        // Texte consolidé : toutes les variantes, séparées pour l'audit.
        self::assertStringContainsString('----- OCR variante v1 -----', $r['text']);
        self::assertStringContainsString('----- OCR variante v2 -----', $r['text']);
    }

    /**
     * Un seul texte (pas d'ImageMagick) : comportement historique EXACT —
     * texte restitué tel quel, invoice = InvoiceParser::parse(texte),
     * aucune reconstruction. Zéro régression garantie.
     */
    public function test_un_seul_texte_comportement_historique(): void
    {
        $text = $this->fixture('metro_invoice.txt');

        $r = InvoiceEnsemble::consolidate([['name' => 'v0', 'text' => $text]]);

        self::assertSame(1, $r['variants']);
        self::assertSame($text, $r['text'], 'Aucune reconstruction à une seule variante.');
        self::assertSame(InvoiceParser::parse($text), $r['invoice']);
        self::assertSame(13, $r['groups']);
    }

    // ————————————————————————————————————————————————————————————
    // Décision « dernier recours »
    // ————————————————————————————————————————————————————————————

    public function test_dernier_recours_selon_le_nombre_de_candidats_valides(): void
    {
        self::assertTrue(InvoiceEnsemble::needsLastResort(['validated' => 4]));
        self::assertTrue(InvoiceEnsemble::needsLastResort([]));
        self::assertFalse(InvoiceEnsemble::needsLastResort(['validated' => 5]));
        self::assertFalse(InvoiceEnsemble::needsLastResort(['validated' => 12]));
    }

    /**
     * Le marqueur ALT annoncé par InvoiceOcr est bien celui que la fusion
     * sait découper (contrat croisé entre les deux classes).
     */
    public function test_marqueur_alt_contrat_ocr_ensemble(): void
    {
        self::assertSame('----- OCR alt -----', InvoiceOcr::ALT_MARKER);
        $passes = $this->passes("passe un\n" . InvoiceOcr::ALT_MARKER . "\npasse deux\n");
        self::assertSame(['passe un', 'passe deux'], $passes);
    }

    // ————————————————————————————————————————————————————————————
    // Aides
    // ————————————————————————————————————————————————————————————

    /**
     * Contenu d'une fixture (sortie OCR réelle ou transcription propre).
     */
    private function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/../Fixtures/' . $name);
        self::assertNotFalse($content, 'Fixture tests/Fixtures/' . $name . ' introuvable.');

        return $content;
    }

    /**
     * Dégradation réaliste d'un texte propre (simulation d'une variante de
     * prétraitement) : doubles espaces partout + espace OCR dans UN montant
     * (« 15,90 » → « 15, 90 ») — exactement les artefacts observés sur les
     * vraies photos (voir fixtures OCR réelles).
     */
    private function degrade(string $clean, string $amountLine): string
    {
        $degraded = str_replace(' ', '  ', $clean);
        $degraded = str_replace($amountLine, str_replace(',', ', ', $amountLine), $degraded);

        return $degraded;
    }

    /**
     * Découpe un texte en passes OCR (même séparateur que les parseurs).
     *
     * @return list<string>
     */
    private function passes(string $text): array
    {
        $parts = preg_split('/^\s*-{3,}\s*OCR alt\s*-+.*$/m', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($p): bool => $p !== ''));
    }

    /**
     * Retrouve une ligne par préfixe de libellé.
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
