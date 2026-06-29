<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des commandes cafétéria (table `cafeteria_orders`).
 */
final class CafeteriaOrder extends Model
{
    protected static string $table = 'cafeteria_orders';

    /**
     * Crée une commande avec ses lignes, en décrémentant atomiquement les stocks.
     *
     * Toute la création est enveloppée dans une transaction : si un produit est
     * indisponible ou si le stock est insuffisant, rien n'est écrit.
     *
     * @param list<array{product: array<string,mixed>, quantity: int}> $items
     * @return string Identifiant de la commande créée.
     *
     * @throws \RuntimeException si le panier est vide ou un produit invalide/stock insuffisant.
     */
    public static function create(?string $userId, array $items, string $notes = ''): string
    {
        if ($items === []) {
            throw new \RuntimeException('La commande est vide.');
        }

        $pdo = static::pdo();
        $orderId = 'ord_' . bin2hex(random_bytes(12));

        $pdo->beginTransaction();
        try {
            // Recalcul serveur du total (on ne fait jamais confiance au client).
            $total = 0.0;
            $lines = [];

            foreach ($items as $entry) {
                $product = Product::find((string) $entry['product']['id']);
                $quantity = (int) $entry['quantity'];

                if ($product === null || (int) $product['is_active'] !== 1 || (int) $product['is_available'] !== 1) {
                    throw new \RuntimeException('Un produit n\'est plus disponible.');
                }
                if ($quantity < 1) {
                    throw new \RuntimeException('Quantité invalide.');
                }

                // Décrément atomique (échec si stock insuffisant).
                if (!Product::decrementStock((string) $product['id'], $quantity)) {
                    throw new \RuntimeException(
                        sprintf('Stock insuffisant pour « %s ».', (string) $product['name'])
                    );
                }

                $unitPrice = (float) $product['price'];
                $total += $unitPrice * $quantity;
                $lines[] = [
                    'product'  => $product,
                    'quantity' => $quantity,
                    'unit'     => $unitPrice,
                ];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO cafeteria_orders (id, user_id, status, total, notes)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $orderId,
                $userId,
                OrderWorkflow::PENDING,
                round($total, 2),
                mb_substr($notes, 0, 500),
            ]);

            foreach ($lines as $line) {
                $stmt = $pdo->prepare(
                    'INSERT INTO cafeteria_order_items (id, order_id, product_id, quantity, price)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    'itm_' . bin2hex(random_bytes(10)),
                    $orderId,
                    (string) $line['product']['id'],
                    $line['quantity'],
                    $line['unit'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $orderId;
    }

    /**
     * Change le statut d'une commande en respectant le workflow.
     *
     * @throws \RuntimeException si la transition est interdite.
     */
    public static function changeStatus(string $orderId, string $newStatus): void
    {
        $order = self::find($orderId);
        if ($order === null) {
            throw new \RuntimeException('Commande introuvable.');
        }

        $current = (string) $order['status'];
        if (!OrderWorkflow::canTransition($current, $newStatus)) {
            throw new \RuntimeException(sprintf(
                'Transition de statut interdite (%s → %s).',
                $current,
                $newStatus
            ));
        }

        $stmt = static::pdo()->prepare(
            'UPDATE cafeteria_orders SET status = ? WHERE id = ?'
        );
        $stmt->execute([$newStatus, $orderId]);
    }

    /**
     * Commandes d'un utilisateur (les plus récentes d'abord).
     *
     * @return list<array<string,mixed>>
     */
    public static function forUser(string $userId, int $limit = 0): array
    {
        $sql = 'SELECT * FROM cafeteria_orders WHERE user_id = ? ORDER BY created_at DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        try {
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([$userId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Toutes les commandes (admin), les plus récentes d'abord, avec le client.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(int $limit = 0): array
    {
        $sql = 'SELECT o.*, u.prenom, u.nom
                FROM cafeteria_orders o
                LEFT JOIN users u ON u.id = o.user_id
                ORDER BY o.created_at DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        try {
            $stmt = static::pdo()->query($sql);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Chiffre d'affaires total (somme des commandes non annulées).
     */
    public static function revenue(): float
    {
        try {
            $stmt = static::pdo()->query(
                "SELECT COALESCE(SUM(total), 0) FROM cafeteria_orders WHERE status <> 'CANCELLED'"
            );

            return (float) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Lignes détaillées d'une commande (avec nom du produit).
     *
     * @return list<array<string,mixed>>
     */
    public static function items(string $orderId): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT i.*, p.name AS product_name
                 FROM cafeteria_order_items i
                 LEFT JOIN products p ON p.id = i.product_id
                 WHERE i.order_id = ?
                 ORDER BY i.id ASC'
            );
            $stmt->execute([$orderId]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
