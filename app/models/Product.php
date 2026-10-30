<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\StockPublic;

/**
 * Modèle des produits cafétéria.
 */
final class Product extends Model
{
    protected static string $table = 'products';

    /**
     * Produits actifs et disponibles, triés par catégorie puis ordre.
     *
     * @return list<array<string,mixed>>
     */
    public static function available(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT p.*, c.name AS category_name
                 FROM products p
                 LEFT JOIN product_categories c ON c.id = p.category_id
                 WHERE p.is_active = 1 AND p.is_available = 1
                 ORDER BY c.`order` ASC, p.`order` ASC, p.name ASC'
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Recherche un produit par son identifiant.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $id): ?array
    {
        try {
            $stmt = static::pdo()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Fiche jumelle d'une clé produit par NOM EXACT (la fiche cafétéria
     * vit au nom de la clé — cf. ProductKeyMerge::mergeProductFiches).
     * Sert à l'archive pré-fusion (T1MergeArchive) : présence, prix,
     * stock de la fiche AVANT fusion.
     *
     * @return array<string,mixed>|null
     */
    public static function findByName(string $name): ?array
    {
        try {
            $stmt = static::pdo()->prepare('SELECT * FROM products WHERE name = ? COLLATE utf8mb4_bin LIMIT 1');
            $stmt->execute([trim($name)]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Décrémente atomiquement le stock d'un produit.
     *
     * La condition `stock >= quantité` empêche tout stock négatif ; la requête
     * ne modifie rien si le stock est insuffisant.
     *
     * @return bool Vrai si le stock a bien été décrémenté.
     */
    public static function decrementStock(string $productId, int $quantity): bool
    {
        $stmt = static::pdo()->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );
        $stmt->execute([$quantity, $productId, $quantity]);

        return $stmt->rowCount() === 1;
    }

    // -----------------------------------------------------------------
    //  Méthodes d'administration.
    // -----------------------------------------------------------------

    /**
     * Tous les produits (admin), triés par catégorie puis ordre.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT p.*, c.name AS category_name
                 FROM products p
                 LEFT JOIN product_categories c ON c.id = p.category_id
                 ORDER BY c.`order` ASC, p.`order` ASC, p.name ASC'
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Crée ou met à jour un produit (upsert par id).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'prod_' . bin2hex(random_bytes(10));
        }

        $fields = [
            'id'           => $id,
            'name'         => $data['name'] ?? '',
            'description'  => $data['description'] ?? null,
            'price'        => $data['price'] ?? 0,
            'image'        => $data['image'] ?? null,
            'category_id'  => ($data['category_id'] ?? '') !== '' ? $data['category_id'] : null,
            'stock'        => (int) ($data['stock'] ?? 0),
            'is_available' => !empty($data['is_available']) ? 1 : 0,
            'is_active'    => isset($data['is_active']) ? ((int) $data['is_active']) : 1,
            'order'        => (int) ($data['order'] ?? 0),
        ];

        self::upsert('products', $fields, $isNew);

        return $id;
    }

    /**
     * Supprime une fiche produit — REFUSÉ si sa clé porte un historique.
     *
     * RÈGLE « L'INVENTAIRE EST SACRÉ » : une clé/fiche produit qui porte
     * des comptages (inventory_counts) ou des ventes (sales) n'est jamais
     * supprimée durablement — elle se désactive ou se fusionne
     * (ProductKeyMerge, qui déplace TOUT avant de supprimer via
     * deleteRowAfterMerge : seule exception documentée à cette garde).
     *
     * Appariement de la clé (le nom de la fiche) aux données d'historique
     * par la primitive partagée StockPublic::normalizeKey (casse, accents,
     * ponctuation et espacements repliés) : une vente sous « Coca_cherry »
     * protège la fiche « Coca cherry » — jamais de strtolower ad hoc.
     *
     * @throws ProductDeletionRefusedException Historique inventaire/ventes présent.
     */
    public static function deleteRow(string $id): void
    {
        $fiche = self::find($id);
        if ($fiche !== null && self::porteHistorique((string) ($fiche['name'] ?? ''))) {
            throw new ProductDeletionRefusedException(sprintf(
                '« %s » : produit avec historique inventaire/ventes — désactivez-le ou fusionnez la clé.',
                (string) ($fiche['name'] ?? $id)
            ));
        }

        $stmt = static::pdo()->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Suppression de fiche RÉSERVÉE au chemin de fusion ProductKeyMerge.
     *
     * Exception documentée à la garde de deleteRow() : la fusion déplace
     * D'ABORD toutes les données de la clé source (ventes, comptages,
     * achats, pertes, coûts, caisse, stock… même transaction) puis
     * supprime la fiche jumelle devenue vide — ce chemin légitime,
     * audité par l'appelant, ne doit jamais être bloqué par la garde.
     */
    public static function deleteRowAfterMerge(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * La clé d'une fiche porte-t-elle un historique protégé (comptages
     * d'inventaire ou ventes) ?
     *
     * Deux passes, comme les résolveurs partagés : correspondance EXACTE
     * d'abord (la collation ci couvre déjà casse/accents), puis repli par
     * forme NORMALISÉE (StockPublic::normalizeKey) pour les variantes de
     * ponctuation/espacement (« Coca_cherry » ~ « Coca cherry »). Les
     * ventes sans clé résolue comptent par leur description (même
     * convention COALESCE que Sale::lastSoldByProduct).
     *
     * Échec de lecture = refus (fail-safe : dans le doute, l'inventaire
     * est sacré — on ne supprime pas ce qu'on n'a pas pu vérifier).
     */
    public static function porteHistorique(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        try {
            $pdo = static::pdo();

            $counts = $pdo->prepare('SELECT COUNT(*) FROM inventory_counts WHERE product_key = ?');
            $counts->execute([$name]);
            if ((int) $counts->fetchColumn() > 0) {
                return true;
            }

            $sales = $pdo->prepare(
                "SELECT COUNT(*) FROM sales
                 WHERE product_key = ?
                    OR ((product_key IS NULL OR product_key = '') AND description = ?)"
            );
            $sales->execute([$name, $name]);
            if ((int) $sales->fetchColumn() > 0) {
                return true;
            }

            $norm = StockPublic::normalizeKey($name);
            if ($norm === '') {
                return false;
            }

            $cles = [];
            foreach ($pdo->query('SELECT DISTINCT product_key FROM inventory_counts WHERE product_key IS NOT NULL') as $row) {
                $cles[] = (string) $row['product_key'];
            }
            foreach ($pdo->query('SELECT DISTINCT COALESCE(product_key, description) AS k FROM sales') as $row) {
                $cles[] = (string) $row['k'];
            }

            foreach ($cles as $cle) {
                if ($cle !== '' && StockPublic::normalizeKey($cle) === $norm) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return true; // Fail-safe : lecture impossible → refus de supprimer.
        }

        return false;
    }

    /**
     * Helper d'upsert générique (utilisé par les modèles d'admin).
     *
     * @param array<string,mixed> $fields
     */
    protected static function upsert(string $table, array $fields, bool $isNew): void
    {
        if ($isNew) {
            $cols = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', array_keys($fields)));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO ' . $table . ' (' . $cols . ') VALUES (' . $placeholders . ')'
            );
            $stmt->execute(array_values($fields));
        } else {
            $set = [];
            foreach (array_keys($fields) as $col) {
                if ($col === 'id') {
                    continue;
                }
                $set[] = '`' . $col . '` = ?';
            }
            $values = array_values(array_diff_key($fields, ['id' => null]));
            $values[] = $fields['id'];
            $stmt = static::pdo()->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }
    }
}
