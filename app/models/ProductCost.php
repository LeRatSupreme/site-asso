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
     *  3. sinon (date antérieure au premier lot) null : coût inconnu avant
     *     le premier lot = 0 (pas de rétroactivité).
     *
     * Aux étapes 1-2, jamais un lot dont valid_from est postérieur à la
     * date : créer un lot ne modifie pas rétroactivement les calculs des
     * ventes déjà couvertes.
     *
     * Départage DÉTERMINISTE à valid_from égal (deux lots ouverts le même
     * jour, ex. une même course saisie deux fois sous deux orthographes
     * puis fusionnées) : created_at puis id — la saisie la plus récente
     * gagne (mêmes règles que ComptaCalc::selectCostLot et
     * normalizePeriods), jamais un choix dépendant du plan SQL.
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
             ORDER BY valid_from DESC, created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$productKey, $day, $day]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row;
        }

        // 2) Lot précédent (trou entre deux lots, ou après le dernier).
        //    Aucun lot antérieur → null (vente avant le premier lot).
        $stmt = $pdo->prepare(
            'SELECT * FROM product_costs
             WHERE product_key = ? AND valid_from <= ?
             ORDER BY valid_from DESC, created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$productKey, $day]);
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
     * La clôture et l'INSERT sont transactionnels : jamais un état où le
     * précédent est encore ouvert et le nouveau absent.
     *
     * @param array<string,mixed> $data purchase_id optionnel : id de l'achat
     *                                  (purchases.id) à l'origine du lot,
     *                                  pour la suppression en cascade —
     *                                  absent/'' pour un lot manuel.
     *                                  qty_purchased optionnel : quantité
     *                                  achetée du lot (NULL/absent = pas de
     *                                  suivi de quantité, ex. anciens lots).
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
        $qtyPurchased = self::qtyPurchasedOrNull($data);

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Clôture du lot précédent ouvert (veille du nouveau valid_from).
            $pdo->prepare(
                'UPDATE product_costs
                    SET valid_to = DATE_SUB(?, INTERVAL 1 DAY)
                  WHERE product_key = ? AND valid_to IS NULL'
            )->execute([$validFrom, $productKey]);

            $id = 'cost_' . bin2hex(random_bytes(10));
            $pdo->prepare(
                'INSERT INTO product_costs (id, product_key, cost_price, qty_purchased, valid_from, valid_to, supplier, notes, purchase_id, created_at)
                 VALUES (?,?,?,?,?, NULL, ?, ?, ?, NOW())'
            )->execute([$id, $productKey, $costPrice, $qtyPurchased, $validFrom, $supplier, $notes, $purchaseId]);

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return $id;
    }

    /**
     * Lit la clé `qty_purchased` d'un tableau de données : présente et
     * non vide → int, absente/NULL/'' → NULL (pas de suivi de quantité).
     *
     * @param array<string,mixed> $data
     */
    private static function qtyPurchasedOrNull(array $data): ?int
    {
        $qty = $data['qty_purchased'] ?? null;
        if ($qty === null || $qty === '') {
            return null;
        }

        return (int) $qty;
    }

    /**
     * Clôt manuellement un lot (valid_to = hier).
     *
     * GREATEST(valid_from, …) : un lot créé aujourd'hui ne peut pas être
     * clôturé avant son propre début (valid_to = valid_from dans ce cas).
     * La clôture peut rouvrir une période sur le lot suivant : les
     * périodes du produit sont rééquilibrées (normalizePeriods).
     */
    public static function close(string $id): bool
    {
        $stmt = self::pdo()->prepare(
            'UPDATE product_costs
                SET valid_to = GREATEST(valid_from, DATE_SUB(CURDATE(), INTERVAL 1 DAY))
              WHERE id = ?'
        );
        $stmt->execute([$id]);
        $closed = $stmt->rowCount() === 1;

        $lot = self::find($id);
        if ($lot !== null) {
            self::normalizePeriods((string) $lot['product_key']);
        }

        return $closed;
    }

    /**
     * Met à jour un lot existant (coût unitaire, date de début,
     * fournisseur, quantité achetée).
     *
     * qty_purchased : mise à jour seulement si la clé est présente dans
     * $data (NULL accepté = arrêter le suivi de quantité) ; absente, la
     * quantité du lot n'est pas touchée.
     *
     * Changer valid_from peut chevaucher le lot suivant : les périodes
     * du produit sont rééquilibrées après l'UPDATE (normalizePeriods).
     *
     * @param array<string,mixed> $data
     */
    public static function update(string $id, array $data): bool
    {
        $lot = self::find($id);
        if ($lot === null) {
            return false;
        }

        $validFrom = substr((string) ($data['valid_from'] ?? ''), 0, 10);
        if ($validFrom === '') {
            return false;
        }

        $costPrice = (float) ($data['cost_price'] ?? 0);
        $supplier = ($data['supplier'] ?? '') !== '' ? trim((string) $data['supplier']) : null;

        if (array_key_exists('qty_purchased', $data)) {
            self::pdo()->prepare(
                'UPDATE product_costs
                    SET cost_price = ?, valid_from = ?, supplier = ?, qty_purchased = ?
                  WHERE id = ?'
            )->execute([$costPrice, $validFrom, $supplier, self::qtyPurchasedOrNull($data), $id]);
        } else {
            self::pdo()->prepare(
                'UPDATE product_costs
                    SET cost_price = ?, valid_from = ?, supplier = ?
                  WHERE id = ?'
            )->execute([$costPrice, $validFrom, $supplier, $id]);
        }

        self::normalizePeriods((string) $lot['product_key']);

        return true;
    }

    /**
     * Supprime un lot (utile pour nettoyer les doublons / saisies erronées).
     *
     * Si le lot supprimé était « en cours » (valid_to NULL) et qu'aucun
     * lot ouvert ne reste, le lot antérieur le plus récent du même
     * produit est réouvert (valid_to = NULL) : sans cela le produit
     * perdrait tout coût applicable et les ventes suivantes n'auraient
     * plus de coût de revient. Les périodes restantes sont ensuite
     * rééquilibrées (normalizePeriods). Supprimer un lot déjà clôturé ne
     * change pas la chaîne.
     */
    public static function delete(string $id): bool
    {
        $pdo = self::pdo();

        // État avant suppression : la réouverture ne concerne que les lots
        // en cours.
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
            $productKey = (string) $lot['product_key'];

            // Aucun lot ouvert restant ET au moins un autre lot : le plus
            // récent redevient le lot courant (départage déterministe).
            if (!self::hasOpenLot($productKey) && self::hasAnyLot($productKey)) {
                $pdo->prepare(
                    'UPDATE product_costs
                        SET valid_to = NULL
                      WHERE product_key = ?
                      ORDER BY valid_from DESC, created_at DESC, id DESC
                      LIMIT 1'
                )->execute([$productKey]);
            }

            self::normalizePeriods($productKey);
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
     * Invariant « au plus un lot en cours » pour un produit.
     *
     * Répare les états impossibles créés par l'historique (fusions brutes
     * de product_key, update() de valid_from sans rééquilibrage, close()
     * sans garde) en rééquilibrant les périodes :
     *
     *  - lots triés par valid_from ASC, created_at ASC, id ASC (même
     *    départage déterministe que lotAt) ;
     *  - chaque lot sauf le dernier doit finir AVANT le début du lot
     *    suivant : ouvert (valid_to NULL) → fermé à la veille du
     *    valid_from suivant ; valid_to >= valid_from suivant (chevauché)
     *    → clampé à cette même veille, jamais en dessous de son propre
     *    valid_from (clampé à valid_from dans le cas extrême d'un
     *    valid_from égal au lot suivant) ;
     *  - un valid_to déjà antérieur au lot suivant (trou volontaire)
     *    est conservé ;
     *  - le dernier lot reste tel quel (ouvert ou clôturé manuellement).
     *
     * N'écrit que les UPDATE nécessaires (comparaison avant écriture) et
     * les regroupe en transaction quand le PDO le permet (l'appel peut
     * survenir dans la transaction d'une fusion : elle s'y intègre).
     */
    public static function normalizePeriods(string $productKey): void
    {
        $lots = self::orderedLots($productKey);
        $count = count($lots);
        if ($count < 2) {
            return;
        }

        $updates = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $from = substr((string) $lots[$i]['valid_from'], 0, 10);
            $currentTo = $lots[$i]['valid_to'] === null
                ? null
                : substr((string) $lots[$i]['valid_to'], 0, 10);
            $nextFrom = substr((string) $lots[$i + 1]['valid_from'], 0, 10);

            if ($currentTo !== null && $currentTo < $nextFrom) {
                // Trou volontaire (ou déjà conforme) : rien à faire.
                continue;
            }

            // Cible : la veille du lot suivant. Pour un lot déjà daté
            // (clôturé/chevauché), jamais avant son propre début (lots à
            // valid_from égal : clamp au valid_from) ; un lot ouvert est
            // pris tel quel, le départage created_at/id tranche le jour
            // dégénéré où valid_from du suivant est égal au sien.
            $target = date('Y-m-d', strtotime($nextFrom . ' -1 day'));
            if ($currentTo !== null && $target < $from) {
                $target = $from;
            }

            if ($target !== $currentTo) {
                $updates[] = [(string) $lots[$i]['id'], $target];
            }
        }

        if ($updates === []) {
            return;
        }

        $pdo = self::pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stmt = $pdo->prepare('UPDATE product_costs SET valid_to = ? WHERE id = ?');
            foreach ($updates as [$id, $validTo]) {
                $stmt->execute([$validTo, $id]);
            }

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Lots d'un produit dans l'ordre chronologique de normalisation :
     * valid_from ASC puis created_at/id ASC (départage déterministe).
     *
     * @return list<array<string,mixed>>
     */
    private static function orderedLots(string $productKey): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT * FROM product_costs
              WHERE product_key = ?
              ORDER BY valid_from ASC, created_at ASC, id ASC'
        );
        $stmt->execute([$productKey]);

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /** Le produit a-t-il au moins un lot « en cours » (valid_to NULL) ? */
    private static function hasOpenLot(string $productKey): bool
    {
        $stmt = self::pdo()->prepare(
            'SELECT 1 FROM product_costs WHERE product_key = ? AND valid_to IS NULL LIMIT 1'
        );
        $stmt->execute([$productKey]);

        return $stmt->fetchColumn() !== false;
    }

    /** Le produit a-t-il au moins un lot (quelconque) ? */
    private static function hasAnyLot(string $productKey): bool
    {
        $stmt = self::pdo()->prepare(
            'SELECT 1 FROM product_costs WHERE product_key = ? LIMIT 1'
        );
        $stmt->execute([$productKey]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Ré-affecte tous les lots d'un produit vers une autre clé canonique
     * (fusion de doublons). Les dates/périodes des lots sont conservées,
     * mais les périodes des deux clés sont rééquilibrées : le déplacement
     * peut faire cohabiter deux lots ouverts sur la cible (la source,
     * elle, peut conserver ses propres lots non déplacés).
     */
    public static function reassign(string $from, string $to): int
    {
        $stmt = self::pdo()->prepare(
            'UPDATE product_costs SET product_key = ? WHERE product_key = ?'
        );
        $stmt->execute([$to, $from]);
        $moved = $stmt->rowCount();

        self::normalizePeriods($to);
        if ($from !== $to) {
            self::normalizePeriods($from);
        }

        return $moved;
    }
}
