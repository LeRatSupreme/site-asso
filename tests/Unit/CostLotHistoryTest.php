<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Model;
use App\Models\ProductCost;
use App\Models\Sale;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Règle métier « as-of » du coût de revient : le coût appliqué à une vente
 * est celui du lot valable À LA DATE de la vente — créer un lot ne modifie
 * JAMAIS rétroactivement le coût des ventes qui ont déjà un lot applicable.
 *
 * Reproduit le bug signalé : un lot « Cafe » créé le 17/09 (premier lot du
 * produit) ne doit pas changer les ventes antérieures une fois valorisées,
 * et un lot postérieur supplémentaire ne doit rien changer du tout au passé.
 *
 * Couvre le chemin SQL : Sale::aggregatesBetween (COST_SUBQUERY) et
 * ProductCost::costAt / lotAt.
 *
 * Saute automatiquement si la base aeic_test est indisponible.
 */
final class CostLotHistoryTest extends TestCase
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
        } catch (\Throwable $e) {
            self::markTestSkipped('Table sales absente de aeic_test : importez database/schema.sql.');
        }

        $this->reset($pdo, ['sales', 'product_costs']);
        Model::setTestPdo($pdo);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    /**
     * Insère une vente produit (1 unité, TTC donné).
     */
    private function insertSale(string $ref, string $soldAt, string $product, float $priceTtc): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sales (id, transaction_ref, sold_at, payment_method, description, product_key, price_ttc)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['sale_' . $ref, $ref, $soldAt, 'CARTE', $product, $product, $priceTtc]);
    }

    public function test_creer_un_lot_ne_reecrit_pas_le_profit_des_ventes_deja_valorisees(): void
    {
        // Ventes « Cafe » : une avant le premier lot, une après.
        $this->insertSale('T1', '2026-09-10 10:00:00', 'Cafe', 2.00);
        $this->insertSale('T2', '2026-09-25 10:00:00', 'Cafe', 2.00);

        // Premier (et unique) lot du produit, créé « aujourd'hui » (17/09).
        ProductCost::create(['product_key' => 'Cafe', 'cost_price' => 0.80, 'valid_from' => '2026-09-17']);

        // Vente antérieure au premier lot → lot le plus ancien connu (0,80).
        self::assertSame(0.80, (float) ProductCost::costAt('Cafe', '2026-09-10'));
        // Vente postérieure → lot couvrant (0,80).
        self::assertSame(0.80, (float) ProductCost::costAt('Cafe', '2026-09-25'));

        // Profit global : 2.00 − 0.80 ×2 = 2.40.
        $agg = Sale::aggregatesBetween('2026-09-01', '2026-09-30');
        self::assertSame(2.40, round((float) $agg['profit'], 2));

        // Création d'un lot POSTÉRIEUR (20/09 à 0,90) : le lot 1 est clôturé
        // à la veille (chaînage de ProductCost::create).
        ProductCost::create(['product_key' => 'Cafe', 'cost_price' => 0.90, 'valid_from' => '2026-09-20']);

        // La vente antérieure garde EXACTEMENT le même coût : le profit du
        // 01 → 16/09 n'a pas bougé (jamais rétroactif).
        self::assertSame(0.80, (float) ProductCost::costAt('Cafe', '2026-09-10'));
        $before = Sale::aggregatesBetween('2026-09-01', '2026-09-16');
        self::assertSame(1.20, round((float) $before['profit'], 2));

        // La vente du 25/09 bascule sur le nouveau lot.
        self::assertSame(0.90, (float) ProductCost::costAt('Cafe', '2026-09-25'));
        $after = Sale::aggregatesBetween('2026-09-20', '2026-09-30');
        self::assertSame(1.10, round((float) $after['profit'], 2));
    }

    public function test_vente_dans_un_trou_entre_deux_lots_prend_le_lot_precedent(): void
    {
        $this->insertSale('T1', '2026-10-05 10:00:00', 'The', 3.00);

        ProductCost::create(['product_key' => 'The', 'cost_price' => 1.00, 'valid_from' => '2026-09-01']);
        ProductCost::create(['product_key' => 'The', 'cost_price' => 1.20, 'valid_from' => '2026-10-10']);

        // Clôture manuelle du lot 2 au 30/09 : trou du 01 au 09/10.
        $this->pdo->exec("UPDATE product_costs SET valid_to = '2026-09-30'
                          WHERE product_key = 'The' AND cost_price = 1.20");

        // Vente dans le trou → lot précédent (1,00), jamais le lot futur.
        self::assertSame(1.00, (float) ProductCost::costAt('The', '2026-10-05'));

        $agg = Sale::aggregatesBetween('2026-10-01', '2026-10-09');
        self::assertSame(2.00, round((float) $agg['profit'], 2));

        // Après le trou : lot couvrant (1,20).
        self::assertSame(1.20, (float) ProductCost::costAt('The', '2026-10-10'));
    }
}
