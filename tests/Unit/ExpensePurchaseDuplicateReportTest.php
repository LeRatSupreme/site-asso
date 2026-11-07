<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\ExpensePurchaseDuplicateReport;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou anti-doublons « expense × purchases » (fusion « Saisir une
 * dépense » × « Achats ») : détecte les paires suspectes avec les deux
 * signaux validés par l'audit pré-fusion du 06/11/2026 —
 *  - n° de facture identique le même jour (dépense × achats) ;
 *  - fournisseur cité dans le libellé + montant = Σ achats du
 *    fournisseur le même jour (dépense sans n° de facture) ;
 * et ne remonte RIEN sur des données saines (la base réelle auditée le
 * 06/11 : 0 paire sur 11 expenses × 132 purchases).
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class ExpensePurchaseDuplicateReportTest extends TestCase
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

        foreach (['expenses', 'purchases'] as $table) {
            try {
                $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            } catch (\Throwable) {
                self::markTestSkipped('Table ' . $table . ' absente de aeic_test : importez database/schema.sql.');
            }
        }

        $this->reset($pdo, ['expenses', 'purchases']);
    }

    /**
     * Insère une dépense (id explicite pour les assertions).
     */
    private function createExpense(string $id, string $day, string $label, float $ttc, string $invoice = ''): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO expenses (id, spent_at, category, label, amount_ttc, invoice_number, created_at)
             VALUES (?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([$id, $day, 'DIVERS', $label, $ttc, $invoice !== '' ? $invoice : null]);
    }

    /**
     * Insère un achat rattaché à une facture.
     */
    private function createPurchase(string $day, string $supplier, string $invoice, string $key, float $ttc): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO purchases (id, purchased_at, supplier, invoice_number, product_key, quantity, total_ttc, created_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, NOW())'
        );
        $stmt->execute(['purchase_' . bin2hex(random_bytes(5)), $day, $supplier, $invoice !== '' ? $invoice : null, $key, $ttc]);
    }

    /**
     * Signal 1 : le ticket écrit DEUX fois (dépense + achats sous le même
     * n° de facture, le même jour) — le profil exact des 3 doublons du
     * 06/11 — est détecté.
     */
    public function test_detecte_le_ticket_comptabilise_deux_fois_meme_facture(): void
    {
        $this->createExpense('exp_dup_1', '2026-11-02', 'Course METRO 02/11', 450.14, '0/0(087)0054/037861');
        $this->createPurchase('2026-11-02', 'METRO', '0/0(087)0054/037861', 'Coca dup', 300.00);
        $this->createPurchase('2026-11-02', 'METRO', '0/0(087)0054/037861', 'Fanta dup', 150.14);

        $pairs = ExpensePurchaseDuplicateReport::detect($this->pdo);

        self::assertCount(1, $pairs, 'Une paire suspecte : dépense × groupe d\'achats de la même facture.');
        self::assertSame('invoice_meme_jour', $pairs[0]['signal']);
        self::assertSame('exp_dup_1', (string) $pairs[0]['expense_id']);
        self::assertSame('0/0(087)0054/037861', (string) $pairs[0]['invoice_number']);
        self::assertSame(2, (int) $pairs[0]['purchase_lines'], 'Les deux lignes d\'achats sont comptées.');
        self::assertSame(450.14, (float) $pairs[0]['purchases_total_ttc']);
    }

    /**
     * Même n° de facture mais un AUTRE jour : pas de doublon (deux
     * factures distinctes peuvent partager un format de numéro).
     */
    public function test_meme_facture_autre_jour_non_signale(): void
    {
        $this->createExpense('exp_ok_1', '2026-11-02', 'Course METRO', 50.00, 'F-100');
        $this->createPurchase('2026-11-01', 'METRO', 'F-100', 'Coca autre jour', 50.00);

        self::assertSame([], ExpensePurchaseDuplicateReport::detect($this->pdo));
    }

    /**
     * Signal 2 : dépense sans facture dont le libellé cite le
     * fournisseur et dont le montant ÉGALE la somme des achats de ce
     * fournisseur le même jour.
     */
    public function test_detecte_fournisseur_cite_et_montant_egal_somme_du_jour(): void
    {
        $this->createPurchase('2026-10-16', 'METRO', 'F-200', 'Coca somme', 141.79);
        $this->createExpense('exp_dup_2', '2026-10-16', 'Course METRO du 16', 141.79);

        $pairs = ExpensePurchaseDuplicateReport::detect($this->pdo);

        self::assertCount(1, $pairs, 'Une paire suspecte : fournisseur cité + montant = Σ achats du jour.');
        self::assertSame('fournisseur_montant_jour', $pairs[0]['signal']);
        self::assertSame('exp_dup_2', (string) $pairs[0]['expense_id']);
        self::assertSame('METRO', (string) $pairs[0]['supplier']);
        self::assertSame(141.79, (float) $pairs[0]['purchases_total_ttc']);
    }

    /**
     * Base saine (état réel audité le 06/11/2026 : frais divers légitimes
     * distincts des achats) : aucune paire remontée. Cas limites : dépense
     * fournisseur cité mais montant différent, fournisseur NON cité avec
     * le même montant, frais bancaires sans achats.
     */
    public function test_base_saine_aucune_paire(): void
    {
        // Achats du 16/10 (METRO, 141.79 €) et dépenses distinctes.
        $this->createPurchase('2026-10-16', 'METRO', 'F-300', 'Coca sain', 141.79);
        // Fournisseur cité, montant différent.
        $this->createExpense('exp_sain_1', '2026-10-16', 'Achats divers METRO', 73.80);
        // Même montant, fournisseur NON cité.
        $this->createExpense('exp_sain_2', '2026-10-16', 'Achat de gobelet', 141.79);
        // Frais bancaires, sans achats le même jour.
        $this->createExpense('exp_sain_3', '2026-10-20', 'Frais de dépôt banque', 3.50);

        self::assertSame([], ExpensePurchaseDuplicateReport::detect($this->pdo));
    }

    /**
     * Base vide : aucune paire, aucun crash (état d'une base fraîchement
     * migrée avant toute saisie).
     */
    public function test_base_vide_aucune_paire(): void
    {
        self::assertSame([], ExpensePurchaseDuplicateReport::detect($this->pdo));
    }
}
