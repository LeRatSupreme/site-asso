<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\AliasSuggester;
use App\Core\Compta\StockPublic;
use App\Core\Logger;

/**
 * Fusion de clés produits (doublons) : déplace TOUTES les données d'une
 * clé source vers une clé cible conservée, sans perte :
 *
 *  - UPDATE simple (PK `id`, pas de conflit possible) : purchases,
 *    losses, sales, product_aliases, inventory_counts, product_costs,
 *    promotions (product_key nullable : seules les lignes rattachées
 *    à la source sont déplacées) ;
 *  - PK `product_key` (une ligne par produit, fusion dédiée) :
 *    product_stocks (stock ADDITIONNÉ), product_discontinued,
 *    product_infinite, product_zero_since, product_packs,
 *    shopping_checks (la ligne de la cible fait foi si elle existe
 *    déjà : la ligne source est supprimée, jamais écrasée) ;
 *  - scan_labels : purge des libellés OCR dont la forme normalisée
 *    devient celle de la source (file « à classer », pas une donnée
 *    comptable) ;
 *  - fiches jumelles `products` (carte cafétéria) : repointage de
 *    cafeteria_order_items vers la fiche cible puis suppression de la
 *    fiche source (stock additionné), ou simple RENOMMAGE si seule la
 *    fiche source existe.
 *
 * Exemple : « Pulco Citronnade » (saisi par erreur dans les achats) →
 * « pulco » (clé canonique des ventes SumUp), ou « Cristaline » →
 * « Eau ».
 *
 * La normalisation (StockPublic::normalizeKey, partagée avec la carte
 * publique) sert à l'appariement des FICHES par nom et à la purge des
 * scan_labels ; la fusion opère elle-même sur les clés BRUTES saisies :
 * ce sont elles qui sont stockées en base, et consolider deux variantes
 * de casse (« Pulco » → « pulco ») est un cas d'usage légitime.
 *
 * Le cache de la carte publique est invalidé DANS merge() après commit :
 * aucun appelant ne peut l'oublier (le théorique a changé de clé).
 */
final class ProductKeyMerge extends Model
{
    protected static string $table = 'product_stocks';

    /**
     * Tables à PK `id` (sales, purchases, losses, product_aliases,
     * inventory_counts, product_costs, promotions) : un UPDATE par table
     * suffit — pas de conflit possible (product_aliases : PK id, UNIQUE
     * sur raw_description ; product_costs : plusieurs lots par produit ;
     * promotions : PK id, product_key nullable — l'UPDATE ne touche que
     * les lignes rattachées à la source).
     *
     * @var list<string>
     */
    private const SIMPLE_TABLES = ['purchases', 'losses', 'sales', 'product_aliases', 'inventory_counts', 'product_costs', 'promotions'];

    /**
     * Tables à PK `product_key` (une seule ligne par produit) fusionnées
     * mécaniquement : si la cible possède déjà sa ligne, elle fait foi et
     * la ligne source est supprimée (jamais d'écrasement de la cible) ;
     * sinon la ligne source est repointée vers la cible. Un UPDATE direct
     * violerait l'unicité de la PK quand la cible existe déjà.
     *
     * @var list<string>
     */
    private const UNIQUE_KEY_TABLES = ['product_infinite', 'product_zero_since', 'product_packs', 'shopping_checks'];

    /**
     * Deux clés sont-elles FUSIBLES (méthode pure, testée) : non vides
     * et DISTINCTES une fois normalisées (StockPublic::normalizeKey :
     * casse, accents, ponctuation et espaces ignorés) ? Pré-condition
     * de l'auto-fusion des fiches au nom long : « OASIS TROPICAL SLIM
     * BTE 33CL » -> « Oasis Tropical » est fusible ; un alias qui vise
     * la fiche elle-même (même clé normalisée) ne l'est pas — rien à
     * absorber, merge() refuserait de toute façon.
     */
    public static function canMerge(string $source, string $target): bool
    {
        $src = self::normalize($source);
        $tgt = self::normalize($target);

        return $src !== '' && $tgt !== '' && $src !== $tgt;
    }

