<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Kiosques personnalisables (table `kiosques`).
 *
 * Chaque kiosque est créé par l'admin : un nom, un jeton secret (le lien
 * EST l'authentification) et la liste des tuiles autorisées, stockée en
 * JSON (ids du catalogue App\Core\Compta\KioskPages).
 * Cette classe ne fait que le CRUD : le catalogue des tuiles et la garde
 * centralisée des jetons vivent dans KioskPages.
 */
final class Kiosque extends Model
{
    protected static string $table = 'kiosques';

    /**
     * Crée un kiosque (id et jeton générés aléatoirement).
     *
     * @param list<string> $pages ids de tuiles autorisées
     * @return string l'id du kiosque créé
     */
    public static function create(string $name, array $pages, string $createdBy = ''): string
    {
        $id = 'kq_' . bin2hex(random_bytes(8));

        self::pdo()->prepare(
            'INSERT INTO kiosques (id, name, token, pages, created_by)
             VALUES (?,?,?,?,?)'
        )->execute([
            $id,
            $name,
            bin2hex(random_bytes(20)),
            json_encode(array_values($pages), JSON_UNESCAPED_UNICODE),
            $createdBy,
        ]);

        return $id;
    }

    /**
     * Tous les kiosques, du plus ancien au plus récent.
     *
     * @return list<array{id:string,name:string,token:string,pages:list<string>,created_by:string,created_at:string,updated_at:?string}>
     */
    public static function all(): array
    {
        $stmt = self::pdo()->query('SELECT * FROM kiosques ORDER BY created_at ASC, id ASC');

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = self::castRow($row);
        }

        return $rows;
    }

    /**
     * Un kiosque par son id.
     *
     * @return array{id:string,name:string,token:string,pages:list<string>,created_by:string,created_at:string,updated_at:?string}|null
     */
    public static function find(string $id): ?array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM kiosques WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row ? self::castRow($row) : null;
    }

    /**
     * Un kiosque par son jeton secret (colonne indexée UNIQUE). La lecture
     * par égalité passe par l'index, puis hash_equals reconfirme en
     * temps constant (cohérence timing).
     *
     * @return array{id:string,name:string,token:string,pages:list<string>,created_by:string,created_at:string,updated_at:?string}|null
     */
    public static function findByToken(string $token): ?array
    {
        $given = trim($token);

        $stmt = self::pdo()->prepare('SELECT * FROM kiosques WHERE token = ? LIMIT 1');
        $stmt->execute([$given]);

        $row = $stmt->fetch();
        if (!$row || !hash_equals((string) $row['token'], $given)) {
            return null;
        }

        return self::castRow($row);
    }

    /**
     * Renomme un kiosque et remplace sa liste de tuiles.
     *
     * @param list<string> $pages ids de tuiles autorisées
     */
    public static function update(string $id, string $name, array $pages): bool
    {
        $stmt = self::pdo()->prepare(
            'UPDATE kiosques SET name = ?, pages = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([
            $name,
            json_encode(array_values($pages), JSON_UNESCAPED_UNICODE),
            $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Régénère le jeton secret d'un kiosque (révoque l'ancien lien) et
     * retourne le nouveau jeton.
     */
    public static function regenerateToken(string $id): string
    {
        $token = bin2hex(random_bytes(20));

        self::pdo()->prepare(
            'UPDATE kiosques SET token = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$token, $id]);

        return $token;
    }

    /**
     * Supprime un kiosque.
     */
    public static function delete(string $id): bool
    {
        $stmt = self::pdo()->prepare('DELETE FROM kiosques WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Normalise une ligne SQL au format public du modèle.
     *
     * @param array<string,mixed> $row
     * @return array{id:string,name:string,token:string,pages:list<string>,created_by:string,created_at:string,updated_at:?string}
     */
    private static function castRow(array $row): array
    {
        $pages = json_decode((string) ($row['pages'] ?? ''), true);
        if (!is_array($pages)) {
            $pages = [];
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'token' => (string) $row['token'],
            'pages' => array_values(array_map(static fn ($p): string => (string) $p, $pages)),
            'created_by' => (string) ($row['created_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
        ];
    }
}
