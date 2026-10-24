<?php

declare(strict_types=1);

namespace Tests\Unit;

require_once __DIR__ . '/db_test_bridge.php';

use App\Core\Compta\CostLotStock;
use App\Models\ImportBatch;
use App\Models\Model;
use App\Models\ProductCost;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests DB de CostLotStock::forProduct()/ecarts() : allocation qty par
 * lot (report sur lot futur, fenêtre du « vendu »), écart de stock
 * (nominal / déficit / surplus / sans comptage) et persistance de
 * qty_purchased (ProductCost::create/update).
 *
 * NB : écart = stock théorique − (achete − vendu − perdu). Un stock
 * plus bas que prévu donne un écart NÉGATIF (unités manquantes : vol,
 * casse ou vente non déclarée — badge danger côté vue) ; un stock plus
 * haut un écart POSITIF (stock excédentaire : achat non saisi ou
 * quantité erronée). Les deux signes sont couverts ici.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class CostLotStockDbTest extends TestCase
{
    use TestDatabaseTrait;

    private function requireDatabase(): bool
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : test CostLotStock DB ignoré.');

            return false;
        }

        foreach (['product_costs', 'sales', 'import_batches', 'inventory_counts', 'purchases', 'losses'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (\Throwable) {
                self::markTestSkipped("Table `$table` absente de aeic_test : importez database/schema.sql.");

                return false;
            }
        }

        $this->reset($pdo, ['product_costs', 'sales', 'import_batches', 'inventory_counts', 'purchases', 'losses']);
        $this->applyCostQtyMigration($pdo);
        $this->applyImportDedupMigration($pdo);
        Model::setTestPdo($pdo);
        // Pont db() actif le temps du test : CostLotStock lit les ventes/
        // pertes via db() globale ; désactivé en tearDown pour laisser le
        // reste de la suite dans son état historique (« db() absente »).
        db_test_bridge_enable();

        return true;
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
        db_test_bridge_disable();
    }

    /**
     * Applique (best effort) la migration qty_purchased
     * (database/migrations/2026_cost_qty_repair.sql) : comme
     * CostLotInvariantsTest, une erreur (déjà appliquée) est ignorée.
     */
    private function applyCostQtyMigration(PDO $pdo): void
    {
        try {
            $pdo->exec(
                'ALTER TABLE product_costs ADD COLUMN IF NOT EXISTS qty_purchased INT NULL AFTER cost_price'
            );
        } catch (\Throwable) {
            // Déjà appliqué : non bloquant.
        }
    }

    /** Ligne de vente au format importBatch (description seule = clé COALESCE). */
    private function saleRow(string $ref, string $soldAt, string $description, int $qty): array
    {
        return [
            'transaction_ref'  => $ref,
            'sold_at'          => $soldAt,
            'payment_method'   => 'CARTE',
            'payment_raw'      => 'Visa - Débit',
            'quantity'         => $qty,
            'description'      => $description,
            'product_key'      => null,
            'category'         => null,
            'sku'              => null,
            'currency'         => 'EUR',
            'price_ttc'        => 2.0 * $qty,
            'price_ht'         => null,
            'vat'              => null,
            'vat_rate'         => null,
            'seller_account'   => null,
            'is_custom_amount' => 0,
        ];
    }

    private function importSales(array $rows): void
    {
        $batchId = ImportBatch::create(['filename' => 'cost-lot-stock-test.csv']);
        Sale::importBatch($batchId, $rows);
    }

    /** Comptage d'inventaire à date fixe (déterministe). */
    private function insertCount(string $key, int $qty, string $countedAt): void
    {
        $this->connect()->prepare(
            'INSERT INTO inventory_counts
                (id, counted_at, product_key, counted_qty, theoretical_qty, gap, note, created_by)
             VALUES (?,?,?,?,?,?,NULL,NULL)'
        )->execute(['inv_' . bin2hex(random_bytes(6)), $countedAt, $key, $qty, $qty, 0]);
    }

    private function lotById(string $id): array
    {
        $stmt = $this->connect()->prepare('SELECT * FROM product_costs WHERE id = ?');
        $stmt->execute([$id]);
        $lot = $stmt->fetch();
        self::assertNotFalse($lot, 'Le lot ' . $id . ' doit exister.');

        /** @var array<string,mixed> $lot */
        return $lot;
    }

    // ── forProduct : allocation + report ────────────────────────────────

    public function test_forproduct_nominal_lot_suivi_report_sur_lot_futur(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $id1 = ProductCost::create([
            'product_key'   => 'cola',
            'cost_price'    => 0.5,
            'valid_from'    => '2026-10-01',
            'qty_purchased' => 10,
        ]);
        // Lot futur (créé après : le 1er est clôturé à la veille, les deux
        // restent suivis) — le report des unités au-delà de 10 doit tomber
        // dessus, même si les ventes précèdent son valid_from.
        $id2 = ProductCost::create([
            'product_key'   => 'cola',
            'cost_price'    => 0.55,
            'valid_from'    => '2026-12-01',
            'qty_purchased' => 24,
        ]);

        // 12 ventes en 2 lignes, clé = description seule (COALESCE).
        $this->importSales([
            $this->saleRow('C1', '2026-10-05 12:00:00', 'cola', 5),
            $this->saleRow('C2', '2026-10-06 12:00:00', 'cola', 7),
        ]);

        $data = CostLotStock::forProduct('cola');

        // 10 unités sur le 1er lot (épuisé), 2 en report sur le lot futur.
        self::assertSame(10, $data['lots'][$id1]['alloue']);
        self::assertSame(0, $data['lots'][$id1]['restant']);
        self::assertTrue($data['lots'][$id1]['epuise']);
        self::assertSame(2, $data['lots'][$id2]['alloue']);
        self::assertSame(22, $data['lots'][$id2]['restant']);
        self::assertFalse($data['lots'][$id2]['epuise']);
        self::assertSame(0, $data['non_couvert']);

        // Fenêtre : les 12 ventes sont postérieures au 1er lot suivi.
        self::assertSame('2026-10-01', $data['premier_lot_suivi']);
        self::assertSame(12, $data['vendu']);
        self::assertSame(0, $data['perdu']);
        self::assertSame(34, $data['achete']);

        // Ni comptage ni achat/perte : pas de stock → pas d'écart.
        self::assertNull($data['stock']);
        self::assertNull($data['ecart']);
    }

    public function test_forproduct_ventes_avant_premier_lot_hors_fenetre_mais_allouees(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $id = ProductCost::create([
            'product_key'   => 'eau',
            'cost_price'    => 1.0,
            'valid_from'    => '2026-10-10',
            'qty_purchased' => 10,
        ]);

        // 12 ventes AVANT le 1er lot suivi : hors fenêtre du « vendu »,
        // mais allouées au lot (report rétroactif), 2 restent non couvertes.
        $this->importSales([
            $this->saleRow('E1', '2026-10-01 12:00:00', 'eau', 7),
            $this->saleRow('E2', '2026-10-05 12:00:00', 'eau', 5),
        ]);

        $data = CostLotStock::forProduct('eau');

        self::assertSame(10, $data['lots'][$id]['alloue'], 'Les ventes antérieures sont bien allouées au lot (report).');
        self::assertSame(0, $data['lots'][$id]['restant']);
        self::assertTrue($data['lots'][$id]['epuise']);
        self::assertSame(2, $data['non_couvert']);
        self::assertSame(0, $data['vendu'], 'Les ventes antérieures au 1er lot suivi sont exclues de la fenêtre.');
        self::assertSame('2026-10-10', $data['premier_lot_suivi']);
    }

    // ── ecarts : écart de stock par produit ─────────────────────────────

    public function test_ecarts_nominal_deficit_surplus_et_sans_comptage(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        foreach (['limonade', 'biere', 'chips', 'sirop'] as $key) {
            ProductCost::create([
                'product_key'   => $key,
                'cost_price'    => 1.0,
                'valid_from'    => '2026-10-01',
                'qty_purchased' => 10,
            ]);
        }

        // Nominal : comptage 10 puis 2 ventes → stock 8, écart 0.
        $this->insertCount('limonade', 10, '2026-10-01 08:00:00');
        $this->importSales([$this->saleRow('L1', '2026-10-02 12:00:00', 'limonade', 2)]);

        // Déficit (« vol » saisi au comptage) : stock 7 pour 10 achetés
        // suivis, 0 vente → écart = 7 − 10 = −3 (négatif).
        $this->insertCount('biere', 7, '2026-10-20 09:00:00');

        // Surplus : 12 en stock pour 10 achetés suivis → écart = +2
        // (unités en stock non expliquées par les achats suivis).
        $this->insertCount('chips', 12, '2026-10-20 09:00:00');

        // sirop : aucun comptage, ni achat/perte → stock inconnu → écart null.

        $ecarts = CostLotStock::ecarts();

        self::assertArrayHasKey('limonade', $ecarts);
        self::assertArrayHasKey('biere', $ecarts);
        self::assertArrayHasKey('chips', $ecarts);
        self::assertArrayHasKey('sirop', $ecarts);

        self::assertSame(0, $ecarts['limonade']['ecart'], 'Cas nominal : écart nul.');
        self::assertSame(8, $ecarts['limonade']['stock']);
        self::assertSame(2, $ecarts['limonade']['vendu']);

        self::assertSame(-3, $ecarts['biere']['ecart'], 'Stock plus bas que prévu : écart négatif (unités manquantes → vol/casse/vente non déclarée).');

        self::assertSame(2, $ecarts['chips']['ecart'], 'Stock plus haut que prévu : écart positif (excédent → achat non saisi ou quantité erronée).');

        self::assertNull($ecarts['sirop']['stock'], 'Sans comptage ni mouvement stock : pas de stock.');
        self::assertNull($ecarts['sirop']['ecart'], 'Sans stock : pas d\'écart.');
    }

    // ── Persistance qty_purchased ────────────────────────────────────────

    public function test_persistance_qty_purchased_create_puis_update(): void
    {
        if (!$this->requireDatabase()) {
            return;
        }

        $id = ProductCost::create([
            'product_key'   => 'chocolat',
            'cost_price'    => 1.2,
            'valid_from'    => '2026-10-01',
            'qty_purchased' => 15,
        ]);
        self::assertSame(15, (int) $this->lotById($id)['qty_purchased'], 'La quantité créée est relue telle quelle.');

        self::assertTrue(ProductCost::update($id, [
            'valid_from'    => '2026-10-01',
            'cost_price'    => 1.2,
            'qty_purchased' => 7,
        ]));
        self::assertSame(7, (int) $this->lotById($id)['qty_purchased'], 'La quantité mise à jour est relue telle quelle.');

        // '' / null = arrêter le suivi (NULL en base).
        self::assertTrue(ProductCost::update($id, [
            'valid_from'    => '2026-10-01',
            'cost_price'    => 1.2,
            'qty_purchased' => null,
        ]));
        self::assertNull($this->lotById($id)['qty_purchased']);
    }
}
