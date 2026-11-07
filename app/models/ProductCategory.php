<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\AliasSuggester;

/**
 * Modèle des catégories de produits cafétéria.
 */
final class ProductCategory extends Model
{
    protected static string $table = 'product_categories';

    /**
     * Catégories actives, triées par ordre d'affichage.
     *
     * @return list<array<string,mixed>>
     */
    public static function active(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT * FROM product_categories WHERE is_active = 1 ORDER BY `order` ASC, name ASC'
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Toutes les catégories (admin), triées par ordre.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query('SELECT * FROM product_categories ORDER BY `order` ASC, name ASC');

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Distance d'édition maximale entre deux noms considérés comme quasi-doublons. */
    public const FUZZY_MAX_DISTANCE = 1;

    /** Longueur minimale (forme normalisée) pour appliquer la distance floue. */
    public const FUZZY_MIN_LENGTH = 4;

    /**
     * Crée la catégorie si absente, en ABSORBANT les quasi-doublons
     * (mission E — fin des catégories « Boissond »/« Evenement » créées
     * en double par l'import) :
     *
     *  1. le nom est normalisé (trim, minuscules, sans accents,
     *     séparateurs repliés — AliasSuggester::normalizeKey) et comparé
     *     à chaque catégorie existante : même forme normalisée → la
     *     catégorie EXISTANTE est réutilisée (son id, sa ligne), aucune
     *     nouvelle ligne ;
     *  2. sinon, distance de Levenshtein <= 1 entre formes normalisées
     *     avec les DEUX noms d'au moins 4 caractères → absorbée aussi
     *     (faute de frappe d'une lettre). Les mots courts (< 4) ne sont
     *     JAMAIS fusionnés à distance 1 (« Eau » ≠ « Eaux ») : règle
     *     prudente, deux vrais rayons courts peuvent différer d'une
     *     lettre ;
     *  3. sinon création en fin de liste (is_active = 1 : la carte
     *     publique n'affiche pas les groupes sans produits disponibles),
     *     AUDITÉE immédiatement — c'est cet audit qui distingue les
     *     catégories auto-créées ('category.ensure', purgeables une fois
     *     vides) des catégories créées/retouchées via le formulaire admin
     *     ('category.save', INTOUCHABLES — voir purgeAutoCreatedEmpty).
     *
     * $auto = true (défaut) : création automatique (import compta, mapping
     * d'alias) → audit 'category.ensure', source « synchro ».
     * $auto = false : création par un bouton admin explicite → audit
     * 'category.save', source « admin » (même trace que le formulaire
     * ProductCategory::save) → jamais purgeable.
     *
     * $userId alimente l'audit (la table n'a pas de colonne créateur).
     *
     * @return string|null id de la catégorie CRÉÉE, null si le nom est
     *                     vide ou absorbé par une catégorie existante
     *                     (aucune ligne créée dans les deux cas).
     */
    public static function ensure(string $name, ?string $userId = null, bool $auto = true): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $key = AliasSuggester::normalizeKey($name);

        $rows = static::pdo()
            ->query('SELECT id, name FROM product_categories')
            ->fetchAll();
        foreach ($rows as $row) {
            $existingKey = AliasSuggester::normalizeKey((string) ($row['name'] ?? ''));
            if ($existingKey === '') {
                continue;
            }

            // Même forme normalisée (casse/accents/séparateurs) : réutilise
            // la ligne existante, n'en crée jamais une seconde.
            if ($existingKey === $key) {
                return null;
            }

            // Quasi-doublon (une édition d'écart), noms assez longs pour
            // que la distance soit significative.
            if (
                strlen($key) >= self::FUZZY_MIN_LENGTH
                && strlen($existingKey) >= self::FUZZY_MIN_LENGTH
                && levenshtein($key, $existingKey) <= self::FUZZY_MAX_DISTANCE
            ) {
                return null;
            }
        }

        $order = (int) static::pdo()
            ->query('SELECT COALESCE(MAX(`order`), 0) + 1 FROM product_categories')
            ->fetchColumn();
        $id = 'cat_' . bin2hex(random_bytes(10));
        $stmt = static::pdo()->prepare(
            'INSERT INTO product_categories (id, name, `order`, is_active) VALUES (?,?,?,1)'
        );
        $stmt->execute([$id, $name, $order]);

        AuditLog::log(
            $auto ? 'category.ensure' : 'category.save',
            $userId,
            'product_category',
            $id,
            ['name' => $name, 'source' => $auto ? 'synchro' : 'admin']
        );

        return $id;
    }

