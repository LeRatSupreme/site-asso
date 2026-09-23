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
        return count(self::usedBy($id));
    }

    /**
     * Produits dont l'image référence ce média (pour afficher POURQUOI la
     * suppression est bloquée, et proposer la suppression en cascade).
     *
     * @return list<array{id:string,name:string,image:string}>
     */
    public static function usedBy(string $id): array
    {
        $media = static::find($id);
        if ($media === null || (string) ($media['url'] ?? '') === '') {
            return [];
        }

        return self::productsReferencing((string) $media['url']);
    }

    /**
     * Produits (avec image non vide) par média les référençant :
     * [media_id => liste de produits]. Une seule requête produits — sert à
     * afficher les badges d'utilisation sur la grille des médias.
     *
     * @return array<string, list<array{id:string,name:string,image:string}>>
     */
    public static function usageMap(): array
    {
        try {
            $medias = static::pdo()->query('SELECT id, url FROM media')->fetchAll();
            $products = static::pdo()
                ->query("SELECT id, name, image FROM products WHERE image IS NOT NULL AND image <> ''")
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        /** @var array<string, list<array{id:string,name:string,image:string}>> $map */
        $map = [];
        foreach ($medias as $media) {
            $map[(string) $media['id']] = [];
        }
        foreach ($products as $product) {
            $image = (string) $product['image'];
            foreach ($medias as $media) {
                $url = (string) $media['url'];
                if ($url !== '' && str_contains($image, $url)) {
                    $map[(string) $media['id']][] = [
                        'id'    => (string) $product['id'],
                        'name'  => (string) $product['name'],
                        'image' => $image,
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * Détache les produits référençant ce média (image remise à NULL) sans
     * toucher aux autres données du produit (nom, prix, stock…).
     *
     * @return int Nombre de produits détachés.
     */
    public static function detachProducts(string $id): int
    {
        $media = static::find($id);
        if ($media === null || (string) ($media['url'] ?? '') === '') {
            return 0;
        }

        $stmt = static::pdo()->prepare(
            "UPDATE products SET image = NULL WHERE image LIKE CONCAT('%', ?, '%')"
        );
        $stmt->execute([(string) $media['url']]);

        return $stmt->rowCount();
    }

    /** @return list<array{id:string,name:string,image:string}> */
    private static function productsReferencing(string $url): array
    {
        try {
            $stmt = static::pdo()->prepare(
                "SELECT id, name, image FROM products
                 WHERE image LIKE CONCAT('%', ?, '%')
                 ORDER BY name ASC"
            );
            $stmt->execute([$url]);

            /** @var list<array{id:string,name:string,image:string}> $rows */
            $rows = $stmt->fetchAll();

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }
}
