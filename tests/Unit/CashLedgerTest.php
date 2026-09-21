<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\CashLedger;
use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\ImportBatch;
use App\Models\Model;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du livre de caisse (traçabilité du liquide).
 *
 * Valide : solde théorique (ventes LIQUIDE + mouvements), dépôt banque,
 * comptage avec écart (ajustement automatique + historisation), comptage
 * exact sans ajustement, fenêtre 30 jours des écarts.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class CashLedgerTest extends TestCase
{
    use TestDatabaseTrait;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test de caisse ignoré.');

            return false;
        }

        // Crée les tables de caisse si absentes (migration idempotente).
        foreach (['cash_movements', 'cash_counts'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (\Throwable) {
                $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/2026_cash_ledger.sql');
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt !== '' && !str_starts_with($stmt, '--')) {
                        try {
                            $pdo->exec($stmt);
                        } catch (\Throwable) {
                            // statement de commentaire : ignoré
                        }
                    }
                }
            }
        }

        try {
            $pdo->query('SELECT 1 FROM sales LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Table sales absente de aeic_test : importez database/schema.sql.');

            return false;
        }

        $this->reset($pdo, ['sales', 'import_batches', 'product_aliases', 'cash_movements', 'cash_counts']);
        $this->applyImportDedupMigration($pdo);
        Model::setTestPdo($pdo);

        return true;
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /** Insère des ventes directes (liquide et carte) et renvoie leur total liquide. */
    private function seedSales(): float
    {
        $row = static fn (string $ref, string $method, float $price): array => [
            'transaction_ref'  => $ref,
            'sold_at'          => '2026-09-21 12:00:00',
            'payment_method'   => $method,
            'payment_raw'      => $method,
            'quantity'         => 1,
            'description'      => 'Test ' . $ref,
            'product_key'      => null,
            'category'         => null,
            'sku'              => null,
            'currency'         => 'EUR',
            'price_ttc'        => $price,
            'price_ht'         => null,
            'vat'              => null,
            'vat_rate'         => null,
            'seller_account'   => null,
            'is_custom_amount' => 0,
        ];

        $batchId = ImportBatch::create(['filename' => 'cash-test.csv']);
        Sale::importBatch($batchId, [
            $row('TAAA TEST 01', 'LIQUIDE', 10.0),
            $row('TAAA TEST 02', 'LIQUIDE', 2.5),
            $row('TAAA TEST 03', 'CARTE', 100.0), // ne doit jamais toucher la caisse
        ]);

        return 12.5;
    }

    public function test_solde_avec_ventes_liquide_fond_et_depot(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $liquide = $this->seedSales();

        CashLedger::recordFund(50.0, 'fond initial', 'test@aeic.fr');
        CashLedger::recordDeposit(20.0, 'bordereau 1', 'test@aeic.fr');

        self::assertSame(50.0 + $liquide - 20.0, CashLedger::balance());
        self::assertSame($liquide, CashLedger::salesTotal());

        // Les ventes CARTE ne sont jamais dans la caisse.
        self::assertNotSame(112.5, CashLedger::balance());
    }

    public function test_comptage_avec_ecart_realigne_et_historise(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $liquide = $this->seedSales();
        CashLedger::recordFund(50.0, 'fond', 'test@aeic.fr');
        $theoretical = 50.0 + $liquide; // 62.5

        $res = CashLedger::recordCount(57.5, 'comptage du soir', 'test@aeic.fr');

        self::assertSame(-5.0, $res['ecart'], '5 € manquants = vol potentiel.');
        self::assertSame(57.5, CashLedger::balance(), 'Le théorique est réaligné sur le compté.');

        // L'écart est visible dans la section « ⚠️ Écarts détectés »…
        $ecarts = CashCount::recentEcarts(30);
        self::assertCount(1, $ecarts);
        self::assertSame(-5.0, (float) $ecarts[0]['ecart']);

        // …et matérialisé par un mouvement AJUSTEMENT signé.
        $movements = CashMovement::recent(10);
        $ajustements = array_values(array_filter($movements, static fn ($m): bool => $m['type'] === CashMovement::TYPE_AJUSTEMENT));
        self::assertCount(1, $ajustements);
        self::assertSame(-5.0, (float) $ajustements[0]['amount']);
    }

    public function test_comptage_exact_sans_ajustement(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $liquide = $this->seedSales();
        $res = CashLedger::recordCount($liquide, 'contrôle', 'test@aeic.fr');

        self::assertSame(0.0, $res['ecart']);
        self::assertNull($res['adjustment_id']);
        self::assertSame([], CashCount::recentEcarts(30));
        self::assertSame(1, count(CashCount::recent(10)));
    }

    public function test_ecarts_30_jours_exclut_les_vieux_comptages(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        CashCount::add([
            'counted'     => 10.0,
            'theoretical' => 12.0,
            'label'       => 'vieux comptage',
            'created_by'  => 'test@aeic.fr',
            'created_at'  => date('Y-m-d H:i:s', strtotime('-40 days')),
        ]);
        CashCount::add([
            'counted'     => 10.0,
            'theoretical' => 11.0,
            'label'       => 'comptage récent',
            'created_by'  => 'test@aeic.fr',
        ]);

        self::assertCount(1, CashCount::recentEcarts(30));
        self::assertSame('comptage récent', CashCount::recentEcarts(30)[0]['label']);
    }
}
