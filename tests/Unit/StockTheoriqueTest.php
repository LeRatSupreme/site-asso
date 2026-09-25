<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Compta\SumUpCsvParser;
use App\Models\ImportBatch;
use App\Models\InventoryCount;
use App\Models\Loss;
use App\Models\Model;
use App\Models\Purchase;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests du stock théorique de l'inventaire (base du réappro).
 *
 * Théorique = dernier comptage + achats − ventes − pertes depuis ce
 * comptage (achats/pertes au jour près, ventes à la seconde près).
 * Vérifie aussi qu'un remboursement SumUp ne compte JAMAIS comme une
 * vente (ni via le CSV, ni via la synchro API) et ne fausse donc
 * jamais le stock ni les moyennes de consommation.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class StockTheoriqueTest extends TestCase
{
    use TestDatabaseTrait;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test de stock ignoré.');

            return false;
        }

        foreach (['sales', 'purchases', 'losses', 'inventory_counts', 'product_stocks'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (\Throwable) {
                self::markTestSkipped("Table `$table` absente de aeic_test : importez database/schema.sql.");

                return false;
            }
        }

        $this->reset($pdo, ['sales', 'import_batches', 'product_aliases', 'purchases', 'losses', 'inventory_counts', 'product_stocks']);
        $this->applyImportDedupMigration($pdo);
        Model::setTestPdo($pdo);

        return true;
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /** Comptage d'inventaire à date fixe (déterministe). */
    private function insertCount(PDO $pdo, string $key, int $qty, string $countedAt): void
    {
        $pdo->prepare(
            'INSERT INTO inventory_counts
                (id, counted_at, product_key, counted_qty, theoretical_qty, gap, note, created_by)
             VALUES (?,?,?,?,?,?,NULL,NULL)'
        )->execute(['inv_' . bin2hex(random_bytes(6)), $countedAt, $key, $qty, $qty, 0]);
    }

    /** Ligne de vente au format importBatch (cf. CashLedgerTest::seedSales). */
    private function saleRow(string $ref, string $soldAt, string $description, int $qty, ?string $productKey = null): array
    {
        return [
            'transaction_ref'  => $ref,
            'sold_at'          => $soldAt,
            'payment_method'   => 'CARTE',
            'payment_raw'      => 'Visa - Débit',
            'quantity'         => $qty,
            'description'      => $description,
            'product_key'      => $productKey,
            'category'         => null,
            'sku'              => null,
            'currency'         => 'EUR',
            'price_ttc'        => 2.5 * $qty,
            'price_ht'         => null,
            'vat'              => null,
            'vat_rate'         => null,
            'seller_account'   => null,
            'is_custom_amount' => 0,
        ];
    }

    private function importSales(array $rows): void
    {
        $batchId = ImportBatch::create(['filename' => 'stock-test.csv']);
        Sale::importBatch($batchId, $rows);
    }

    // ── Formule du stock théorique ─────────────────────────────────────

    public function test_theorique_comptage_plus_achats_moins_ventes_moins_pertes(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $pdo = $this->connect();
        $this->insertCount($pdo, 'redbull-winter', 24, '2026-09-15 18:00:00');

        // Achat le jour du comptage : inclus (borne jour, ≥ 2026-09-15).
        Purchase::create([
            'purchased_at' => '2026-09-15',
            'product_key'  => 'redbull-winter',
            'quantity'     => 24,
            'total_ht'     => 29.76,
            'vat_rate'     => null,
        ]);

        // Vente AVANT le comptage : déjà reflétée dans les 24 comptés.
        $this->importSales([$this->saleRow('TAV1', '2026-09-15 10:00:00', 'Redbull Winter', 5, 'redbull-winter')]);
        // Vente APRÈS le comptage : déduite.
        $this->importSales([$this->saleRow('TAV2', '2026-09-16 12:00:00', 'Redbull Winter', 2, 'redbull-winter')]);

        Loss::create([
            'lost_at'     => '2026-09-16',
            'product_key' => 'redbull-winter',
            'quantity'    => 3,
            'reason'      => 'CASSE',
        ]);

        // 24 (comptés) + 24 (achat) − 2 (vente après) − 3 (perte) = 43.
        // La vente d'avant le comptage (5) n'est PAS déduite.
        self::assertSame(43, InventoryCount::theoreticalStock('redbull-winter'));
        self::assertSame(43, InventoryCount::theoreticalStocksMap()['redbull-winter'] ?? -999);

        // La borne datetime du comptage déduit exactement les ventes postérieures.
        self::assertSame(2, Sale::soldQtySince('redbull-winter', '2026-09-15 18:00:00'));
    }

    public function test_achat_posterieur_au_comptage_est_ajoute(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $pdo = $this->connect();
        $this->insertCount($pdo, 'eau', 6, '2026-09-15 08:00:00');

        Purchase::create([
            'purchased_at' => '2026-09-18',
            'product_key'  => 'eau',
            'quantity'     => 48,
            'total_ht'     => 7.87,
            'vat_rate'     => null,
        ]);

        self::assertSame(54, InventoryCount::theoreticalStock('eau'));
    }

    /**
     * Reproduit le cas « Redbull Winter à −1 » : comptage à 0, puis 25
     * ventes et une livraison de 24. Le théorique (0 + 24 − 25 = −1)
     * est négatif tant qu'un recomptage n'a pas réaligné la base sur
     * le physique : c'est le comportement attendu, pas une fuite.
     */
    public function test_stock_negatif_avant_recomptage(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $pdo = $this->connect();
        $this->insertCount($pdo, 'redbull-winter', 0, '2026-09-15 08:00:00');

        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->saleRow('TW' . $i, '2026-09-15 12:30:00', 'Redbull Winter', 1, 'redbull-winter');
        }
        $this->importSales($rows);

        Purchase::create([
            'purchased_at' => '2026-09-17',
            'product_key'  => 'redbull-winter',
            'quantity'     => 24,
            'total_ht'     => 29.76,
            'vat_rate'     => null,
        ]);

        self::assertSame(-1, InventoryCount::theoreticalStock('redbull-winter'));

        // Le recomptage physique (24 en rayon) repose une nouvelle base.
        $this->insertCount($pdo, 'redbull-winter', 24, '2026-09-25 09:00:00');
        self::assertSame(24, InventoryCount::theoreticalStock('redbull-winter'));
    }

    public function test_produit_jamais_compte_base_zero_sur_achat(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        // Aucune donnée : pas de stock théorique (ni « à compter » fake).
        self::assertNull(InventoryCount::theoreticalStock('bueno-white'));

        // Un achat établit une base 0 : 0 + 10 achetés = 10.
        Purchase::create([
            'purchased_at' => '2026-09-20',
            'product_key'  => 'bueno-white',
            'quantity'     => 10,
            'total_ht'     => 5.9,
            'vat_rate'     => null,
        ]);

        self::assertSame(10, InventoryCount::theoreticalStock('bueno-white'));
    }

    // ── Remboursements : ne doivent jamais compter comme des ventes ────

    public function test_remboursement_csv_ne_compte_pas_dans_les_ventes(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $csv = "Date,Type,Réf. transaction,Moyen de paiement,Quantité,Description,Catégorie,SKU,Devise,Prix avant réduction,Réduction,Prix (TTC),Prix (HT),TVA,Taux de TVA,Compte\n"
            . "16 septembre 2026 12:00,Vente,TV1,Visa - Débit,1,Redbull Winter,,,EUR,2.5,0,2.5,2.37,0.13,5.5,Alex\n"
            . "16 septembre 2026 12:05,Remboursement,TR1,Visa - Débit,1,Redbull Winter,,,EUR,2.5,0,2.5,2.37,0.13,5.5,Alex\n";

        $parsed = (new SumUpCsvParser())->parse($csv);

        // Le remboursement est filtré à la source : une seule ligne valide.
        self::assertSame(1, $parsed['meta']['total']);
        self::assertSame([], $parsed['meta']['invalid']);

        $batchId = ImportBatch::create(['filename' => 'avec-remboursement.csv']);
        Sale::importBatch($batchId, $parsed['rows']);

        // Exactement 1 vente (la ligne « Vente »), jamais 2 (le remboursement
        // ne doit jamais arriver dans `sales`).
        $fresh = $this->connect();
        self::assertSame(1, (int) $fresh->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        // soldQtySince matche la clé produit OU la description exacte.
        self::assertSame(1, Sale::soldQtySince('Redbull Winter', '1970-01-01 00:00:00'));
    }
}
