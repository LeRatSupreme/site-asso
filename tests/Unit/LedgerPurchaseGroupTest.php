<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AdminLedgerController;
use PHPUnit\Framework\TestCase;

/**
 * Tests purs de la référence de regroupement des achats du livre
 * comptable (AdminLedgerController::purchaseGroupRef) : même jour +
 * même fournisseur, sinon même n° de facture/ticket partagé, sinon clé
 * produit (repli historique). Une course scannée sans fournisseur mais
 * avec un n° de facture commun reste donc fusionnée en une seule
 * écriture au total du jour. Méthode pure (aucun accès POST/DB) : le
 * contrôleur n'est jamais instancié ici, seul l'appel statique est
 * exercé (même pattern que SeoController::buildSitemap dans les tests).
 */
final class LedgerPurchaseGroupTest extends TestCase
{
    /**
     * Le fournisseur renseigné gagne systématiquement, même si un n° de
     * facture est présent : c'est la règle historique du livre (une
     * écriture par jour et par fournisseur).
     */
    public function test_fournisseur_renseigne_gagne(): void
    {
        self::assertSame(
            'METRO',
            AdminLedgerController::purchaseGroupRef('METRO', '3007 02 0090', 'Coca 33cl')
        );
    }

    /**
     * Fournisseur vide + n° de facture présent : la facture sert de
     * référence — c'est le cas du bug (course de 5 produits sans
     * fournisseur, n° « Ticket 54199 Auchant » commun, qui doit
     * fusionner en une écriture et non une ligne par produit).
     */
    public function test_sans_fournisseur_la_facture_sert_de_reference(): void
    {
        self::assertSame(
            'Ticket 54199 Auchant',
            AdminLedgerController::purchaseGroupRef('', 'Ticket 54199 Auchant', 'Bueno white')
        );
    }

    /**
     * Tout vide sauf la clé produit : repli historique, la clé produit
     * référence le groupe (un achat isolé sans fournisseur ni facture
     * reste identifiable par son produit).
     */
    public function test_repli_sur_la_cle_produit(): void
    {
        self::assertSame(
            'Bueno white',
            AdminLedgerController::purchaseGroupRef('', '', 'Bueno white')
        );
    }

    /**
     * Espaces bornes ignorés sur chaque valeur : la référence retenue
     * est toujours trimée (les libellés « Achat — <référence> » et les
     * clés de regroupement restent propres).
     */
    public function test_espaces_bornes_ignores(): void
    {
        self::assertSame(
            'METRO',
            AdminLedgerController::purchaseGroupRef('  METRO  ', '3007 02 0090', 'Coca 33cl')
        );
        self::assertSame(
            'Ticket 54199',
            AdminLedgerController::purchaseGroupRef("   \t", ' Ticket 54199 ', 'Bueno white')
        );
        self::assertSame(
            'Gauffre',
            AdminLedgerController::purchaseGroupRef('', '   ', ' Gauffre ')
        );
    }

    /**
     * Tout vide : '' — dernier recours documenté de la chaîne de repli
     * (clé produit elle-même vide), le regroupement retombe alors sur
     * le jour seul.
     */
    public function test_tout_vide_rend_chaine_vide(): void
    {
        self::assertSame('', AdminLedgerController::purchaseGroupRef('', '', ''));
        self::assertSame('', AdminLedgerController::purchaseGroupRef('  ', '   ', ' '));
    }
}
