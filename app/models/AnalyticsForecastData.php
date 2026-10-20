<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\Forecast\DayCurrent;
use App\Core\Compta\Forecast\ForecastDataset;
use App\Core\Compta\Forecast\ProductStat;

/**
 * Couche d'EXTRACTION de données pour le moteur de prévision du dashboard
 * Analytics (consomme les DTO de App\Core\Compta\Forecast).
 *
 * FLUX COURANT = ventes « normales » d'une journée d'ouverture :
 *   is_custom_amount = 0 (les « Divers » à montant libre sont hors périmètre)
 *   ET COALESCE(category,'') NOT IN (self::EXCEPTIONAL_CATEGORIES) (« Événement »)
 *   ET price_ttc > 0 (les lignes à prix ≤ 0 sont des transactions de test :
 *   comptées dans audit(), jamais dans les séries).
 *
 * Un jour SANS transaction du flux courant est un jour FERMÉ : il n'apparaît
 * jamais dans currentFlowDays (donc jamais de CA à 0 dans le jeu d'entraînement).
 *
 * Coûts : même logique « as-of » que Sale::COST_SUBQUERY / Analytics::COST_SUBQUERY
 * mais à la date du JOUR (aujourd'hui), pas à la date de vente :
 *   1. lot couvrant aujourd'hui (valid_from <= CURDATE() ET valid_to NULL ou
 *      >= aujourd'hui), le plus récent ;
 *   2. sinon le dernier lot antérieur (trou / après valid_to) ;
 *   3. sinon NULL (uniquement des lots futurs = coût inconnu).
 * Implémenté en PHP depuis les lignes de product_costs (table petite) pour
 * partager exactement la même map entre productStats() et audit().
 *
 * Toutes les méthodes utilisent PDO préparé, try/catch et renvoient des
 * tableaux (jamais null), style Analytics.
 */
final class AnalyticsForecastData extends Model
{
    protected static string $table = 'sales';

    /** Catégories exclues du flux courant (catégories d'alias événementiel). */
    public const EXCEPTIONAL_CATEGORIES = ['Événement'];

    /**
     * Fragment WHERE du flux courant (sans la fenêtre temporelle).
     * Les placeholders sont remplis par self::flowArgs().
     */
    private static function flowWhere(): string
    {
        $notIn = implode(', ', array_fill(0, count(self::EXCEPTIONAL_CATEGORIES), '?'));

        return "is_custom_amount = 0 AND COALESCE(category, '') NOT IN ({$notIn}) AND price_ttc > 0";
    }

    /**
     * Arguments du fragment flowWhere() (les catégories exclues).
     *
     * @return list<string>
     */
    private static function flowArgs(): array
    {
        return self::EXCEPTIONAL_CATEGORIES;
    }

