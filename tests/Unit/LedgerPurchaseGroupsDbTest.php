<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\AdminLedgerController;
use App\Models\Model;
use App\Models\Purchase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du regroupement par facture du livre comptable : deux achats
 * saisis le même jour chez le même fournisseur avec des numéros de
 * facture différents doivent donner DEUX groupes récents
 * (recentPurchaseGroups) et DEUX écritures (buildEntries), avec des
 * totaux globaux inchangés. La fusion historique (même n° de facture
 * partagé, ou facture absente) reste en place.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class LedgerPurchaseGroupsDbTest extends TestCase
{
    use TestDatabaseTrait;

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');
        }
        $this->pdo = $pdo;

        // S'assure que les tables existent (le schéma doit avoir été importé
        // dans aeic_test au préalable).
        foreach (['purchases', 'expenses', 'sales', 'product_costs', 'product_stocks'] as $table) {
            try {
                $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            } catch (\Throwable) {
                self::markTestSkipped('Table ' . $table . ' absente de aeic_test : importez database/schema.sql.');
            }
        }

        $this->reset($pdo, ['purchases', 'expenses', 'sales', 'product_costs', 'product_stocks']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /**
     * Deux factures METRO distinctes le même jour : deux groupes récents,
     * chacun avec son n° de facture, son total et son justificatif —
     * structure rétrocompatible (champs historiques conservés, dont
     * « invoice » et « receipt_path » ; ajout « invoice_number » et
     * « receipts »).
     */
    public function test_deux_factures_meme_jour_donnent_deux_groupes(): void
    {
        $this->createPurchase('Coca 33cl', '159-10007281', 12.30, 'uploads/receipts/' . str_repeat('a', 24) . '.pdf', '2026-10-14 08:00:00');
        $this->createPurchase('Fanta 33cl', '159-10007282', 8.50, 'uploads/receipts/' . str_repeat('b', 24) . '.pdf', '2026-10-14 09:00:00');

        $groups = AdminLedgerController::recentPurchaseGroups(20);

        self::assertCount(2, $groups, 'Deux factures distinctes : deux groupes (et non une ligne fusionnée).');

        $invoices = array_map(static fn (array $g): string => (string) $g['invoice'], $groups);
        self::assertSame(['159-10007282', '159-10007281'], $invoices, 'Ordre DESC (dernier créé d\'abord).');

        $byInvoice = [];
        foreach ($groups as $g) {
            $byInvoice[(string) $g['invoice']] = $g;
        }

        $g1 = $byInvoice['159-10007281'];
        self::assertSame('2026-10-14', $g1['date']);
        self::assertSame('METRO', $g1['supplier']);
        self::assertSame(12.30, $g1['total_ttc']);
        self::assertSame('159-10007281', $g1['invoice_number'], 'Champ « invoice_number » exposé (affichage facture).');
        self::assertSame('uploads/receipts/' . str_repeat('a', 24) . '.pdf', $g1['receipt_path']);
        self::assertSame(['uploads/receipts/' . str_repeat('a', 24) . '.pdf'], $g1['receipts']);
        self::assertSame(1, $g1['lines']);

        $g2 = $byInvoice['159-10007282'];
        self::assertSame(8.50, $g2['total_ttc']);
        self::assertSame('159-10007282', $g2['invoice_number']);
        self::assertSame(['uploads/receipts/' . str_repeat('b', 24) . '.pdf'], $g2['receipts']);
    }

    /**
     * Même n° de facture partagé (ou facture absente) : la fusion
     * historique reste en place — un seul groupe, plusieurs lignes, les
     * reçus distincts listés dans « receipts » (ordre de rencontre), le
     * premier dans « receipt_path ».
     */
    public function test_meme_facture_fusion_conservee_avec_receipts(): void
    {
        $receiptA = 'uploads/receipts/' . str_repeat('c', 24) . '.pdf';
        $receiptB = 'uploads/receipts/' . str_repeat('d', 24) . '.png';
        $this->createPurchase('Coca 33cl', '3007 02 0090', 12.30, $receiptA, '2026-10-14 08:00:00');
        $this->createPurchase('Fanta 33cl', '3007 02 0090', 8.50, $receiptB, '2026-10-14 09:00:00');

        $groups = AdminLedgerController::recentPurchaseGroups(20);

        self::assertCount(1, $groups, 'Une course (même facture) : une seule ligne, comme avant.');
        $g = $groups[0];
        self::assertSame('METRO', $g['supplier']);
        self::assertSame(20.80, $g['total_ttc']);
        self::assertSame(2, $g['lines']);
        self::assertSame('3007 02 0090', $g['invoice']);
        self::assertSame('3007 02 0090', $g['invoice_number']);
        self::assertSame($receiptA, $g['receipt_path'], 'Premier justificatif rencontré (comportement historique).');
        self::assertSame([$receiptA, $receiptB], $g['receipts'], 'Tous les justificatifs distincts, ordre de rencontre.');
    }

    /**
     * buildEntries : deux factures distinctes → deux écritures d'achat
     * (débits séparés), totaux globaux du livre inchangés.
     */
    public function test_build_entries_deux_ecritures_totaux_inchanges(): void
    {
        $this->createPurchase('Coca 33cl', '159-10007281', 12.30, null);
        $this->createPurchase('Fanta 33cl', '159-10007282', 8.50, null);

        $ledger = new AdminLedgerController();
        $entries = $ledger->buildEntries('2026-10-14', '2026-10-14');

        $purchases = array_values(array_filter(
            $entries['rows'],
            static fn (array $r): bool => $r['kind'] === 'purchase'
        ));

        self::assertCount(2, $purchases, 'Deux écritures « Achat » (une par facture).');

        $labels = array_map(static fn (array $r): string => (string) $r['label'], $purchases);
        self::assertSame(['Achat — METRO', 'Achat — METRO'], $labels, 'La référence fournisseur reste affichée.');
        self::assertSame(12.30, $purchases[0]['debit']);
        self::assertSame(8.50, $purchases[1]['debit']);

        // Totaux globaux inchangés : la somme des deux factures.
        self::assertSame(20.80, $entries['total_debit']);
        self::assertSame(0.0, $entries['total_credit']);
        self::assertSame(-20.80, $entries['balance']);

        // Le « ticket » de chaque écriture référence sa facture.
        self::assertStringContainsString('facture 159-10007281', (string) $purchases[0]['ticket']);
        self::assertStringContainsString('facture 159-10007282', (string) $purchases[1]['ticket']);
    }

    /**
     * buildEntries : même n° de facture → UNE écriture au total du jour
     * (comportement historique inchangé).
     */
    public function test_build_entries_meme_facture_une_seule_ecriture(): void
    {
        $this->createPurchase('Coca 33cl', '3007 02 0090', 12.30, null);
        $this->createPurchase('Fanta 33cl', '3007 02 0090', 8.50, null);

        $ledger = new AdminLedgerController();
        $entries = $ledger->buildEntries('2026-10-14', '2026-10-14');

        $purchases = array_values(array_filter(
            $entries['rows'],
            static fn (array $r): bool => $r['kind'] === 'purchase'
        ));

        self::assertCount(1, $purchases);
        self::assertSame(20.80, $purchases[0]['debit'], 'Total du jour fusionné.');
        self::assertSame(20.80, $entries['total_debit'], 'Total global inchangé.');
    }

    /**
     * Facture absente : fusion par fournisseur du jour conservée (une
     * seule écriture), quel que soit le produit.
     */
    public function test_build_entries_sans_facture_fusion_historique(): void
    {
        $this->createPurchase('Coca 33cl', '', 12.30, null);
        $this->createPurchase('Fanta 33cl', '', 8.50, null);

        $ledger = new AdminLedgerController();
        $entries = $ledger->buildEntries('2026-10-14', '2026-10-14');

        $purchases = array_values(array_filter(
            $entries['rows'],
            static fn (array $r): bool => $r['kind'] === 'purchase'
        ));

        self::assertCount(1, $purchases, 'Sans facture : une écriture par jour + fournisseur (historique).');
        self::assertSame(20.80, $entries['total_debit']);
    }

    /**
     * Achat réel avec tous les champs d'en-tête (effets de bord inclus :
     * entrée en stock). created_at est fixé explicitement pour rendre
     * l'ordre DESC du modèle déterministe (NOW() a la seconde près).
     *
     * @param string|null $receipt Chemin de justificatif (null = aucun).
     */
    private function createPurchase(string $key, string $invoice, float $amount, ?string $receipt, string $at = '2026-10-14 08:00:00'): string
    {
        $id = Purchase::create([
            'purchased_at'   => '2026-10-14',
            'product_key'    => $key,
            'quantity'       => 24,
            'total_ht'       => $amount,
            'vat_rate'       => null,
            'supplier'       => 'METRO',
            'invoice_number' => $invoice,
            'receipt_path'   => $receipt,
        ]);

        self::assertNotSame('', $id, 'Achat créé (données valides).');

        $stmt = $this->pdo->prepare('UPDATE purchases SET created_at = ? WHERE id = ?');
        $stmt->execute([$at, $id]);

        return $id;
    }
}
