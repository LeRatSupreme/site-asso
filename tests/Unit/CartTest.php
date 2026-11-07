<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Cart;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du panier cafétéria (logique pure, sans DB).
 */
final class CartTest extends TestCase
{
    private function product(string $id, float $price): array
    {
        return ['id' => $id, 'name' => 'P' . $id, 'price' => $price];
    }

    public function test_panier_vide_a_total_zero(): void
    {
        $cart = new Cart();

        self::assertTrue($cart->isEmpty());
        self::assertSame(0.0, $cart->total());
        self::assertSame(0, $cart->count());
    }

    public function test_add_cumule_les_quantites(): void
    {
        $cart = new Cart();
        $cart->add($this->product('a', 1.0), 2);
        $cart->add($this->product('a', 1.0), 3);

        self::assertSame(5, $cart->count());
        self::assertSame(5.0, $cart->total());
    }

    public function test_total_calcule_prix_x_quantite(): void
    {
        $cart = new Cart();
        $cart->add($this->product('a', 1.5), 2);  // 3.0
        $cart->add($this->product('b', 2.0), 4);  // 8.0

        self::assertSame(11.0, $cart->total());
    }

    public function test_set_quantity_remplace_la_quantite(): void
    {
        $cart = new Cart();
        $cart->add($this->product('a', 1.0), 5);
        $cart->setQuantity($this->product('a', 1.0), 2);

        self::assertSame(2, $cart->count());
    }

    public function test_set_quantity_zero_retire_l_article(): void
    {
        $cart = new Cart();
        $cart->add($this->product('a', 1.0), 3);
        $cart->setQuantity($this->product('a', 1.0), 0);

        self::assertTrue($cart->isEmpty());
    }

    public function test_remove_retire_un_produit(): void
    {
        $cart = new Cart();
        $cart->add($this->product('a', 1.0));
        $cart->add($this->product('b', 2.0));
        $cart->remove('a');

        self::assertSame(1, $cart->count());
        self::assertSame(2.0, $cart->total());
    }

    public function test_clear_vide_le_panier(): void
    {
        $cart = new Cart();
        $cart->add($this->product('a', 1.0), 2);
        $cart->clear();

        self::assertTrue($cart->isEmpty());
    }

    public function test_add_quantite_negative_est_ignoree(): void
    {
        $cart = new Cart();
        $cart->add($this->product('a', 1.0), -3);

        self::assertTrue($cart->isEmpty());
    }
}
