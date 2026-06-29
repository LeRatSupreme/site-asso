<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Tests d'intégration de l'import de ventes SumUp
 * (route POST /admin/compta/import → parseur + Sale::importBatch + base).
 *
 * Le login admin est forcé (bypass 2FA via APP_TESTING) pour isoler le test
 * du flux d'authentification, déjà couvert par ailleurs.
 */
final class ComptaImportTest extends IntegrationTestCase
{
    private string $adminId = 'u_admin_int';
    private string $csvPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset(['sale_adjustments', 'sales', 'import_batches', 'product_aliases', 'settings']);

        $this->seedUser($this->adminId, 'admin-int@exemple.fr', 'Password1', 'ADMIN');

        // Mini rapport SumUp (3 lignes) en mémoire, posé sur disque pour l'upload.
        $csv = "Date,Type,Réf. transaction,Moyen de paiement,Quantité,Description,Catégorie,SKU,Devise,Prix avant réduction,Réduction,Prix (TTC),Prix (HT),TVA,Taux de TVA,Compte"
            . "\n1 juin 2026 09:59,Vente,TINT001,Visa - Débit,1,Bueno,Nourriture,,EUR,1,0,1,1,0,,Alex"
            . "\n1 juin 2026 10:01,Vente,TINT002,Mastercard - Débit,1,Coca,Boissons,,EUR,1,0,1,1,0,,Alex"
            . "\n1 juin 2026 11:27,Vente,TINT003,Visa - Débit,1,Montant personnalisé,,,EUR,1,0,1,1,0,,Alex";
        $this->csvPath = sys_get_temp_dir() . '/aeic_import_' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($this->csvPath, $csv);
    }

    protected function tearDown(): void
    {
        if (is_file($this->csvPath)) {
            @unlink($this->csvPath);
        }
        parent::tearDown();
    }

    public function test_import_inserer_puis_dedupliquer(): void
    {
        $files = ['csv' => ['name' => 'rapport.csv', 'tmp_name' => $this->csvPath]];

        // 1er import : 3 lignes insérées.
        $r1 = $this->request('POST', '/admin/compta/import', [], $files, $this->adminId);
        self::assertStringContainsString('/admin/compta/import', $this->location($r1));
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());

        // Réimport du même fichier : aucune ligne en double (déduplication).
        $this->request('POST', '/admin/compta/import', [], $files, $this->adminId);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }
}
