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
     * Lot applicable à un produit à une date donnée — règle « as-of » :
     *  1. lot couvrant la date (valid_from <= date ET valid_to NULL ou
     *     date <= valid_to), le plus récent ;
     *  2. sinon (trou / après le dernier lot) le lot précédent, c'est-à-dire
     *     le plus récent avec valid_from <= date ;
     *  3. sinon (date antérieure au premier lot) le lot le plus ancien connu.
     *
     * Aux étapes 1-2, jamais un lot dont valid_from est postérieur à la
     * date : créer un lot ne modifie pas rétroactivement les calculs des
     * ventes déjà couvertes. L'étape 3 est le cas voulu « vente antérieure
     * au premier lot → premier lot connu ».
     *
     * @return array<string,mixed>|null
     */
    public static function lotAt(string $productKey, string $date): ?array
    {
        $day = substr($date, 0, 10);
        $pdo = self::pdo();

        // 1) Lot couvrant la date, le plus récent.
        $stmt = $pdo->prepare(
            'SELECT * FROM product_costs
             WHERE product_key = ? AND valid_from <= ?
               AND (valid_to IS NULL OR ? <= valid_to)
             ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$productKey, $day, $day]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }

        // 2) Lot précédent (trou entre deux lots, ou après le dernier).
        $stmt = $pdo->prepare(
            'SELECT * FROM product_costs
             WHERE product_key = ? AND valid_from <= ?
             ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$productKey, $day]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }

        // 3) Lot le plus ancien connu (date antérieure au premier lot).
        $stmt = $pdo->prepare(
            'SELECT * FROM product_costs
             WHERE product_key = ?
             ORDER BY valid_from ASC LIMIT 1'
        );
        $stmt->execute([$productKey]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * Coût unitaire applicable à un produit à une date donnée
     * (même règle « as-of » que lotAt()).
     */
    public static function costAt(string $productKey, string $date): ?string
    {
        $lot = self::lotAt($productKey, $date);

        return $lot === null ? null : (string) $lot['cost_price'];
    }

    /**
     * Lot actuellement en cours pour un produit : le lot applicable à
     * aujourd'hui (même règle « as-of », date = aujourd'hui).
     *
     * @return array<string,mixed>|null
     */
    public static function current(string $productKey): ?array
    {
        return self::lotAt($productKey, date('Y-m-d'));
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
     * @param array<string,mixed> $data purchase_id optionnel : id de l'achat
     *                                  (purchases.id) à l'origine du lot,
     *                                  pour la suppression en cascade —
     *                                  absent/'' pour un lot manuel.
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
        $purchaseId = ($data['purchase_id'] ?? '') !== '' ? (string) $data['purchase_id'] : null;

        // Clôture du lot précédent ouvert (veille du nouveau valid_from).
        $pdo->prepare(
            'UPDATE product_costs
                SET valid_to = DATE_SUB(?, INTERVAL 1 DAY)
              WHERE product_key = ? AND valid_to IS NULL'
        )->execute([$validFrom, $productKey]);

        $id = 'cost_' . bin2hex(random_bytes(10));
        $pdo->prepare(
            'INSERT INTO product_costs (id, product_key, cost_price, valid_from, valid_to, supplier, notes, purchase_id, created_at)
             VALUES (?,?,?,?, NULL, ?, ?, ?, NOW())'
        )->execute([$id, $productKey, $costPrice, $validFrom, $supplier, $notes, $purchaseId]);

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

    /**
     * Met à jour un lot existant (coût unitaire, date de début, fournisseur).
     *
     * @param array<string,mixed> $data
     */
    public static function update(string $id, array $data): bool
    {
        if (self::find($id) === null) {
            return false;
        }

        $validFrom = substr((string) ($data['valid_from'] ?? ''), 0, 10);
        if ($validFrom === '') {
            return false;
        }

        $costPrice = (float) ($data['cost_price'] ?? 0);
        $supplier = ($data['supplier'] ?? '') !== '' ? trim((string) $data['supplier']) : null;

        self::pdo()->prepare(
            'UPDATE product_costs
                SET cost_price = ?, valid_from = ?, supplier = ?
              WHERE id = ?'
        )->execute([$costPrice, $validFrom, $supplier, $id]);

        return true;
    }

    /**
     * Supprime un lot (utile pour nettoyer les doublons / saisies erronées).
     *
     * Si le lot supprimé était « en cours » (valid_to NULL), le lot
     * antérieur le plus récent du même produit est réouvert (valid_to =
     * NULL) : sans cela le produit perdrait tout coût applicable et les
     * ventes suivantes n'auraient plus de coût de revient.
     */
    public static function delete(string $id): bool
    {
        $pdo = self::pdo();

        // État avant suppression : la réouverture ne concerne que les lots
        // en cours (supprimer un lot déjà clôturé ne change pas la chaîne).
        $stmt = $pdo->prepare('SELECT product_key, valid_to FROM product_costs WHERE id = ?');
        $stmt->execute([$id]);
        $lot = $stmt->fetch();
        if ($lot === false) {
            return false;
        }

        $del = $pdo->prepare('DELETE FROM product_costs WHERE id = ?');
        $del->execute([$id]);
        if ($del->rowCount() !== 1) {
            return false;
        }

        if (($lot['valid_to'] ?? null) === null) {
            self::reopenPreviousLot((string) $lot['product_key']);
        }

        return true;
    }

    /**
     * Supprime tous les lots liés à un achat (product_costs.purchase_id).
     *
     * Cascade de la suppression d'un achat : chaque lot supprimé « en
     * cours » fait réouvrir le lot antérieur du produit (via self::delete).
     *
     * @return int Nombre de lots supprimés.
     */
    public static function deleteByPurchase(string $purchaseId): int
    {
        if ($purchaseId === '') {
            return 0;
        }

        $stmt = self::pdo()->prepare('SELECT id FROM product_costs WHERE purchase_id = ?');
        $stmt->execute([$purchaseId]);

        $deleted = 0;
        foreach ($stmt->fetchAll() as $row) {
            if (self::delete((string) $row['id'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Réouvre le lot le plus récent restant d'un produit (valid_to = NULL).
     *
     * Le lot supprimé était en cours : aucun lot postérieur ne peut exister
     * (il l'aurait clôturé), le plus récent restant redevient donc le lot
     * courant. Inutile si le produit n'a plus aucun lot (aucune ligne mise
     * à jour).
     */
    private static function reopenPreviousLot(string $productKey): void
    {
        self::pdo()->prepare(
            'UPDATE product_costs
                SET valid_to = NULL
              WHERE product_key = ?
              ORDER BY valid_from DESC, created_at DESC
              LIMIT 1'
        )->execute([$productKey]);
    }

    /**
     * Ré-affecte tous les lots d'un produit vers une autre clé canonique
     * (fusion de doublons). Les dates/périodes des lots sont conservées.
     */
    public static function reassign(string $from, string $to): int
    {
        $stmt = self::pdo()->prepare(
            'UPDATE product_costs SET product_key = ? WHERE product_key = ?'
        );
        $stmt->execute([$to, $from]);

        return $stmt->rowCount();
    }
}