    /**
     * Valide les paires (source → cible) du formulaire « Tout fusionner »
     * des doublons probables (méthode PURE, testée sans base) : reçoit les
     * tableaux bruts `pairs_from[]` / `pairs_to[]` du POST et ne rend que
     * les paires exploitables — indices appariés, chaînes non vides
     * (espaces rognés) de 255 caractères au plus (taille de la colonne
     * product_key) et distinctes en COMPARAISON BRUTE (même garde que
     * merge(), qui refuse seulement source === cible : consolider deux
     * variantes de casse — « Bowling » → « bowling », le cas courant de
     * la liste des doublons — est un cas d'usage légitime). Tout le reste
     * est jeté : le formulaire ne propose que des champs cachés générés
     * côté serveur depuis les paires AFFICHÉES, une entrée forgée ne peut
     * donc rien fusionner de plus.
     *
     * @return list<array{from:string,to:string}>
     */
    public static function validPairs(mixed $from, mixed $to): array
    {
        if (!is_array($from) || !is_array($to)) {
            return [];
        }

        $pairs = [];
        $count = min(count($from), count($to));
        for ($i = 0; $i < $count; $i++) {
            $source = is_string($from[$i] ?? null) ? trim((string) $from[$i]) : '';
            $target = is_string($to[$i] ?? null) ? trim((string) $to[$i]) : '';
            if ($source === '' || $target === '') {
                continue;
            }
            if (mb_strlen($source) > 255 || mb_strlen($target) > 255) {
                continue;
            }
            if ($source === $target) {
                continue;
            }
            $pairs[] = ['from' => $source, 'to' => $target];
        }

        return $pairs;
    }

    /**
     * Clé normalisée sans espaces (même repliement que partout ailleurs :
     * AliasSuggester::normalizeKey + retrait des espaces).
     */
    private static function normalize(string $value): string
    {
        return str_replace(' ', '', AliasSuggester::normalizeKey($value));
    }

