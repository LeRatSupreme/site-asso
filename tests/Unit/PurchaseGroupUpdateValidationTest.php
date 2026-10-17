<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AdminStockController;
use PHPUnit\Framework\TestCase;

/**
 * Tests purs de la validation de l'édition d'un groupe d'achats
 * (AdminStockController::validatePurchaseGroupUpdate) : date obligatoire
 * au format « YYYY-MM-DD » (jour de calendrier réel), fournisseur et n°
 * de facture bornés à 255 caractères (taille des colonnes), valeurs
 * trimées. Méthode statique pure (aucun accès POST/DB) : le contrôleur
 * n'est jamais instancié ici (même pattern que LedgerPurchaseGroupTest).
 */
final class PurchaseGroupUpdateValidationTest extends TestCase
{
    /**
     * Entrée valide : ok=true, valeurs trimées, pas d'erreur.
     */
    public function test_entree_valide(): void
    {
        $res = AdminStockController::validatePurchaseGroupUpdate(' 2026-10-14 ', ' METRO ', ' 159-10007281 ');

        self::assertTrue($res['ok']);
        self::assertNull($res['error']);
        self::assertSame('2026-10-14', $res['purchased_at']);
        self::assertSame('METRO', $res['supplier']);
        self::assertSame('159-10007281', $res['invoice_number']);
    }

    /**
     * Champs vides acceptés (date obligatoire excepté) : fournisseur et
     * n° de facture peuvent être vidés (stockés NULL par le modèle).
     */
    public function test_fournisseur_et_facture_vides_acceptes(): void
    {
        $res = AdminStockController::validatePurchaseGroupUpdate('2026-10-14', '', '');

        self::assertTrue($res['ok']);
        self::assertSame('', $res['supplier']);
        self::assertSame('', $res['invoice_number']);
    }

    /**
     * Date manquante ou mal formée : refus avec message FR.
     */
    public function test_date_manquante_ou_mal_formee_refusee(): void
    {
        foreach (['', '   ', '14/10/2026', '2026-10', '2026-10-14 12:00', '20261014'] as $bad) {
            $res = AdminStockController::validatePurchaseGroupUpdate($bad, 'METRO', 'F1');
            self::assertFalse($res['ok'], 'Date refusée attendue : « ' . $bad . ' ».');
            self::assertSame('Date invalide (format attendu AAAA-MM-JJ).', $res['error']);
        }
    }

    /**
     * Jour de calendrier impossible (2026-02-30, 2026-13-01) : refus —
     * createFromFormat roule les débordements, la re-formation sert de
     * garde.
     */
    public function test_jour_impossible_refuse(): void
    {
        foreach (['2026-02-30', '2026-13-01', '2026-00-10', '2026-10-32'] as $bad) {
            $res = AdminStockController::validatePurchaseGroupUpdate($bad, 'METRO', 'F1');
            self::assertFalse($res['ok'], 'Jour de calendrier refusé attendu : « ' . $bad . ' ».');
        }
    }

    /**
     * Fournisseur : 255 caractères acceptés (taille de colonne), 256
     * refusés avec message FR.
     */
    public function test_fournisseur_borne_255(): void
    {
        $ok = AdminStockController::validatePurchaseGroupUpdate('2026-10-14', str_repeat('A', 255), '');
        self::assertTrue($ok['ok']);

        $ko = AdminStockController::validatePurchaseGroupUpdate('2026-10-14', str_repeat('A', 256), '');
        self::assertFalse($ko['ok']);
        self::assertSame('Fournisseur trop long (255 caractères maximum).', $ko['error']);
    }

    /**
     * N° de facture : 255 caractères acceptés, 256 refusés avec message
     * FR (accents comptés via mb_strlen : « É » répété reste 1 caractère).
     */
    public function test_facture_borne_255(): void
    {
        $ok = AdminStockController::validatePurchaseGroupUpdate('2026-10-14', '', str_repeat('É', 255));
        self::assertTrue($ok['ok']);

        $ko = AdminStockController::validatePurchaseGroupUpdate('2026-10-14', '', str_repeat('É', 256));
        self::assertFalse($ko['ok']);
        self::assertSame('Numéro de facture trop long (255 caractères maximum).', $ko['error']);
    }
}
