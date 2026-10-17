<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AdminLedgerController;
use PHPUnit\Framework\TestCase;

/**
 * Tests purs de la clé de regroupement des achats du livre comptable
 * (AdminLedgerController::purchaseGroupKey) : jour + référence
 * (fournisseur, sinon n° de facture partagé, sinon clé produit) + n° de
 * facture NORMALISÉ (minuscules, espaces bornes retirés, '' si absent).
 *
 * Deux factures distinctes saisies le même jour chez le même fournisseur
 * donnent deux clés — donc deux écritures — sans toucher au cas
 * historique (une course = un n° de facture commun = une écriture).
 * Méthode pure (aucun accès POST/DB) : le contrôleur n'est jamais
 * instancié ici, seul l'appel statique est exercé (même pattern que
 * LedgerPurchaseGroupTest pour purchaseGroupRef).
 */
final class LedgerPurchaseGroupKeyTest extends TestCase
{
    /**
     * Cas du bug : deux factures METRO distinctes le même jour — la clé
     * inclut le n° de facture, les deux achats ne fusionnent plus.
     */
    public function test_deux_factures_meme_jour_meme_fournisseur_donnent_deux_cles(): void
    {
        $a = AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '159-10007281', 'Coca 33cl');
        $b = AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '159-10007282', 'Fanta 33cl');

        self::assertNotSame($a, $b);
    }

    /**
     * Même jour + même fournisseur + même facture (casse et espaces
     * bornes ignorés) : même clé — la fusion historique d'une course est
     * conservée.
     */
    public function test_meme_facture_casse_et_espaces_ignores_meme_cle(): void
    {
        self::assertSame(
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '159-10007281', 'Coca 33cl'),
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '  159-10007281  ', 'Fanta 33cl')
        );
        self::assertSame(
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '159-10007281', 'Coca 33cl'),
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '159-10007281', 'Fanta 33cl')
        );
    }

    /**
     * Facture absente ('' ou espaces, y compris NULL converti en '') :
     * la clé retombe sur jour + référence — le comportement historique
     * (fusion par fournisseur du jour) est inchangé.
     */
    public function test_facture_absente_comportement_historique_inchange(): void
    {
        self::assertSame(
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '', 'Coca 33cl'),
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '', 'Fanta 33cl')
        );
        self::assertSame(
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '', 'Coca 33cl'),
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '   ', 'Fanta 33cl')
        );
    }

    /**
     * Deux jours différents ne fusionnent jamais, facture égale ou non.
     */
    public function test_jours_differents_ne_fusionnent_pas(): void
    {
        self::assertNotSame(
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '159-10007281', 'Coca 33cl'),
            AdminLedgerController::purchaseGroupKey('2026-10-15', 'METRO', '159-10007281', 'Coca 33cl')
        );
    }

    /**
     * Format de la clé : « date|ref|factureNormalisée » — le n° est en
     * minuscules et trimé ; sans fournisseur, la référence reste le n°
     * de facture (purchaseGroupRef), le suffixe normalisé n'ajoute rien
     * de contradictoire (mêmes écritures fusionnées).
     */
    public function test_format_cle_avec_facture_normalisee(): void
    {
        self::assertSame(
            '2026-10-14|METRO|159-10007281',
            AdminLedgerController::purchaseGroupKey('2026-10-14', 'METRO', '159-10007281', 'Coca 33cl')
        );
        self::assertSame(
            '2026-10-14|METRO|159-10007281',
            AdminLedgerController::purchaseGroupKey('2026-10-14', ' METRO ', ' 159-10007281 ', 'Coca 33cl')
        );
        // Sans fournisseur : ref = facture, suffixe = facture normalisée.
        self::assertSame(
            '2026-10-14|Ticket 54199|ticket 54199',
            AdminLedgerController::purchaseGroupKey('2026-10-14', '', 'Ticket 54199', 'Bueno')
        );
    }

    /**
     * Tout vide : la référence retombe sur la clé produit (repli
     * historique de purchaseGroupRef) — deux produits différents d'un
     * même jour ne fusionnent pas, deux lignes du même produit oui.
     */
    public function test_tout_vide_repli_cle_produit_historique(): void
    {
        self::assertNotSame(
            AdminLedgerController::purchaseGroupKey('2026-10-14', '', '', 'A'),
            AdminLedgerController::purchaseGroupKey('2026-10-14', '', '', 'B')
        );
        self::assertSame(
            AdminLedgerController::purchaseGroupKey('2026-10-14', '', '', 'A'),
            AdminLedgerController::purchaseGroupKey('2026-10-14', '', '', 'A')
        );
    }
}