    /**
     * Fusionne la clé source dans la clé cible, transactionnellement :
     * tout est déplacé ou rien ne l'est.
     *
     * @param string     $source Clé doublon à absorber (supprimée partout).
     * @param string     $target Clé canonique conservée.
     * @param string|null $userId Auteur de la fusion (audit du drapeau).
     *
     * @return array<string,int> Nombre de lignes déplacées par table :
     *                           purchases, losses, sales, aliases, counts,
     *                           costs, stocks, discontinued, promotions,
     *                           infinite, zero_since, packs, checks,
     *                           scan_labels, order_items, fiches.
     *
     * @throws \InvalidArgumentException Clé source vide ou identique à la cible.
     * @throws \Throwable                Erreur SQL (transaction annulée).
     */
    public static function merge(string $source, string $target, ?string $userId): array
    {
        $source = trim($source);
        $target = trim($target);

        if ($source === '') {
            throw new \InvalidArgumentException('La clé source est vide.');
        }
        if ($source === $target) {
            throw new \InvalidArgumentException('La clé source et la clé cible sont identiques.');
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $moved = [];

            // 1-7. Tables à PK id : déplacement simple des lignes.
            foreach (self::SIMPLE_TABLES as $table) {
                $stmt = $pdo->prepare(
                    'UPDATE `' . $table . '` SET product_key = ? WHERE product_key = ?'
                );
                $stmt->execute([$target, $source]);
                $moved[$table] = (int) $stmt->rowCount();
            }

            // 8. product_stocks (PK product_key) : fusion ADDITIVE du stock
            //    de référence (source 5 + cible 7 → cible 12), puis
            //    suppression de la ligne source.
            //    Lecture en deux temps : un INSERT ... SELECT depuis la
            //    table product_stocks elle-même rend « stock » ambigu
            //    dans ON DUPLICATE KEY UPDATE (erreur 1052 MySQL) — on
            //    lit donc le stock source, puis un INSERT ... VALUES sans
            //    SELECT l'ajoute à la cible.
            $stmt = $pdo->prepare('SELECT stock FROM product_stocks WHERE product_key = ?');
            $stmt->execute([$source]);
            $sourceStock = $stmt->fetchColumn();
            $stocks = 0;
            if ($sourceStock !== false) {
                $stmt = $pdo->prepare(
                    'INSERT INTO product_stocks (product_key, stock) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock), updated_at = NOW()'
                );
                $stmt->execute([$target, (int) $sourceStock]);
                $stocks = 1;
            }
            $pdo->prepare('DELETE FROM product_stocks WHERE product_key = ?')->execute([$source]);

            // 9. product_discontinued (PK product_key) : le drapeau « plus
            //    en vente » suit la cible (premier auteur conservé), puis
            //    suppression de la ligne source.
            $stmt = $pdo->prepare(
                'INSERT INTO product_discontinued (product_key, updated_by)
                 SELECT ?, COALESCE(updated_by, ?) FROM product_discontinued WHERE product_key = ?
                 ON DUPLICATE KEY UPDATE updated_by = VALUES(updated_by)'
            );
            $stmt->execute([$target, $userId, $source]);
            $discontinued = (int) $stmt->rowCount();
            $pdo->prepare('DELETE FROM product_discontinued WHERE product_key = ?')->execute([$source]);

            // 10. Drapeaux / réglages à PK product_key (stock infini, suivi
            //     « à zéro depuis », taille de pack, case de liste de
            //     courses) : si la cible a déjà sa ligne, elle fait foi
            //     (source supprimée, rien d'écrasé) ; sinon la ligne source
            //     est repointée.
            $flags = [];
            foreach (self::UNIQUE_KEY_TABLES as $table) {
                $flags[$table] = self::mergeUniqueKeyRow($pdo, $table, $source, $target);
            }

            // 11. scan_labels : purge des libellés OCR dont la forme
            //     normalisée devient celle de la source (la clé source
            //     n'existe plus ; ces entrées sont une file « à classer »,
            //     pas une donnée comptable — une purge manquée est sans
            //     conséquence, la table est donc optionnelle ici).
            $scanPurged = 0;
            try {
                $scanPurged = self::purgeScanLabels($pdo, $source);
            } catch (\Throwable $e) {
                Logger::warning('merge: purge scan_labels impossible', ['msg' => $e->getMessage()]);
            }

            // 12. Fiches jumelles `products` (carte cafétéria) : appariées
            //     par nom normalisé (casse/accents/espaces ignorés). Les
            //     DEUX existent → la caisse (cafeteria_order_items) est
            //     repointée vers la fiche cible, son stock est additionné,
            //     puis la fiche source est supprimée (désactivée en repli
            //     si une contrainte l'empêche). Seule la SOURCE existe →
            //     elle est RENOMMÉE en clé cible (id conservé : l'historique
            //     caisse suit). Seule la cible ou aucune → rien.
            ['fiches' => $fiches, 'order_items' => $orderItems] = self::mergeProductFiches($pdo, $source, $target);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        // La carte publique suit : le théorique a changé de clé.
        StockPublic::invalidate();

        return [
            'purchases'    => $moved['purchases'],
            'losses'       => $moved['losses'],
            'sales'        => $moved['sales'],
            'aliases'      => $moved['product_aliases'],
            'counts'       => $moved['inventory_counts'],
            'costs'        => $moved['product_costs'],
            'stocks'       => $stocks,
            'discontinued' => $discontinued,
            'promotions'   => $moved['promotions'],
            'infinite'     => $flags['product_infinite'],
            'zero_since'   => $flags['product_zero_since'],
            'packs'        => $flags['product_packs'],
            'checks'       => $flags['shopping_checks'],
            'scan_labels'  => $scanPurged,
            'order_items'  => $orderItems,
            'fiches'       => $fiches,
        ];
    }

    /**
     * Fusion d'une table à PK `product_key` (une ligne par produit) :
     * la ligne de la cible fait foi si elle existe déjà (la ligne source
     * est supprimée, rien n'est écrasé — un UPDATE direct violerait
     * l'unicité), sinon la ligne source est repointée vers la cible.
     *
     * @return int 1 si une ligne a été déplacée, 0 sinon.
     */
    private static function mergeUniqueKeyRow(\PDO $pdo, string $table, string $source, string $target): int
    {
        $stmt = $pdo->prepare('SELECT 1 FROM `' . $table . '` WHERE product_key = ? LIMIT 1');
        $stmt->execute([$target]);
        if ($stmt->fetchColumn() !== false) {
            $pdo->prepare('DELETE FROM `' . $table . '` WHERE product_key = ?')->execute([$source]);

            return 0;
        }

        $stmt = $pdo->prepare('UPDATE `' . $table . '` SET product_key = ? WHERE product_key = ?');
        $stmt->execute([$target, $source]);

        return (int) $stmt->rowCount();
    }

    /**
     * Supprime les scan_labels dont le libellé, une fois normalisé
     * (StockPublic::normalizeKey : casse, accents, séparateurs et
     * espaces ignorés), devient celui de la clé source absorbée.
     */
    private static function purgeScanLabels(\PDO $pdo, string $source): int
    {
        $norm = StockPublic::normalizeKey($source);
        if ($norm === '') {
            return 0;
        }

        /** @var list<array{id:string,label:string}> $rows */
        $rows = $pdo->query('SELECT id, label FROM scan_labels')->fetchAll(\PDO::FETCH_ASSOC);
        $del = $pdo->prepare('DELETE FROM scan_labels WHERE id = ?');
        $deleted = 0;
        foreach ($rows as $row) {
            if (StockPublic::normalizeKey((string) $row['label']) === $norm) {
                $del->execute([(string) $row['id']]);
                $deleted += $del->rowCount();
            }
        }

        return $deleted;
    }

    /**
     * Fusion des fiches jumelles de la table `products` (carte cafétéria),
     * appariées par nom normalisé contre les clés source et cible.
     *
     * @return array{fiches:int, order_items:int}
     */
    private static function mergeProductFiches(\PDO $pdo, string $source, string $target): array
    {
        $srcNorm = StockPublic::normalizeKey($source);
        $tgtNorm = StockPublic::normalizeKey($target);
        if ($srcNorm === '' || $tgtNorm === '') {
            return ['fiches' => 0, 'order_items' => 0];
        }

        /** @var list<array{id:string,name:string}> $rows */
        $rows = $pdo->query('SELECT id, name FROM products')->fetchAll(\PDO::FETCH_ASSOC);
        $srcFiche = null;
        $tgtFiche = null;
        foreach ($rows as $row) {
            $norm = StockPublic::normalizeKey((string) $row['name']);
            if ($srcFiche === null && $norm === $srcNorm) {
                $srcFiche = (string) $row['id'];
            } elseif ($tgtFiche === null && $norm === $tgtNorm) {
                $tgtFiche = (string) $row['id'];
            }
        }

        if ($srcFiche === null) {
            return ['fiches' => 0, 'order_items' => 0];
        }

        // Seule la source existe (ou une seule fiche couvre les deux clés
        // quand elles ne diffèrent que par la casse) : renommée en clé
        // cible, id conservé — l'historique caisse suit.
        if ($tgtFiche === null) {
            $pdo->prepare('UPDATE products SET name = ? WHERE id = ?')->execute([$target, $srcFiche]);

            return ['fiches' => 1, 'order_items' => 0];
        }

        // Les deux existent : la caisse suit la fiche cible (les lignes
        // restantes, créées entre-temps, passeraient sinon à NULL via la
        // FK ON DELETE SET NULL), le stock est additionné (aucune perte),
        // puis la fiche source disparaît.
        $stmt = $pdo->prepare('UPDATE cafeteria_order_items SET product_id = ? WHERE product_id = ?');
        $stmt->execute([$tgtFiche, $srcFiche]);
        $orderItems = (int) $stmt->rowCount();

        // Stock de la fiche ADDITIONNÉ à la cible (même logique que
        // product_stocks) : lecture en deux temps, un UPDATE ... + (SELECT
        // ...) sur la même table étant refusé par MySQL (erreur 1093).
        $stmt = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$srcFiche]);
        $srcStock = (int) $stmt->fetchColumn();
        $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$srcStock, $tgtFiche]);

        try {
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$srcFiche]);
        } catch (\Throwable $e) {
            // Repli : contrainte inattendue — la fiche source est
            // désactivée au lieu d'être supprimée (jamais de blocage).
            Logger::warning('merge: suppression fiche produit impossible, désactivation', [
                'id'  => $srcFiche,
                'msg' => $e->getMessage(),
            ]);
            $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$srcFiche]);
        }

        return ['fiches' => 1, 'order_items' => $orderItems];
    }

    /**
     * Toutes les clés produits existantes : UNION (distinct) du
     * product_key des 7 tables, trié, sans chaîne vide. Sert au datalist
     * de fusion et à la prévention de doublons à la saisie (achats,
     * pertes). Silencieux sans base (retourne []).
     *
     * @return list<string>
     */
    public static function allKeys(): array
    {
        $sql = 'SELECT product_key FROM purchases
                UNION
                SELECT product_key FROM losses
                UNION
                SELECT product_key FROM sales
                UNION
                SELECT product_key FROM product_aliases
                UNION
                SELECT product_key FROM inventory_counts
                UNION
                SELECT product_key FROM product_stocks
                UNION
                SELECT product_key FROM product_discontinued
                ORDER BY product_key';

        try {
            /** @var list<array-key,mixed> $rows */
            $rows = self::pdo()->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $k) {
            $key = trim((string) $k);
            if ($key !== '') {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Statistiques par clé produit (nombre de lignes par table) : sert à
     * la prévisualisation de la fusion dans la vue Inventaire — l'utilisateur
     * voit ce que déplacerait chaque fusion AVANT de la valider.
     *
     * Clé absente du résultat = clé inconnue de toutes les tables.
     *
     * @return array<string, array{sales:int, purchases:int, losses:int, counts:int, costs:int, aliases:int, stock:?int, discontinued:bool, promotions:int, checks:int, infinite:int, zero_since:int, packs:int, fiche:int}>
     */
    public static function keyStats(): array
    {
        $queries = [
            'sales'      => 'SELECT product_key, COUNT(*) FROM sales GROUP BY product_key',
            'purchases'  => 'SELECT product_key, COUNT(*) FROM purchases GROUP BY product_key',
            'losses'     => 'SELECT product_key, COUNT(*) FROM losses GROUP BY product_key',
            'counts'     => 'SELECT product_key, COUNT(*) FROM inventory_counts GROUP BY product_key',
            'costs'      => 'SELECT product_key, COUNT(*) FROM product_costs GROUP BY product_key',
            'aliases'    => 'SELECT product_key, COUNT(*) FROM product_aliases GROUP BY product_key',
            'promotions' => 'SELECT product_key, COUNT(*) FROM promotions WHERE product_key IS NOT NULL GROUP BY product_key',
            'checks'     => 'SELECT product_key, COUNT(*) FROM shopping_checks GROUP BY product_key',
            'infinite'   => 'SELECT product_key, COUNT(*) FROM product_infinite GROUP BY product_key',
            'zero_since' => 'SELECT product_key, COUNT(*) FROM product_zero_since GROUP BY product_key',
        ];

        $stats = [];
        try {
            foreach ($queries as $field => $sql) {
                /** @var list<list<string|int>> $rows */
                $rows = self::pdo()->query($sql)->fetchAll(\PDO::FETCH_NUM);
                foreach ($rows as $row) {
                    $key = trim((string) $row[0]);
                    if ($key === '') {
                        continue;
                    }
                    $stats[$key] ??= self::emptyStat();
                    $stats[$key][$field] = (int) $row[1];
                }
            }
        } catch (\Throwable) {
            // Silencieux : la carte de fusion doit s'afficher même sans base.
        }

        // Taille de pack (valeur utile pour l'aperçu, pas le nombre de lignes).
        try {
            /** @var list<list<string|int>> $rows */
            $rows = self::pdo()
                ->query('SELECT product_key, pack_size FROM product_packs WHERE pack_size > 0')
                ->fetchAll(\PDO::FETCH_NUM);
            foreach ($rows as $row) {
                $key = trim((string) $row[0]);
                if ($key === '') {
                    continue;
                }
                $stats[$key] ??= self::emptyStat();
                $stats[$key]['packs'] = (int) $row[1];
            }
        } catch (\Throwable) {
            // Silencieux : table absente sur une installation ancienne.
        }

        foreach (ProductStock::allMap() as $key => $stock) {
            $stats[$key] ??= self::emptyStat();
            $stats[$key]['stock'] = $stock;
        }
        foreach (ProductDiscontinued::keys() as $key) {
            $stats[$key] ??= self::emptyStat();
            $stats[$key]['discontinued'] = true;
        }

        // Fiches jumelles de la carte (nom exact = clé).
        try {
            /** @var list<array{name:string}> $rows */
            $rows = self::pdo()->query('SELECT name FROM products')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $key = trim((string) $row['name']);
                if ($key === '') {
                    continue;
                }
                $stats[$key] ??= self::emptyStat();
                $stats[$key]['fiche']++;
            }
        } catch (\Throwable) {
            // Silencieux : table absente sur une installation ancienne.
        }

        ksort($stats, SORT_STRING);

        return $stats;
    }

    /**
     * Compteurs à zéro pour une clé nouvellement rencontrée.
     *
     * @return array{sales:int, purchases:int, losses:int, counts:int, costs:int, aliases:int, stock:?int, discontinued:bool, promotions:int, checks:int, infinite:int, zero_since:int, packs:int, fiche:int}
     */
    private static function emptyStat(): array
    {
        return [
            'sales'        => 0,
            'purchases'    => 0,
            'losses'       => 0,
            'counts'       => 0,
            'costs'        => 0,
            'aliases'      => 0,
            'stock'        => null,
            'discontinued' => false,
            'promotions'   => 0,
            'checks'       => 0,
            'infinite'     => 0,
            'zero_since'   => 0,
            'packs'        => 0,
            'fiche'        => 0,
        ];
    }
}