    /**
     * Flux courant par jour : CA, nombre de transactions (refs distincts),
     * répartition horaire des transactions et montants par panier.
     *
     * Seuls les jours avec au moins une transaction du flux courant sont
     * retournés (jour sans vente = fermé, jamais CA 0).
     *
     * @param string $from Date de début (YYYY-MM-DD, incluse).
     * @param string $to   Date de fin (YYYY-MM-DD, incluse).
     * @return array<string,DayCurrent>  Indexé par date « Y-m-d ».
     */
    public static function currentFlowDays(string $from, string $to): array
    {
        $flow = self::flowWhere();
        $args = array_merge(self::flowArgs(), [$from, $to]);

        // 1) CA + nb transactions par jour.
        $sqlDays = 'SELECT DATE(sold_at) AS d,
                           COALESCE(SUM(price_ttc), 0) AS ca,
                           COUNT(DISTINCT transaction_ref) AS n
                    FROM sales
                    WHERE ' . $flow . '
                      AND DATE(sold_at) >= ? AND DATE(sold_at) <= ?
                    GROUP BY d
                    ORDER BY d ASC';

        try {
            $stmt = self::pdo()->prepare($sqlDays);
            $stmt->execute($args);
            $dayRows = $stmt->fetchAll();
        } catch (\Throwable) {
            $dayRows = [];
        }

        if ($dayRows === []) {
            return [];
        }

        // 2) Nb transactions par jour ET heure (heure murale Paris).
        $sqlHours = 'SELECT DATE(sold_at) AS d, HOUR(sold_at) AS h,
                            COUNT(DISTINCT transaction_ref) AS n
                     FROM sales
                     WHERE ' . $flow . '
                       AND DATE(sold_at) >= ? AND DATE(sold_at) <= ?
                     GROUP BY d, h';

        try {
            $stmt = self::pdo()->prepare($sqlHours);
            $stmt->execute($args);
            $hourRows = $stmt->fetchAll();
        } catch (\Throwable) {
            $hourRows = [];
        }

        // 3) Montant par transaction (panier) : une valeur par ref et jour.
        $sqlBaskets = 'SELECT DATE(sold_at) AS d, transaction_ref,
                              COALESCE(SUM(price_ttc), 0) AS amount
                       FROM sales
                       WHERE ' . $flow . '
                         AND DATE(sold_at) >= ? AND DATE(sold_at) <= ?
                       GROUP BY d, transaction_ref';

        try {
            $stmt = self::pdo()->prepare($sqlBaskets);
            $stmt->execute($args);
            $basketRows = $stmt->fetchAll();
        } catch (\Throwable) {
            $basketRows = [];
        }

        // Assemblage AVANT instanciation : les DTO sont readonly.
        $cas = [];
        $ns = [];
        foreach ($dayRows as $r) {
            $d = (string) ($r['d'] ?? '');
            $n = (int) ($r['n'] ?? 0);
            if ($d === '' || $n < 1) {
                continue; // Jour fermé : jamais retourné.
            }
            $cas[$d] = (float) ($r['ca'] ?? 0);
            $ns[$d]  = $n;
        }

        $hourly = [];
        foreach ($hourRows as $r) {
            $d = (string) ($r['d'] ?? '');
            if (!isset($ns[$d])) {
                continue;
            }
            $h = (int) ($r['h'] ?? -1);
            if ($h >= 0 && $h <= 23) {
                $hourly[$d][$h] = (int) ($r['n'] ?? 0);
            }
        }

        $baskets = [];
        foreach ($basketRows as $r) {
            $d = (string) ($r['d'] ?? '');
            if (isset($ns[$d])) {
                $baskets[$d][] = (float) ($r['amount'] ?? 0);
            }
        }

        $days = [];
        foreach ($ns as $d => $n) {
            $days[$d] = new DayCurrent(
                date: $d,
                ca: $cas[$d],
                n: $n,
                hourlyN: $hourly[$d] ?? [],
                baskets: $baskets[$d] ?? [],
            );
        }

        return $days;
    }

