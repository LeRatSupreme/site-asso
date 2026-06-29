<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Coûts de revient par lot daté (table `product_costs`).
 *
 * Comme les achats se font à plusieurs endroits/fournisseurs, le coût varie
 * dans le temps. On gère des lots datés (valid_from..valid_to) : le lot valide
 * à la date d'une vente donne son coût de revient unitaire.
 */
final class ProductCost extends Model
{
    protected static string $table = 'product_costs';

    /**
     * Tous les lots, triés par produit puis date de début décroissante.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query('SELECT * FROM product_costs ORDER BY product_key, valid_from DESC')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Coût unitaire applicable à un produit à une date donnée.
     */
    public static function costAt(string $productKey, string $date): ?string
    {
        $stmt = self::pdo()->prepare(
            'SELECT cost_price FROM product_costs
             WHERE product_key = ?
               AND valid_from <= ?
               AND (valid_to IS NULL OR ? < valid_to)
             ORDER BY valid_from DESC
             LIMIT 1'
        );
        // Comparaison sur la partie DATE uniquement.
        $day = substr($date, 0, 10);
        $stmt->execute([$productKey, $day, $day]);
        $row = $stmt->fetch();

        return $row ? (string) $row['cost_price'] : null;
    }

    /**
     * Lot actuellement en cours (valid_to IS NULL) pour un produit.
     *
     * @return array<string,mixed>|null
     */
    public static function current(string $productKey): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT * FROM product_costs
             WHERE product_key = ? AND valid_to IS NULL
             ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$productKey]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Lots d'un produit, triés du plus récent au plus ancien.
     *
     * @return list<array<string,mixed>>
     */
    public static function forProduct(string $productKey): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT * FROM product_costs WHERE product_key = ? ORDER BY valid_from DESC'
        );
        $stmt->execute([$productKey]);

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Crée un nouveau lot pour un produit.
     *
     * Clôt automatiquement le lot précédent en cours (valid_to = veille du
     * nouveau valid_from) pour conserver des périodes strictement consécutives.
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): string
    {
        $pdo = self::pdo();
        $productKey = trim((string) ($data['product_key'] ?? ''));
        $validFrom = substr((string) ($data['valid_from'] ?? ''), 0, 10);

        if ($productKey === '' || $validFrom === '') {
            return '';
        }

        $costPrice = (float) ($data['cost_price'] ?? 0);
        $supplier = ($data['supplier'] ?? '') !== '' ? (string) $data['supplier'] : null;
        $notes = ($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null;

        // Clôture du lot précédent ouvert (veille du nouveau valid_from).
        $pdo->prepare(
            'UPDATE product_costs
                SET valid_to = DATE_SUB(?, INTERVAL 1 DAY)
              WHERE product_key = ? AND valid_to IS NULL'
        )->execute([$validFrom, $productKey]);

        $id = 'cost_' . bin2hex(random_bytes(10));
        $pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from, valid_to, supplier, notes, created_at)
             VALUES (?,?,?,?, NULL, ?, ?, NOW())'
        )->execute([$id, $productKey, $costPrice, $validFrom, $supplier, $notes]);

        return $id;
    }

    /**
     * Clôt manuellement un lot (valid_to = hier).
     */
    public static function close(string $id): bool
    {
        $stmt = self::pdo()->prepare(
            'UPDATE product_costs SET valid_to = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }
}
