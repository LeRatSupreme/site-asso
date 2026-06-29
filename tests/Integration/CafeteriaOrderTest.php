<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Tests d'intégration du parcours commande cafétéria
 * (panier en session → POST /eleve/cafeteria/checkout → base cafeteria_orders).
 */
final class CafeteriaOrderTest extends IntegrationTestCase
{
    private string $userId = 'u_caf_int';
    private string $productId = 'prod_caf_int';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->requireDatabase();
        $this->reset(['cafeteria_order_items', 'cafeteria_orders', 'products', 'users', 'settings']);

        $this->seedUser($this->userId, 'caf@exemple.fr');
        $this->seedProduct($this->productId, 'Café', 1.20, 5);
    }

    public function test_checkout_cree_commande_et_decremente_le_stock(): void
    {
        $this->login('caf@exemple.fr', 'Password1');

        // 1. Ajout au panier (panier en session).
        $add = $this->request('POST', '/eleve/cafeteria/add', [
            'product_id' => $this->productId,
            'quantity'   => 2,
        ]);
        self::assertStringContainsString('/eleve/cafeteria', $this->location($add));

        // 2. Validation de la commande.
        $checkout = $this->request('POST', '/eleve/cafeteria/checkout');
        self::assertStringContainsString('/eleve/commandes', $this->location($checkout));

        // Commande créée avec total recalculé serveur (2 × 1,20 = 2,40).
        $order = $this->pdo->prepare('SELECT * FROM cafeteria_orders WHERE user_id = ?');
        $order->execute([$this->userId]);
        $row = $order->fetch();
        self::assertNotNull($row);
        self::assertSame('2.40', (string) $row['total']);

        // 1 ligne d'item, quantité 2.
        $items = $this->pdo->prepare('SELECT * FROM cafeteria_order_items WHERE order_id = ?');
        $items->execute([$row['id']]);
        $lines = $items->fetchAll();
        self::assertCount(1, $lines);
        self::assertSame('2', (string) $lines[0]['quantity']);

        // Stock décrémenté : 5 - 2 = 3.
        self::assertSame(3, (int) $this->stock($this->productId));
    }

    public function test_produit_indisponible_rejete_la_commande(): void
    {
        $this->login('caf@exemple.fr', 'Password1');

        // On rend le produit indisponible côté serveur.
        $this->pdo->prepare('UPDATE products SET is_available = 0 WHERE id = ?')->execute([$this->productId]);

        $this->request('POST', '/eleve/cafeteria/add', [
            'product_id' => $this->productId,
            'quantity'   => 1,
        ]);

        // L'ajout lui-même refuse déjà un produit indisponible : le panier reste vide,
        // donc le checkout échoue et aucune commande n'est écrite.
        $this->request('POST', '/eleve/cafeteria/checkout');

        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM cafeteria_orders WHERE user_id = '{$this->userId}'")
            ->fetchColumn();
        self::assertSame(0, $count);
        self::assertSame(5, (int) $this->stock($this->productId), 'Stock intact (rien écrit).');
    }

    private function stock(string $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);

        return (int) $stmt->fetchColumn();
    }

    private function seedProduct(string $id, string $name, float $price, int $stock): void
    {
        $this->pdo->prepare(
            'INSERT INTO products (id, name, price, stock, is_active, is_available, `order`)
             VALUES (?,?,?,?,1,1,0)'
        )->execute([$id, $name, $price, $stock]);
    }
}
