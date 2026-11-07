<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Panier cafétéria (logique pure, sans DB).
 *
 * Représente une sélection de produits (id => [product, quantity]) et calcule
 * le total. La validation de la disponibilité/stock se fait côté contrôleur
 * au moment de la commande (jamais au panier).
 */
final class Cart
{
    /** @var array<string, array{product: array<string,mixed>, quantity: int}> */
    private array $items = [];

    /**
     * @param array<string, array{product: array<string,mixed>, quantity: int}> $items
     */
    public function __construct(array $items = [])
    {
        foreach ($items as $id => $entry) {
            $this->items[$id] = $entry;
        }
    }

    /**
     * Ajoute (ou augmente) une quantité pour un produit.
     *
     * @param array<string,mixed> $product
     */
    public function add(array $product, int $quantity = 1): void
    {
        if ($quantity <= 0) {
            return;
        }

        $id = (string) $product['id'];
        if (isset($this->items[$id])) {
            $this->items[$id]['quantity'] += $quantity;
        } else {
            $this->items[$id] = ['product' => $product, 'quantity' => $quantity];
        }
    }

    /**
     * Définit la quantité exacte d'un produit (0 = retire l'article).
     *
     * @param array<string,mixed> $product
     */
    public function setQuantity(array $product, int $quantity): void
    {
        $id = (string) $product['id'];
        if ($quantity <= 0) {
            unset($this->items[$id]);
            return;
        }

        $this->items[$id] = ['product' => $product, 'quantity' => $quantity];
    }

    /**
     * Retire un produit du panier.
     */
    public function remove(string $productId): void
    {
        unset($this->items[$productId]);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return array<string, array{product: array<string,mixed>, quantity: int}>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Nombre total d'articles (somme des quantités).
     */
    public function count(): int
    {
        return array_sum(array_map(static fn (array $i): int => $i['quantity'], $this->items));
    }

    /**
     * Total du panier (somme prix × quantité).
     */
    public function total(): float
    {
        $sum = 0.0;
        foreach ($this->items as $entry) {
            $sum += (float) $entry['product']['price'] * (int) $entry['quantity'];
        }

        return $sum;
    }
}
