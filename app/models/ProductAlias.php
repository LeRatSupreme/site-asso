<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Mapping libellés CSV SumUp -> produit canonique (table `product_aliases`).
 *
 * Chaque libellé brut rencontré dans un rapport (ex: « Bueno_white »,
 * « Bueno », « cristaline ») est rattaché une fois pour toutes à un
 * product_key canonique. Permet des statistiques fiables malgré les
 * variations de saisie.
 */
final class ProductAlias extends Model
{
    protected static string $table = 'product_aliases';

    /**
     * Tous les alias, triés par product_key puis libellé brut.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query('SELECT * FROM product_aliases ORDER BY product_key, raw_description')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Résout un libellé brut en product_key canonique (correspondance exacte).
     */
    public static function resolve(string $description): ?string
    {
        $stmt = self::pdo()->prepare(
            'SELECT product_key FROM product_aliases WHERE raw_description = ? LIMIT 1'
        );
        $stmt->execute([$description]);
        $row = $stmt->fetch();

        return $row ? (string) $row['product_key'] : null;
    }

    /**
     * Récupère l'alias correspondant à un libellé brut.
     *
     * @return array<string,mixed>|null
     */
    public static function findByRaw(string $rawDescription): ?array
    {
        $stmt = self::pdo()->prepare(
            'SELECT * FROM product_aliases WHERE raw_description = ? LIMIT 1'
        );
        $stmt->execute([$rawDescription]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Crée ou met à jour un alias (upsert sur raw_description).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data): string
    {
        $raw = trim((string) ($data['raw_description'] ?? ''));
        $productKey = trim((string) ($data['product_key'] ?? ''));
        $category = ($data['category'] ?? '') !== '' ? (string) $data['category'] : null;

        if ($raw === '' || $productKey === '') {
            return '';
        }

        $existing = self::findByRaw($raw);
        if ($existing !== null) {
            $stmt = self::pdo()->prepare(
                'UPDATE product_aliases SET product_key = ?, category = ? WHERE id = ?'
            );
            $stmt->execute([$productKey, $category, $existing['id']]);

            return (string) $existing['id'];
        }

        $id = 'alias_' . bin2hex(random_bytes(10));
        $stmt = self::pdo()->prepare(
            'INSERT INTO product_aliases (id, raw_description, product_key, category, created_at)
             VALUES (?,?,?,?, NOW())'
        );
        $stmt->execute([$id, $raw, $productKey, $category]);

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = self::pdo()->prepare('DELETE FROM product_aliases WHERE id = ?');
        $stmt->execute([$id]);
    }
}