    /**
     * Statistiques par produit sur le flux courant (clé = COALESCE(product_key,
     * description), même clé que les lots de coûts).
     *
     * - qty / ca : sommes sur la fenêtre.
     * - unitPrice : MÉDIANE exacte des couples prix×quantité observés
     *   (price_ttc / quantity par ligne de vente) — robuste aux rappels de prix.
     * - category : mode (catégorie la plus fréquente ; à égalité, la première
     *   rencontrée) ; NULL/'' normalisé en « Non classé ».
     * - unitCost : coût as-of AUJOURD'HUI (voir docblock de classe) ; NULL si
     *   aucun lot couvrant ni antérieur.
     * - stock : stock référencé (product_stocks), NULL si inconnu OU si le
     *   produit est marqué « stock infini » (product_infinite).
     * - costSuspect : coût inconnu (NULL), coût ≤ 0, ou prix unitaire < coût.
     *   ATTENTION : un prix unitaire inconnu (NULL) avec un coût > 0 n'est PAS
     *   suspect (prix inconnu ≠ incohérent) — mais il est signalé si le coût
     *   est lui-même inconnu ou nul.
     *
     * @return list<ProductStat>  Trié par CA décroissant.
     */
    public static function productStats(string $from, string $to): array
    {
        $sql = 'SELECT COALESCE(product_key, description) AS pkey,
                       description, category, quantity, price_ttc
                FROM sales
                WHERE ' . self::flowWhere() . '
                  AND DATE(sold_at) >= ? AND DATE(sold_at) <= ?
                ORDER BY sold_at ASC, id ASC';

        $args = array_merge(self::flowArgs(), [$from, $to]);

        try {
            $stmt = self::pdo()->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }

        if ($rows === []) {
            return [];
        }

        // Agrégation en mémoire (la médiane et le mode exigent les lignes).
        $qty = [];
        $ca = [];
        $unitPrices = []; // pkey => list<float> price_ttc/quantity
        $labels = [];     // pkey => list<string> descriptions
        $categories = []; // pkey => list<string> catégories normalisées
        foreach ($rows as $r) {
            $key = (string) ($r['pkey'] ?? '');
            if ($key === '') {
                continue;
            }
            $price = (float) ($r['price_ttc'] ?? 0);
            $quantity = (int) ($r['quantity'] ?? 0);

            $qty[$key] = ($qty[$key] ?? 0) + $quantity;
            $ca[$key]  = ($ca[$key] ?? 0.0) + $price;

            if ($quantity > 0) {
                $unitPrices[$key][] = $price / $quantity;
            }
            $labels[$key][] = (string) ($r['description'] ?? '');
            $categories[$key][] = (($r['category'] ?? '') !== '' ? (string) $r['category'] : 'Non classé');
        }

        $costs = self::currentCostMap(array_keys($ca));
        $stocks = ProductStock::allMap();
        $infinite = ProductInfinite::keys();

        $out = [];
        foreach ($ca as $key => $productCa) {
            $unitPrice = self::median($unitPrices[$key] ?? []);
            $unitCost  = $costs[$key] ?? null;
            $stock     = in_array($key, $infinite, true) ? null : ($stocks[$key] ?? null);

            $out[] = new ProductStat(
                key: $key,
                label: self::mode($labels[$key] ?? []) ?? $key,
                category: self::mode($categories[$key] ?? []) ?? 'Non classé',
                qty: (int) ($qty[$key] ?? 0),
                ca: (float) $productCa,
                unitPrice: $unitPrice,
                unitCost: $unitCost,
                costSuspect: $unitCost === null
                    || $unitCost <= 0.0
                    || ($unitPrice !== null && $unitPrice < $unitCost),
                stock: $stock,
            );
        }

        usort($out, static fn (ProductStat $a, ProductStat $b): int => $b->ca <=> $a->ca);

        return $out;
    }