    /**
     * Purge des catégories AUTO-créées (mission E) devenues VIDES à
     * l'issue d'un import : une catégorie posée par un import antérieur
     * dont l'alias a été renommé entre-temps n'a plus ni produit ni
     * alias et reste orpheline sur la carte.
     *
     * CONSERVATEUR par construction — une catégorie n'est supprimée que
     * si TOUTES les conditions tiennent :
     *  - tracée 'category.ensure' (créée par ensure() automatique — sans
     *    cette trace, historique antérieur ou création manuelle, elle
     *    est INTROUVABLE pour la purge : jamais touchée) ;
     *  - AUCUNE trace 'category.save' (le formulaire admin ou un bouton
     *    manuel l'a créée ou retouchée → pilotée à la main, jamais
     *    purgée) ;
     *  - 0 produit pointant dessus (products.category_id) ;
     *  - 0 alias la citant par nom (comparaison normalisée casse/accents
     *    — plus prudent que l'égalité exacte : un alias « Redbull »
     *    retient la catégorie « RedBull »).
     *
     * Chaque suppression est auditée ('category.purge').
     *
     * @return int nombre de catégories supprimées
     */
    public static function purgeAutoCreatedEmpty(?string $userId = null): int
    {
        $pdo = static::pdo();

        // Catégories créées par ensure() automatique vs. créées/retouchées
        // via le formulaire/boutons admin (distingue par AuditLog).
        $autoCreated = self::auditedEntityIds('category.ensure');
        $manuallyTouched = self::auditedEntityIds('category.save');

        // Alias citant une catégorie, par forme normalisée.
        $aliasKeys = [];
        foreach (ProductAlias::distinctCategories() as $aliasCategory) {
            $aliasKeys[AliasSuggester::normalizeKey($aliasCategory)] = true;
        }

        $rows = $pdo->query('SELECT id, name FROM product_categories')->fetchAll();
        $products = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
        $purged = 0;
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if (!isset($autoCreated[$id]) || isset($manuallyTouched[$id])) {
                continue;
            }

            $nameKey = AliasSuggester::normalizeKey((string) ($row['name'] ?? ''));
            if (isset($aliasKeys[$nameKey])) {
                continue;
            }

            $products->execute([$id]);
            if ((int) $products->fetchColumn() > 0) {
                continue;
            }

            $stmt = $pdo->prepare('DELETE FROM product_categories WHERE id = ?');
            $stmt->execute([$id]);
            AuditLog::log(
                'category.purge',
                $userId,
                'product_category',
                $id,
                ['name' => (string) ($row['name'] ?? ''), 'reason' => 'auto-créée vide après import']
            );
            $purged++;
        }

        return $purged;
    }

    /**
     * Ids d'entités product_category portant une action d'audit donnée.
     *
     * @return array<string,true> id => true
     */
    private static function auditedEntityIds(string $action): array
    {
        $stmt = static::pdo()->prepare(
            "SELECT DISTINCT entity_id FROM audit_logs
             WHERE action = ? AND entity_type = 'product_category' AND entity_id IS NOT NULL"
        );
        $stmt->execute([$action]);

        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $ids[(string) $row['entity_id']] = true;
        }

        return $ids;
    }

    /**
     * Crée ou met à jour une catégorie (upsert par id).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'cat_' . bin2hex(random_bytes(10));
        }

        $fields = [
            'id'          => $id,
            'name'        => $data['name'] ?? '',
            'description' => $data['description'] ?? null,
            'image'       => $data['image'] ?? null,
            'order'       => (int) ($data['order'] ?? 0),
            'is_active'   => isset($data['is_active']) ? ((int) $data['is_active']) : 1,
        ];

        if ($isNew) {
            $cols = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', array_keys($fields)));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO product_categories (' . $cols . ') VALUES (' . $placeholders . ')'
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
            $values[] = $id;
            $stmt = static::pdo()->prepare('UPDATE product_categories SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM product_categories WHERE id = ?');
        $stmt->execute([$id]);
    }
}
