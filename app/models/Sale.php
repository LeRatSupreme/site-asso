<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Ventes importées depuis les rapports SumUp (table `sales`).
 *
 * Les lignes de ventes sont IMMUABLES après import : on ne les modifie ni ne
 * les supprime. L'anti-doublon repose sur :
 *   1. un dédoublonnage en mémoire dans importBatch() (clé md5
 *      transaction_ref|sold_at|description|quantity|price_ttc) pour les
 *      occurrences au sein d'un même fichier ;
 *   2. la clé unique MySQL (transaction_ref, sold_at, description, quantity,
 *      price_ttc) via INSERT IGNORE : un réimport du même fichier (ou d'un
 *      fichier en chevauchement) n'insère aucune ligne supplémentaire.
 * La colonne description est NOT NULL DEFAULT '' : un NULL l'aurait fait
 * sortir de la clé unique (MySQL ignore les NULL dans les index UNIQUE).
 */
final class Sale extends Model
{
    protected static string $table = 'sales';

    /**
     * Insère un lot de lignes normalisées en ignorant les doublons
     * (INSERT IGNORE sur la clé unique de la ligne).
     *
     * - Les occurrences en double AU SEIN du fichier sont écartées avant
     *   insertion et comptées dans `file_duplicates`.
     * - L'ensemble tourne dans une transaction SQL : en cas d'échec, rien
     *   n'est inséré (pas de demi-import), l'exception est relancée.
     *
     * @param list<array<string,mixed>> $rows Lignes issues du parseur.
     * @return array{inserted:int, skipped:int, file_duplicates:int}
     *         inserted = nouvelles lignes, skipped = déjà présentes en base.
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
        $fileDuplicates = 0;
        $seen = [];

        try {
            $pdo->beginTransaction();

            foreach ($rows as $r) {
                $ref = (string) ($r['transaction_ref'] ?? '');
                $soldAt = (string) ($r['sold_at'] ?? '');
                $description = (string) ($r['description'] ?? '');
                $quantity = (int) ($r['quantity'] ?? 1);
                $priceTtc = number_format((float) ($r['price_ttc'] ?? 0), 2, '.', '');

                // Dédoublonnage dans le fichier : la 2e occurrence (et
                // suivantes) d'une ligne identique est écartée d'office.
                $dedupKey = md5($ref . '|' . $soldAt . '|' . $description . '|' . $quantity . '|' . $priceTtc);
                if (isset($seen[$dedupKey])) {
                    $fileDuplicates++;
                    continue;
                }
                $seen[$dedupKey] = true;

                $stmt->execute([
                    'sale_' . bin2hex(random_bytes(10)),
                    $ref,
                    $soldAt,
                    (string) ($r['payment_method'] ?? 'CARTE'),
                    $r['payment_raw'] ?? null,
                    $quantity,
                    $description,
                    $r['product_key'] ?? null,
                    $r['category'] ?? null,
                    $r['sku'] ?? null,
                    (($r['currency'] ?? '') !== '' ? $r['currency'] : 'EUR'),
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

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'file_duplicates' => $fileDuplicates];
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
     * Agrégats (CA, bénéfice, quantité) sur une plage de jours (bornes
     * incluses), ou sur tout l'historique si les bornes sont null.
     *
     * Même calcul que monthAggregates(), mais la période est libre.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return array{ca:float, profit:float, qty:int, ca_products:float}
     */
    public static function aggregatesBetween(?string $fromDay, ?string $toDay): array
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

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
                ' . $whereSql;

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);
            $row = $stmt->fetch() ?: [];
        } catch (\Throwable) {
            return ['ca' => 0.0, 'profit' => 0.0, 'qty' => 0, 'ca_products' => 0.0];
        }

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
     * Agrégats par catégorie sur une plage de jours (bornes incluses).
     *
     * Même agrégation que byCategory(), mais le filtre porte sur des
     * jours explicites plutôt que sur une année/un mois.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array<string,mixed>>
     */
    public static function byCategoryBetween(?string $fromDay, ?string $toDay): array
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
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

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
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
     * Agrégats par produit canonique sur une plage de jours (bornes
     * incluses), ou sur tout l'historique si les bornes sont null.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array<string,mixed>>
     */
    public static function byProductBetween(?string $fromDay, ?string $toDay): array
    {
        $where = ['is_custom_amount = 0'];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
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

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
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
     * Répartition du CA par moyen de paiement sur une plage de jours
     * (bornes incluses), ou sur tout l'historique si les bornes sont null.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return array<string,float>  ex: ['CARTE' => 123.4, 'LIQUIDE' => 12.0]
     */
    public static function paymentSplitBetween(?string $fromDay, ?string $toDay): array
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = 'SELECT payment_method, COALESCE(SUM(price_ttc), 0) AS ca
                FROM sales
                ' . $whereSql . '
                GROUP BY payment_method';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return ['CARTE' => 0.0, 'LIQUIDE' => 0.0];
        }

        $split = ['CARTE' => 0.0, 'LIQUIDE' => 0.0];
        foreach ($rows as $row) {
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
     * Journal filtrable des ventes sur une plage de jours (bornes incluses),
     * ou sur tout l'historique si les bornes sont null.
     *
     * Combine les bornes de période avec les filtres catégorie / produit /
     * paiement existants.
     *
     * @param string|null $fromDay    Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay      Jour de fin « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $category   Filtre catégorie exacte (null = toutes).
     * @param string|null $productKey Filtre produit canonique (null = tous).
     * @param string|null $payment    Filtre moyen de paiement (null = tous).
     *
     * @return list<array<string,mixed>>
     */
    public static function journalBetween(
        ?string $fromDay,
        ?string $toDay,
        ?string $category,
        ?string $productKey,
        ?string $payment,
        int $limit = 500
    ): array {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
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

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Journal des ventes d'un produit canonique sur une plage de jours
     * (bornes incluses) : lignes dont product_key = clé OU description = clé.
     *
     * Utilisé par le détail d'un événement de trésorerie pour lister les
     * ventes rattachées au nom de l'événement (libellé du bouton SumUp).
     *
     * @param string $productKey Clé produit (ou libellé exact SumUp).
     * @param string $fromDay    Jour de début « YYYY-MM-DD » (inclus).
     * @param string $toDay      Jour de fin « YYYY-MM-DD » (inclus).
     *
     * @return list<array<string,mixed>>
     */
    public static function journalForProductBetween(string $productKey, string $fromDay, string $toDay, int $limit = 200): array
    {
        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 200;
        }

        try {
            $stmt = self::pdo()->prepare(
                'SELECT sold_at, transaction_ref, payment_method, description, product_key, quantity, price_ttc
                 FROM sales
                 WHERE (product_key = ? OR description = ?)
                   AND sold_at >= ?
                   AND sold_at <= ?
                 ORDER BY sold_at DESC
                 LIMIT ' . $limit
            );
            $stmt->execute([
                $productKey,
                $productKey,
                $fromDay . ' 00:00:00',
                $toDay . ' 23:59:59',
            ]);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
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
     * Top produits (CA) sur une plage de jours (bornes incluses), ou sur
     * tout l'historique si les bornes sont null.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array<string,mixed>>
     */
    public static function topProductsBetween(?string $fromDay, ?string $toDay, int $limit = 8): array
    {
        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 8;
        }

        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = 'SELECT COALESCE(product_key, description) AS label,
                       SUM(price_ttc) AS ca, SUM(quantity) AS qty
                FROM sales
                ' . $whereSql . '
                GROUP BY COALESCE(product_key, description)
                ORDER BY ca DESC
                LIMIT ' . $limit;

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
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
     * Consommation par produit canonique sur une période donnée.
     *
     * Contrairement à consumptionByProductKey (fenêtre glissante en mois),
     * cette méthode travaille sur une plage de dates explicite (bornes
     * incluses), ce qui permet d'analyser n'importe quelle période
     * (7 jours, 30 jours, année civile, tout l'historique…).
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return array<string,array{qty:int,category:string}>
     */
    public static function consumptionBetween(?string $fromDay, ?string $toDay): array
    {
        $where = ['is_custom_amount = 0'];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
        }

        $sql = 'SELECT COALESCE(product_key, description) AS product_key,
                       MAX(NULLIF(category, \'\')) AS category,
                       SUM(quantity) AS qty
                FROM sales
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY COALESCE(product_key, description)';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);
        } catch (\Throwable) {
            return [];
        }

        $byProduct = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) $row['product_key'];
            if ($key === '') {
                continue;
            }
            $byProduct[$key] = [
                'qty'       => (int) $row['qty'],
                'category'  => (string) ($row['category'] ?? '—'),
            ];
        }

        return $byProduct;
    }

    /**
     * Date de la toute première vente importée (ou null si aucune).
     */
    public static function firstSoldDay(): ?string
    {
        try {
            $day = self::pdo()->query('SELECT DATE(MIN(sold_at)) FROM sales')->fetchColumn();
        } catch (\Throwable) {
            return null;
        }

        return $day ? (string) $day : null;
    }

    /**
     * Descriptions distinctes non encore mappées (product_key IS NULL).
     *
     * @return list<array<string,mixed>>
     */
    public static function unmappedDescriptions(): array    {
        $sql = 'SELECT description, COUNT(*) AS occurrences, MAX(sold_at) AS last_seen
                FROM sales
                WHERE product_key IS NULL
                  AND description IS NOT NULL
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

    /**
     * Ventilation HT/TVA/TTC par taux de TVA (année obligatoire, mois optionnel).
     *
     * @return list<array<string,mixed>>
     */
    public static function vatByRate(?int $year, ?int $month): array
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

        $sql = 'SELECT COALESCE(vat_rate, "—") AS rate,
                       COALESCE(SUM(price_ht), 0) AS ht,
                       COALESCE(SUM(vat), 0) AS vat,
                       COALESCE(SUM(price_ttc), 0) AS ttc
                FROM sales
                ' . $whereSql . '
                GROUP BY COALESCE(vat_rate, "—")
                ORDER BY vat DESC';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Ventilation HT/TVA/TTC par taux de TVA sur une plage de jours
     * (bornes incluses), ou sur tout l'historique si les bornes sont null.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array<string,mixed>>
     */
    public static function vatByRateBetween(?string $fromDay, ?string $toDay): array
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = 'SELECT COALESCE(vat_rate, "—") AS rate,
                       COALESCE(SUM(price_ht), 0) AS ht,
                       COALESCE(SUM(vat), 0) AS vat,
                       COALESCE(SUM(price_ttc), 0) AS ttc
                FROM sales
                ' . $whereSql . '
                GROUP BY COALESCE(vat_rate, "—")
                ORDER BY vat DESC';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Part du CA produits couverte par un coût de revient connu.
     *
     * Une vente est « couverte » si le produit canonique
     * (COALESCE(product_key, description)) possède au moins un lot
     * dans product_costs (même fallback que COST_SUBQUERY).
     *
     * @return array{covered:float, uncovered:float}
     */
    public static function costCoverage(int $year, int $month): array
    {
        $sql = 'SELECT
                    COALESCE(SUM(
                        CASE WHEN EXISTS (
                            SELECT 1 FROM product_costs pc
                            WHERE pc.product_key = COALESCE(sales.product_key, sales.description)
                        ) THEN price_ttc ELSE 0 END
                    ), 0) AS covered,
                    COALESCE(SUM(
                        CASE WHEN NOT EXISTS (
                            SELECT 1 FROM product_costs pc
                            WHERE pc.product_key = COALESCE(sales.product_key, sales.description)
                        ) THEN price_ttc ELSE 0 END
                    ), 0) AS uncovered
                FROM sales
                WHERE YEAR(sold_at) = ? AND MONTH(sold_at) = ? AND is_custom_amount = 0';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute([$year, $month]);
            $row = $stmt->fetch() ?: [];
        } catch (\Throwable) {
            return ['covered' => 0.0, 'uncovered' => 0.0];
        }

        return [
            'covered'   => (float) ($row['covered'] ?? 0),
            'uncovered' => (float) ($row['uncovered'] ?? 0),
        ];
    }

    /**
     * Part du CA produits couverte par un coût de revient connu, sur une
     * plage de jours (bornes incluses) ou tout l'historique (bornes null).
     *
     * Une vente est « couverte » si le produit canonique
     * (COALESCE(product_key, description)) possède au moins un lot
     * dans product_costs (même fallback que COST_SUBQUERY).
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return array{covered:float, uncovered:float}
     */
    public static function costCoverageBetween(?string $fromDay, ?string $toDay): array
    {
        $where = ['is_custom_amount = 0'];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
        }

        $sql = 'SELECT
                    COALESCE(SUM(
                        CASE WHEN EXISTS (
                            SELECT 1 FROM product_costs pc
                            WHERE pc.product_key = COALESCE(sales.product_key, sales.description)
                        ) THEN price_ttc ELSE 0 END
                    ), 0) AS covered,
                    COALESCE(SUM(
                        CASE WHEN NOT EXISTS (
                            SELECT 1 FROM product_costs pc
                            WHERE pc.product_key = COALESCE(sales.product_key, sales.description)
                        ) THEN price_ttc ELSE 0 END
                    ), 0) AS uncovered
                FROM sales
                WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);
            $row = $stmt->fetch() ?: [];
        } catch (\Throwable) {
            return ['covered' => 0.0, 'uncovered' => 0.0];
        }

        return [
            'covered'   => (float) ($row['covered'] ?? 0),
            'uncovered' => (float) ($row['uncovered'] ?? 0),
        ];
    }

    /**
     * Statistiques de paniers (une transaction = un panier).
     *
     * @return array{baskets:int, avg_basket:float, avg_items:float}
     */
    public static function basketStats(?int $year, ?int $month): array
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

        $sql = 'SELECT COUNT(*) AS baskets,
                       COALESCE(AVG(t.total), 0) AS avg_basket,
                       COALESCE(AVG(t.items), 0) AS avg_items
                FROM (
                    SELECT transaction_ref, SUM(price_ttc) AS total, SUM(quantity) AS items
                    FROM sales
                    ' . $whereSql . '
                    GROUP BY transaction_ref
                ) t';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);
            $row = $stmt->fetch() ?: [];
        } catch (\Throwable) {
            return ['baskets' => 0, 'avg_basket' => 0.0, 'avg_items' => 0.0];
        }

        return [
            'baskets'    => (int) ($row['baskets'] ?? 0),
            'avg_basket' => round((float) ($row['avg_basket'] ?? 0), 2),
            'avg_items'  => round((float) ($row['avg_items'] ?? 0), 2),
        ];
    }

    /**
     * Produits vendus à perte sur un mois (prix moyen unitaire < coût applicable).
     *
     * @return list<array<string,mixed>>
     */
    public static function lossLeaders(int $year, int $month): array
    {
        $sql = 'SELECT COALESCE(product_key, description) AS product,
                       AVG(price_ttc / NULLIF(quantity, 0)) AS avg_price,
                       IFNULL((' . self::COST_SUBQUERY . '), 0) AS cost,
                       SUM(quantity) AS qty
                FROM sales
                WHERE YEAR(sold_at) = ? AND MONTH(sold_at) = ? AND is_custom_amount = 0
                GROUP BY COALESCE(product_key, description)
                HAVING cost > 0 AND avg_price < cost
                ORDER BY (cost - avg_price) DESC
                LIMIT 10';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute([$year, $month]);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Produits vendus à perte sur une plage de jours (bornes incluses),
     * ou sur tout l'historique si les bornes sont null.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array<string,mixed>>
     */
    public static function lossLeadersBetween(?string $fromDay, ?string $toDay): array
    {
        $where = ['is_custom_amount = 0'];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'sold_at >= ?';
            $args[] = $fromDay . ' 00:00:00';
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'sold_at <= ?';
            $args[] = $toDay . ' 23:59:59';
        }

        $sql = 'SELECT COALESCE(product_key, description) AS product,
                       AVG(price_ttc / NULLIF(quantity, 0)) AS avg_price,
                       IFNULL((' . self::COST_SUBQUERY . '), 0) AS cost,
                       SUM(quantity) AS qty
                FROM sales
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY COALESCE(product_key, description)
                HAVING cost > 0 AND avg_price < cost
                ORDER BY (cost - avg_price) DESC
                LIMIT 10';

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Quantité totale vendue d'un produit depuis une date ou datetime donné.
     *
     * Accepte « YYYY-MM-DD » (borne à 00:00:00) ou « YYYY-MM-DD HH:MM:SS »
     * (borne exacte, utilisée par l'inventaire pour ne déduire que les
     * ventes postérieures au comptage).
     *
     * @param string $since Jour ou datetime de borne inférieure (inclus).
     */
    public static function soldQtySince(string $productKey, string $since): int
    {
        // Granularité : une date seule borne au début du jour ; un
        // datetime complet (comptage d'inventaire) borne à la seconde.
        $bound = strlen($since) > 10 ? $since : $since . ' 00:00:00';

        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(quantity), 0)
                 FROM sales
                 WHERE (product_key = ? OR description = ?)
                   AND sold_at >= ?
                   AND is_custom_amount = 0'
            );
            $stmt->execute([$productKey, $productKey, $bound]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Date du dernier import de ventes (null si aucun).
     */
    public static function lastImportAt(): ?string
    {
        try {
            $at = self::pdo()->query('SELECT MAX(imported_at) FROM sales')->fetchColumn();
        } catch (\Throwable) {
            return null;
        }

        return $at ? (string) $at : null;
    }

    /**
     * Années distinctes présentes dans les ventes (desc).
     *
     * @return list<int>
     */
    public static function distinctYears(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT DISTINCT YEAR(sold_at) AS y FROM sales ORDER BY y DESC')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) $r['y'];
        }

        return $out;
    }
}
