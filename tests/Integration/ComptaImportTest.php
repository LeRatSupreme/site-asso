<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Tests d'intÃ©gration de l'import de ventes SumUp
 * (route POST /admin/compta/import â†’ parseur + Sale::importBatch + base).
 *
 * Le login admin est forcÃ© (bypass 2FA via APP_TESTING) pour isoler le test
 * du flux d'authentification, dÃ©jÃ  couvert par ailleurs.
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
        $this->applyImportDedupMigration();

        $this->seedUser($this->adminId, 'admin-int@exemple.fr', 'Password123456', 'ADMIN');

        // Mini rapport SumUp (3 lignes) en mÃ©moire, posÃ© sur disque pour l'upload.
        $csv = "Date,Type,RÃ©f. transaction,Moyen de paiement,QuantitÃ©,Description,CatÃ©gorie,SKU,Devise,Prix avant rÃ©duction,RÃ©duction,Prix (TTC),Prix (HT),TVA,Taux de TVA,Compte"
            . "\n1 juin 2026 09:59,Vente,TINT001,Visa - DÃ©bit,1,Bueno,Nourriture,,EUR,1,0,1,1,0,,Alex"
            . "\n1 juin 2026 10:01,Vente,TINT002,Mastercard - DÃ©bit,1,Coca,Boissons,,EUR,1,0,1,1,0,,Alex"
            . "\n1 juin 2026 11:27,Vente,TINT003,Visa - DÃ©bit,1,Montant personnalisÃ©,,,EUR,1,0,1,1,0,,Alex";
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

        // 1er import : 3 lignes insÃ©rÃ©es.
        $r1 = $this->request('POST', '/admin/compta/import', [], $files, $this->adminId);
        self::assertStringContainsString('/admin/compta/import', $this->location($r1));
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());

        // RÃ©import du mÃªme fichier : aucune ligne en double (dÃ©duplication).
        $this->request('POST', '/admin/compta/import', [], $files, $this->adminId);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
    }

    /**
     * Applique (best effort) database/migrations/2026_import_dedup.sql :
     * chaque statement est independant, une erreur (deja applique) est ignoree.
     */
    private function applyImportDedupMigration(): void
    {
        $statements = [
            "UPDATE sales SET description = '' WHERE description IS NULL",
            "ALTER TABLE sales MODIFY description VARCHAR(255) NOT NULL DEFAULT ''",
            'ALTER TABLE import_batches ADD COLUMN file_hash CHAR(64) NULL AFTER filename',
            'ALTER TABLE import_batches ADD UNIQUE KEY uniq_import_hash (file_hash)',
        ];
        foreach ($statements as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (\PDOException) {
                // Deja applique : non bloquant.
            }
        }
    }

    public function test_reimport_meme_fichier_refuse_par_empreinte(): void
    {
        $files = ['csv' => ['name' => 'rapport.csv', 'tmp_name' => $this->csvPath]];

        // 1er import : 3 lignes, 1 seul lot.
        $this->request('POST', '/admin/compta/import', [], $files, $this->adminId);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM import_batches')->fetchColumn());

        // Reimport du MEME fichier (empreinte SHA-256 identique) : refuse
        // avant meme le dedoublonnage base — ni lot, ni ligne en plus.
        $r2 = $this->request('POST', '/admin/compta/import', [], $files, $this->adminId);

        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM import_batches')->fetchColumn());

        $flashes = $r2['session']['_flash'] ?? [];
        self::assertNotEmpty($flashes);
        $last = end($flashes);
        self::assertSame('error', $last['type']);
        self::assertStringContainsString('déjà été importé', $last['message']);
        self::assertStringContainsString('Import refusé', $last['message']);
    }
}
