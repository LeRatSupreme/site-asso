<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle de la bibliothèque de médias (table `media`).
 */
final class Media extends Model
{
    protected static string $table = 'media';

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 0): array
    {
        $sql = 'SELECT * FROM media ORDER BY created_at DESC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        try {
            $stmt = static::pdo()->query($sql);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function create(array $data): string
    {
        $id = 'med_' . bin2hex(random_bytes(12));

        $stmt = static::pdo()->prepare(
            'INSERT INTO media (id, name, url, type, mime_type, alt, size)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['name'] ?? '',
            $data['url'] ?? '',
            $data['type'] ?? 'image',
            $data['mime_type'] ?? null,
            $data['alt'] ?? null,
            $data['size'] ?? null,
        ]);

        return $id;
    }

    /**
     * Supprime la ligne et retourne le nombre de lignes effacées
     * (0 = média introuvable).
     */
    public static function deleteRow(string $id): int
    {
        $stmt = static::pdo()->prepare('DELETE FROM media WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount();
    }

    /**
     * Nombre de produits dont l'image référence ce média
     * (products.image stocke un chemin contenant l'URL relative du média).
     */
    public static function usedByCount(string $id): int
    {
        $media = static::find($id);
        if ($media === null || (string) ($media['url'] ?? '') === '') {
            return 0;
        }

        $stmt = static::pdo()->prepare(
            "SELECT COUNT(*) FROM products WHERE image LIKE CONCAT('%', ?, '%')"
        );
        $stmt->execute([(string) $media['url']]);

        return (int) $stmt->fetchColumn();
    }
}
