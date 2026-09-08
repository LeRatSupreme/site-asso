<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Ventes importées depuis les rapports SumUp (table `sales`).
 *
 * Les lignes de ventes sont IMMUABLES après import : on ne les modifie ni ne
 * les supprime. L'anti-doublon repose sur la clé unique MySQL
 * (transaction_ref, sold_at, description, quantity, price_ttc) ; un réimport
 * du même fichier n'insère donc aucune ligne supplémentaire.
 */
final class Sale extends Model
{
    protected static string $table = 'sales';

    /**
     * Insère un lot de lignes normalisées en ignorant les doublons
     * (INSERT IGNORE sur la clé unique de la ligne).
     *
     * @param list<array<string,mixed>> $rows Lignes issues du parseur.
     * @return array{inserted:int, skipped:int}
     */
    public static function importBatch(string $batchId, array $rows): array
    {
        $pdo = self::pdo();

        $sql = 'INSERT IGNORE INTO sales
                (id, transaction_ref, sold_at, payment_method, payment_raw, quantity,
                 description, product_key, category, sku, currency, price_ttc, price_ht,
                 vat, vat_rate, seller_account, is_custom_amount, import_batch_id, imported_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())';

        $stmt = $pdo->prepare($sql);

        $inserted = 0;
        $skipped = 0;

        foreach ($rows as $r) {
            $stmt->execute([
                'sale_' . bin2hex(random_bytes(10)),
                (string) ($r['transaction_ref'] ?? ''),
                (string) ($r['sold_at'] ?? null),
                (string) ($r['payment_method'] ?? 'CARTE'),
                $r['payment_raw'] ?? null,
                (int) ($r['quantity'] ?? 1),
                $r['description'] ?? null,
                $r['product_key'] ?? null,
                $r['category'] ?? null,
                $r['sku'] ?? null,
                $r['currency'] !== '' ? $r['currency'] : 'EUR',
                $r['price_ttc'] ?? 0,
                $r['price_ht'] ?? null,
                $r['vat'] ?? null,
                $r['vat_rate'] ?? null,
                $r['seller_account'] ?? null,
                (int) ($r['is_custom_amount'] ?? 0),
                $batchId,
            ]);

            if ($stmt->rowCount() === 1) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Regroupe les ventes de plusieurs clés produit vers une clé canonique.
     *
     * Cible les lignes dont product_key = ancienne clé, OU dont product_key
     * est NULL avec une description strictement égale à l'ancienne clé
     * (le libellé brut servait alors de clé canonique par défaut).
     *
     * @param list<string> $oldKeys Clés à fusionner dans $keep.
     * @return int Nombre de ventes re-groupées.
     */
    public static function mergeInto(string $keep, array $oldKeys): int
    {
        $oldKeys = array_values(array_unique(array_filter(array_map('trim', $oldKeys), static fn ($k): bool => $k !== '' && $k !== $keep)));
        if ($oldKeys === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($oldKeys), '?'));
        $args = array_merge([$keep], $oldKeys, $oldKeys);

        $stmt = self::pdo()->prepare(
            'UPDATE sales SET product_key = ?
              WHERE product_key IN (' . $placeholders . ')
                 OR (product_key IS NULL AND description IN (' . $placeholders . '))'
        );
        $stmt->execute($args);

        return $stmt->rowCount();
    }

    /**
     * Nombre total de ventes importées.
     */
    public static function count(): int
    {
        try {
            return (int) self::pdo()->query('SELECT COUNT(*) FROM sales')->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Fragment SQL donnant le coût unitaire applicable à une vente
     * (lot valide à la date de la vente). Renvoie 0 si aucun lot.
     */
    private const COST_SUBQUERY = '
        SELECT pc.cost_price
        FROM product_costs pc
        WHERE pc.product_key = COALESCE(sales.product_key, sales.description)
        ORDER BY
            CASE
                WHEN pc.valid_from <= DATE(sales.sold_at)
                     AND (pc.valid_to IS NULL OR DATE(sales.sold_at) < pc.valid_to)
                THEN 0
                ELSE 1
            END,
            pc.valid_from DESC
        LIMIT 1';

    /**
     * Agrégats (CA, bénéfice, quantité) pour un mois donné.
     *
     * @return array{ca:float, profit:float, qty:int, ca_products:float}
     */
    public static function monthAggregates(int $year, int $month): array
    {
        $sql = 'SELECT
                    COALESCE(SUM(price_ttc), 0) AS ca,
                    COALESCE(SUM(
                        CASE WHEN is_custom_amount = 0
                             THEN price_ttc - IFNULL((' . self::COST_SUBQUERY . '), 0) * quantity
                             ELSE 0 END
                    ), 0) AS profit,
                    COALESCE(SUM(quantity), 0) AS qty,
                    COALESCE(SUM(CASE WHEN is_custom_amount = 0 THEN price_ttc ELSE 0 END), 0) AS ca_products
                FROM sales
                WHERE YEAR(sold_at) = ? AND MONTH(sold_at) = ?';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([$year, $month]);
        $row = $stmt->fetch() ?: [];

        return [
            'ca'          => (float) ($row['ca'] ?? 0),
            'profit'      => (float) ($row['profit'] ?? 0),
            'qty'         => (int) ($row['qty'] ?? 0),
            'ca_products' => (float) ($row['ca_products'] ?? 0),
        ];
    }

    /**
     * CA par mois pour une année donnée.
     *
     * @return list<array<string,mixed>>
     */
    public static function byMonth(int $year): array
    {
        $sql = 'SELECT
                    MONTH(sold_at) AS m,
                    COALESCE(SUM(price_ttc), 0) AS ca,
                    COALESCE(SUM(
                        CASE WHEN is_custom_amount = 0
                             THEN price_ttc - IFNULL((' . self::COST_SUBQUERY . '), 0) * quantity
                             ELSE 0 END
                    ), 0) AS profit,
                    COALESCE(SUM(quantity), 0) AS qty
                FROM sales
                WHERE YEAR(sold_at) = ?
                GROUP BY MONTH(sold_at)
                ORDER BY MONTH(sold_at)';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([$year]);

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Agrégats par catégorie pour un mois donné (ou toute l'année si null).
     *
     * @return list<array<string,mixed>>
     */
    public static function byCategory(?int $year, ?int $month): array
    {
        $where = [];
        $args = [];
        if ($year !== null) {
            $where[] = 'YEAR(sold_at) = ?';
            $args[] = $year;
        }
        if ($month !== null) {
            $where[] = 'MONTH(sold_at) = ?';
            $args[] = $month;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = 'SELECT
                    COALESCE(NULLIF(category, ""), "Non classé") AS category,
                    COALESCE(SUM(price_ttc), 0) AS ca,
                    COALESCE(SUM(
                        CASE WHEN is_custom_amount = 0
                             THEN price_ttc - IFNULL((' . self::COST_SUBQUERY . '), 0) * quantity
                             ELSE 0 END
                    ), 0) AS profit,
                    COALESCE(SUM(quantity), 0) AS qty
                FROM sales
                ' . $whereSql . '
                GROUP BY COALESCE(NULLIF(category, ""), "Non classé")
                ORDER BY ca DESC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Agrégats par produit canonique pour un mois donné.
     *
     * @return list<array<string,mixed>>
     */
    public static function byProduct(int $year, ?int $month): array
    {
        $where = ['YEAR(sold_at) = ?', 'is_custom_amount = 0'];
        $args = [$year];
        if ($month !== null) {
            $where[] = 'MONTH(sold_at) = ?';
            $args[] = $month;
        }

        $sql = 'SELECT
                    COALESCE(product_key, description) AS product_key,
                    COALESCE(NULLIF(category, ""), "Non classé") AS category,
                    SUM(quantity) AS qty,
                    SUM(price_ttc) AS ca,
                    AVG(price_ttc / NULLIF(quantity, 0)) AS avg_price,
                    IFNULL((' . self::COST_SUBQUERY . '), 0) AS cost_price,
                    SUM(price_ttc - IFNULL((' . self::COST_SUBQUERY . '), 0) * quantity) AS profit
                FROM sales
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY COALESCE(product_key, description)
                ORDER BY ca DESC';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Répartition du CA par moyen de paiement pour un mois donné.
     *
     * @return array<string,float>  ex: ['CARTE' => 123.4, 'LIQUIDE' => 12.0]
     */
    public static function paymentSplit(int $year, ?int $month): array
    {
        $where = ['YEAR(sold_at) = ?'];
        $args = [$year];
        if ($month !== null) {
            $where[] = 'MONTH(sold_at) = ?';
            $args[] = $month;
        }

        $sql = 'SELECT payment_method, COALESCE(SUM(price_ttc), 0) AS ca
                FROM sales
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY payment_method';

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);

        $split = ['CARTE' => 0.0, 'LIQUIDE' => 0.0];
        foreach ($stmt->fetchAll() as $row) {
            $split[(string) $row['payment_method']] = (float) $row['ca'];
        }

        return $split;
    }

    /**
     * Journal filtrable des ventes.
     *
     * @return list<array<string,mixed>>
     */
    public static function journal(
        ?int $year,
        ?int $month,
        ?string $category,
        ?string $productKey,
        ?string $payment,
        int $limit = 500
    ): array {
        $where = [];
        $args = [];
        if ($year !== null) {
            $where[] = 'YEAR(sold_at) = ?';
            $args[] = $year;
        }
        if ($month !== null) {
            $where[] = 'MONTH(sold_at) = ?';
            $args[] = $month;
        }
        if ($category !== null && $category !== '') {
            $where[] = 'category = ?';
            $args[] = $category;
        }
        if ($productKey !== null && $productKey !== '') {
            $where[] = '(product_key = ? OR description = ?)';
            $args[] = $productKey;
            $args[] = $productKey;
        }
        if ($payment !== null && $payment !== '') {
            $where[] = 'payment_method = ?';
            $args[] = $payment;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 500;
        }

        $sql = 'SELECT *,
                    IFNULL((' . self::COST_SUBQUERY . '), 0) AS cost_price,
                    (CASE WHEN is_custom_amount = 0
                          THEN price_ttc - IFNULL((' . self::COST_SUBQUERY . '), 0) * quantity
                          ELSE 0 END) AS profit
                FROM sales
                ' . $whereSql . '
                ORDER BY sold_at DESC, id DESC
                LIMIT ' . $limit;

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($args);

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Top produits (CA) pour un mois donné.
     *
     * @return list<array<string,mixed>>
     */
    public static function topProducts(int $year, int $month, int $limit = 8): array
    {
        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 8;
        }

        $sql = 'SELECT COALESCE(product_key, description) AS label,
                       SUM(price_ttc) AS ca, SUM(quantity) AS qty
                FROM sales
                WHERE YEAR(sold_at) = ? AND MONTH(sold_at) = ?
                GROUP BY COALESCE(product_key, description)
                ORDER BY ca DESC
                LIMIT ' . $limit;

        $stmt = self::pdo()->prepare($sql);
        $stmt->execute([$year, $month]);

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Consommation mensuelle par produit canonique sur les N derniers mois.
     *
     * @return array<string,array{qty:int, monthly:list<int>}>
     */
    public static function consumptionByProductKey(int $monthsWindow = 3): array
    {
        $window = $monthsWindow > 0 ? $monthsWindow : 3;

        // Quantités par produit × mois sur les N derniers mois.
        $sql = 'SELECT COALESCE(product_key, description) AS product_key,
                       MAX(NULLIF(category, \'\')) AS category,
                       YEAR(sold_at) AS y, MONTH(sold_at) AS m,
                       SUM(quantity) AS qty
                FROM sales
                WHERE sold_at >= DATE_SUB(CURDATE(), INTERVAL ' . $window . ' MONTH)
                  AND is_custom_amount = 0
                GROUP BY COALESCE(product_key, description), YEAR(sold_at), MONTH(sold_at)';

        try {
            $rows = self::pdo()->query($sql)->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $byProduct = [];
        foreach ($rows as $row) {
            $key = (string) $row['product_key'];
            $byProduct[$key]['monthly'][] = (int) $row['qty'];
            $byProduct[$key]['qty'] = ($byProduct[$key]['qty'] ?? 0) + (int) $row['qty'];
            // Catégorie (cohérente pour un même produit ; on prend la 1ère non vide).
            if (!isset($byProduct[$key]['category']) && !empty($row['category'])) {
                $byProduct[$key]['category'] = (string) $row['category'];
            }
        }

        return $byProduct;
    }

    /**
     * Descriptions distinctes non encore mappées (product_key IS NULL).
     *
     * @return list<array<string,mixed>>
     */
    public static function unmappedDescriptions(): array
    {
        $sql = 'SELECT description, COUNT(*) AS occurrences, MAX(sold_at) AS last_seen
                FROM sales
                WHERE product_key IS NULL
                  AND description IS NOT NULL
                  AND description NOT LIKE "%Montant personnalisé%"
                GROUP BY description
                ORDER BY occurrences DESC, last_seen DESC';

        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()->query($sql)->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Toutes les descriptions distinctes (mappées ou non) avec occurrences.
     *
     * Utilisé pour l'auto-détection de doublons : on suggère un mapping pour
     * chaque libellé rencontré, qu'il soit déjà aliasé ou non.
     *
     * @return list<array<string,mixed>>
     */
    public static function allDescriptions(): array
    {
        $sql = 'SELECT description, COUNT(*) AS occurrences, MAX(sold_at) AS last_seen
                FROM sales
                WHERE description IS NOT NULL
                  AND description <> ""
                  AND description NOT LIKE "%Montant personnalisé%"
                GROUP BY description
                ORDER BY occurrences DESC, last_seen DESC';

        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()->query($sql)->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Ré-applique tous les aliases aux ventes existantes.
     *
     * Pour chaque alias (raw_description -> product_key), on met à jour les
     * ventes dont la `description` correspond, que `product_key` soit NULL ou
     * déjà renseigné (utile quand la clé canonique d'un alias change).
     *
     * @return int Nombre total de lignes affectées.
     */
    public static function reapplyAliases(): int
    {
        $pdo = self::pdo();

        try {
            $rows = $pdo->query('SELECT raw_description, product_key FROM product_aliases')->fetchAll();
        } catch (\Throwable) {
            return 0;
        }

        $stmt = $pdo->prepare('UPDATE sales SET product_key = ? WHERE description = ?');

        $count = 0;
        foreach ($rows as $a) {
            $raw = trim((string) ($a['raw_description'] ?? ''));
            $key = trim((string) ($a['product_key'] ?? ''));
            if ($raw === '' || $key === '') {
                continue;
            }
            $stmt->execute([$key, $raw]);
            $count += (int) $stmt->rowCount();
        }

        return $count;
    }

    /**
     * Liste les catégories distinctes présentes dans les ventes.
     *
     * @return list<string>
     */
    public static function distinctCategories(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT DISTINCT category FROM sales WHERE category IS NOT NULL AND category <> "" ORDER BY category')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = (string) $r['category'];
        }

        return $out;
    }

    /**
     * Liste les produits canoniques distincts (product_key ou description).
     *
     * @return list<string>
     */
    public static function distinctProducts(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT DISTINCT COALESCE(product_key, description) AS p FROM sales ORDER BY p')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = (string) $r['p'];
        }

        return $out;
    }
}
