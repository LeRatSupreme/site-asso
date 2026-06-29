<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des pages CMS.
 */
final class Page extends Model
{
    protected static string $table = 'pages';

    /**
     * Recherche une page publiée par son slug.
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlug(string $slug): ?array
    {
        $stmt = static::pdo()->prepare(
            'SELECT * FROM pages WHERE slug = ? AND is_published = 1 LIMIT 1'
        );
        $stmt->execute([$slug]);

        $row = $stmt->fetch();

        return $row ?: null;
    }
}
