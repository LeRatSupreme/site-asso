<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductAlias;
use App\Models\ProductCost;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Test d'intégration de la fusion de produits dupliqués.
 *
 * Valide que Sale::mergeInto / ProductCost::reassign / ProductAlias::reassign
 * regroupent bien ventes, lots et alias vers la clé canonique conservée.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class ProductMergeTest extends TestCase
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

        try {
            $pdo->query('SELECT 1 FROM sales LIMIT 1');
            $pdo->query('SELECT 1 FROM product_costs LIMIT 1');
            $pdo->query('SELECT 1 FROM product_aliases LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Tables sales/product_costs/product_aliases absentes de aeic_test : importez database/schema.sql.');
        }

        $this->reset($pdo, ['sale_adjustments', 'sales', 'import_batches', 'product_aliases', 'product_costs']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /**
     * @return array<string,mixed> Ligne de vente normalisée (format parseur).
     */
    private function row(string $ref, string $description, ?string $productKey): array
    {
        return [
            'transaction_ref'  => $ref,
            'sold_at'          => '2026-09-07 10:00:00',
            'payment_method'   => 'CARTE',
            'payment_raw'      => 'Visa - Débit',
            'quantity'         => 1,
            'description'      => $description,
            'product_key'      => $productKey,
            'category'         => 'Boisson',
            'sku'              => null,
            'currency'         => 'EUR',
            'price_ttc'        => 1.5,
            'price_ht'         => null,
            'vat'              => null,
            'vat_rate'         => null,
            'seller_account'   => 'test',
            'is_custom_amount' => 0,
        ];
    }

    public function test_fusion_regroupe_ventes_lots_et_alias(): void
    {
        $keep = 'Redbull Peach';
        $old = 'redbull Peach';

        // Ventes : 2 groupées sous l'ancienne clé, 1 sans clé (description
        // brute servant de canonique), 1 sous le survivant.
        Sale::importBatch('batch_merge_test', [
            $this->row('T1', 'redbull Peach', $old),
            $this->row('T2', 'redbull Peach', $old),
            $this->row('T3', 'Redbull Peach', null),
            $this->row('T4', 'Redbull Peach', $keep),
        ]);

        // Lots + alias des deux côtés.
        ProductCost::create(['product_key' => $old, 'valid_from' => '2026-09-01', 'cost_price' => 1.23]);
        ProductCost::create(['product_key' => $keep, 'valid_from' => '2026-09-05', 'cost_price' => 1.30]);
        ProductAlias::save(['raw_description' => 'redbull peach', 'product_key' => $old]);
        ProductAlias::save(['raw_description' => 'redbull peach (petite)', 'product_key' => $old]);

        // Fusion.
        $salesMoved = Sale::mergeInto($keep, [$old]);
        $lotsMoved = ProductCost::reassign($old, $keep);
        $aliasesMoved = ProductAlias::reassign($old, $keep);

        self::assertSame(3, $salesMoved, 'Les 3 ventes de l ancienne clé (2 product_key + 1 description) doivent bouger.');
        self::assertSame(1, $lotsMoved);
        self::assertSame(2, $aliasesMoved);

        // Plus qu'un seul produit canonique dans les ventes.
        $keys = $this->pdo->query(
            'SELECT DISTINCT COALESCE(product_key, description) AS k FROM sales'
        )->fetchAll(PDO::FETCH_COLUMN);
        sort($keys);
        self::assertSame([$keep], $keys);

        // Tous les lots et alias pointent vers le survivant.
        $costKeys = $this->pdo->query('SELECT DISTINCT product_key FROM product_costs')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([$keep], $costKeys);

        $aliasKeys = $this->pdo->query('SELECT DISTINCT product_key FROM product_aliases')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([$keep], $aliasKeys);

        // Les libellés bruts sont conservés (les ventes restent immuables
        // hormis leur rattachement canonique).
        $descs = $this->pdo->query('SELECT COUNT(*) FROM sales WHERE description LIKE "%edbull Peach"')->fetchColumn();
        self::assertSame(4, (int) $descs);
    }

    public function test_merge_into_sans_ancienne_cle_ne_fait_rien(): void
    {
        self::assertSame(0, Sale::mergeInto('Coca', []));
        self::assertSame(0, Sale::mergeInto('Coca', ['Coca']), 'Fusionner vers soi-même est un no-op.');
    }
}