    /**
     * Audit qualité des données de la fenêtre.
     *
     * - ventes_zero : lignes price_ttc <= 0 (toutes catégories, montants
     *   personnalisés inclus) — transactions de test, exclues des séries.
     * - doublons_ignores : somme de import_batches.rows_skipped (lignes
     *   écartées par l'anti-doublon lors des imports, cf. ImportBatch::finalize)
     *   des lots rattachés à la fenêtre : lot dont [period_start, period_end]
     *   chevauche [from, to] ; à défaut de période connue (anciens imports),
     *   lot importé (DATE(imported_at)) dans la fenêtre.
     * - produits_cout_suspect : labels des produits suspects, cohérents avec
     *   productStats() (même définition de costSuspect).
     *
     * @return array{ventes_zero:int, doublons_ignores:int, produits_cout_suspect:list<string>}
     */
    public static function audit(string $from, string $to): array
    {
        // 1) Lignes à prix nul ou négatif (toutes catégories).
        $ventesZero = 0;
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COUNT(*) FROM sales
                 WHERE price_ttc <= 0 AND DATE(sold_at) >= ? AND DATE(sold_at) <= ?'
            );
            $stmt->execute([$from, $to]);
            $ventesZero = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $ventesZero = 0;
        }

        // 2) Doublons ignorés par les imports (compteur rows_skipped des lots).
        $doublons = 0;
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(rows_skipped), 0) FROM import_batches
                 WHERE rows_skipped IS NOT NULL
                   AND (
                     (period_start IS NOT NULL AND period_end IS NOT NULL
                       AND period_start <= ? AND period_end >= ?)
                     OR (period_start IS NULL AND period_end IS NULL
                       AND DATE(imported_at) >= ? AND DATE(imported_at) <= ?)
                   )'
            );
            $stmt->execute([$to, $from, $from, $to]);
            $doublons = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $doublons = 0;
        }

        // 3) Produits au coût suspect (mêmes règles que productStats).
        $suspects = [];
        foreach (self::productStats($from, $to) as $p) {
            if ($p->costSuspect) {
                $suspects[] = $p->label;
            }
        }

        return [
            'ventes_zero'          => $ventesZero,
            'doublons_ignores'     => $doublons,
            'produits_cout_suspect' => $suspects,
        ];
    }

    /**
     * Assemble le jeu de données complet du moteur de prévision.
     */
    public static function dataset(string $from, string $to): ForecastDataset
    {
        return new ForecastDataset(
            days: self::currentFlowDays($from, $to),
            products: self::productStats($from, $to),
            audit: self::audit($from, $to),
        );
    }

    /**
     * Date de la première vente importée (borne basse de la période « Tout »).
     * Délègue à Analytics::firstSaleDate().
     */
    public static function firstSaleDate(): string
    {
        return Analytics::firstSaleDate();
    }

    // -----------------------------------------------------------------
    //  Helpers privés
    // -----------------------------------------------------------------

    /**
     * Coût « as-of aujourd'hui » par clé produit, même priorité que
     * COST_SUBQUERY (lot couvrant, sinon dernier lot antérieur, sinon NULL).
     * Lit toute product_costs (table petite) et résout en PHP.
     *
     * @param list<string> $keys Clés produits (COALESCE(product_key, description)).
     * @return array<string,?float>  Coût courant, NULL si inconnu.
     */
    private static function currentCostMap(array $keys): array
    {
        $map = array_fill_keys($keys, null);

        try {
            $rows = self::pdo()
                ->query('SELECT product_key, cost_price, valid_from, valid_to FROM product_costs')
                ->fetchAll();
        } catch (\Throwable) {
            return $map;
        }

        try {
            $today = new \DateTimeImmutable('today');
        } catch (\Throwable) {
            return $map;
        }

        // Deux passes par lot : couvrant (prioritaire) sinon antérieur.
        $covering = [];
        $previous = [];
        foreach ($rows as $r) {
            $key = (string) ($r['product_key'] ?? '');
            if ($key === '' || !array_key_exists($key, $map)) {
                continue;
            }
            try {
                $from = new \DateTimeImmutable((string) ($r['valid_from'] ?? ''));
                $to   = ($r['valid_to'] ?? null) !== null
                    ? new \DateTimeImmutable((string) $r['valid_to'])
                    : null;
            } catch (\Throwable) {
                continue;
            }
            if ($from > $today) {
                continue; // Lot futur : jamais rétroactif.
            }
            $price = (float) ($r['cost_price'] ?? 0);
            if ($to === null || $today <= $to) {
                // Lot couvrant : garde le valid_from le plus récent.
                if (!isset($covering[$key]) || $from > $covering[$key]['from']) {
                    $covering[$key] = ['from' => $from, 'price' => $price];
                }
            }
            // Lot antérieur (couvrant ou non) : garde le valid_from le plus récent.
            if (!isset($previous[$key]) || $from > $previous[$key]['from']) {
                $previous[$key] = ['from' => $from, 'price' => $price];
            }
        }

        foreach (array_keys($map) as $key) {
            $map[$key] = ($covering[$key] ?? $previous[$key] ?? null)['price'] ?? null;
        }

        return $map;
    }

    /**
     * Médiane exacte d'une liste de valeurs (NULL si vide).
     *
     * @param list<float> $values
     */
    private static function median(array $values): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? (float) $values[$mid]
            : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
    }

    /**
     * Mode d'une liste (valeur la plus fréquente ; à égalité, la première
     * rencontrée — tri stable PHP 8). NULL si vide.
     *
     * @param list<string> $values
     */
    private static function mode(array $values): ?string
    {
        if ($values === []) {
            return null;
        }
        $counts = [];
        foreach ($values as $v) {
            $counts[$v] = ($counts[$v] ?? 0) + 1;
        }
        arsort($counts);

        $key = array_key_first($counts);

        return $key === null ? null : (string) $key;
    }
}
