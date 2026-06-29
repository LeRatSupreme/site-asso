<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Models\CafeteriaOrder;
use App\Models\Model;
use App\Models\OrderWorkflow;
use App\Models\Product;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests des commandes cafétéria : création (total recalculé, stock décrémenté,
 * produit indisponible refusé, stock insuffisant refusé) + workflow de statut.
 *
 * Saute automatiquement si la base `aeic_test` n'est pas joignable.
 */
final class CafeteriaOrderTest extends TestCase
{
    use TestDatabaseTrait;

    private PDO $pdo;
    private string $userId = 'u_caf_test';
    private string $p1 = 'prod_caf_1';
    private string $p2 = 'prod_caf_2';

    protected function setUp(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : configurez DB_* dans phpunit.xml.');
        }
        $this->pdo = $pdo;

        $this->reset($pdo, ['cafeteria_order_items', 'cafeteria_orders', 'products', 'users']);
        Model::setTestPdo($pdo);

        $this->seedProduct($this->p1, 'Coca', 1.50, 10, true);
        $this->seedProduct($this->p2, 'Chips', 1.00, 2, true);

        $this->pdo->prepare(
            'INSERT INTO users (id, prenom, nom, email, role, is_active) VALUES (?,?,?,?,?,1)'
        )->execute([$this->userId, 'Caf', 'Test', 'caftest@exemple.fr', Auth::ROLE_ELEVE]);
    }

    protected function tearDown(): void
    {
        Model::setTestPdo(null);
    }

    public function test_create_recalcule_le_total_serveur(): void
    {
        $orderId = CafeteriaOrder::create($this->userId, [
            ['product' => Product::find($this->p1), 'quantity' => 2], // 3.00
            ['product' => Product::find($this->p2), 'quantity' => 1], // 1.00
        ]);

        $order = CafeteriaOrder::find($orderId);

        self::assertNotNull($order);
        self::assertSame('4.00', (string) $order['total']);
    }

    public function test_create_decremente_le_stock(): void
    {
        CafeteriaOrder::create($this->userId, [
            ['product' => Product::find($this->p1), 'quantity' => 3],
        ]);

        self::assertSame(7, (int) Product::find($this->p1)['stock']);
    }

    public function test_create_refuse_un_produit_indisponible(): void
    {
        // On désactive p2.
        $this->pdo->prepare('UPDATE products SET is_available = 0 WHERE id = ?')->execute([$this->p2]);

        $this->expectException(\Throwable::class);
        CafeteriaOrder::create($this->userId, [
            ['product' => Product::find($this->p2), 'quantity' => 1],
        ]);
    }

    public function test_create_refuse_un_stock_insuffisant(): void
    {
        // p2 a un stock de 2.
        $this->expectException(\Throwable::class);
        CafeteriaOrder::create($this->userId, [
            ['product' => Product::find($this->p2), 'quantity' => 5],
        ]);
    }

    public function test_create_panier_vide_echoue(): void
    {
        $this->expectException(\Throwable::class);
        CafeteriaOrder::create($this->userId, []);
    }

    public function test_stock_insuffisant_n_ecrit_rien(): void
    {
        try {
            CafeteriaOrder::create($this->userId, [
                ['product' => Product::find($this->p2), 'quantity' => 5],
            ]);
        } catch (\Throwable) {
            // ignoré
        }

        // Aucune commande créée, stock intact.
        self::assertSame(2, (int) Product::find($this->p2)['stock']);
        self::assertSame([], CafeteriaOrder::forUser($this->userId));
    }

    public function test_change_status_respecte_le_workflow(): void
    {
        $orderId = CafeteriaOrder::create($this->userId, [
            ['product' => Product::find($this->p1), 'quantity' => 1],
        ]);

        CafeteriaOrder::changeStatus($orderId, 'CONFIRMED');
        self::assertSame('CONFIRMED', CafeteriaOrder::find($orderId)['status']);

        // Transition interdite (saut d'étape).
        $this->expectException(\Throwable::class);
        CafeteriaOrder::changeStatus($orderId, 'DELIVERED');
    }

    public function test_items_renvoie_les_lignes_de_commande(): void
    {
        $orderId = CafeteriaOrder::create($this->userId, [
            ['product' => Product::find($this->p1), 'quantity' => 2],
        ]);

        $items = CafeteriaOrder::items($orderId);

        self::assertCount(1, $items);
        self::assertSame('Coca', $items[0]['product_name']);
        self::assertSame('2', (string) $items[0]['quantity']);
    }

    private function seedProduct(string $id, string $name, float $price, int $stock, bool $available): void
    {
        $this->pdo->prepare(
            'INSERT INTO products (id, name, price, stock, is_active, is_available, `order`)
             VALUES (?,?,?,?,1,?,0)'
        )->execute([$id, $name, $price, $stock, $available ? 1 : 0]);
    }
}
