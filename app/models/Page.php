<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des pages CMS.
 */
final class Page extends Model
{
    protected static string $table = 'pages';

    /** Ordre canonique des pages : position (drag & drop admin), puis
     * titre puis id en départage — UN SEUL point d'assemblage de l'ordre,
     * réutilisé par tous les listages (liste admin, sitemap). */
    private const ORDER_BY = 'position ASC, title ASC, id ASC';

    /**
     * Pages publiées, dans l'ordre canonique (sitemap, listes publiques).
     *
     * @return list<array<string,mixed>>
     */
    public static function published(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT * FROM pages WHERE is_published = 1 ORDER BY ' . self::ORDER_BY
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

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

    /**
     * Recherche dans les pages publiées (titre).
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $like, int $limit = 3): array
    {
        $sql = 'SELECT * FROM pages
                WHERE is_published = 1 AND title LIKE ?
                ORDER BY title ASC
                LIMIT ' . (int) $limit;

        try {
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([$like]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Toutes les pages (admin), dans l'ordre canonique (position du
     * drag & drop, titre puis id en départage).
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query('SELECT * FROM pages ORDER BY ' . self::ORDER_BY);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Recherche une page par son slug, sans filtre de publication (admin).
     *
     * @return array<string,mixed>|null
     */
    public static function findBySlugAny(string $slug): ?array
    {
        $stmt = static::pdo()->prepare('SELECT * FROM pages WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Crée ou met à jour une page (upsert par id).
     *
     * À la création, la page part en FIN de liste (position = MAX + 1,
     * comme ProductCategory::ensure) ; à la mise à jour la position
     * n'est JAMAIS touchée — seul le drag & drop (reorder) l'écrit.
     *
     * @param array<string,mixed> $data
     * @return string L'identifiant de la page.
     */
    public static function save(array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        $isNew = $id === '' || self::find($id) === null;
        if ($isNew) {
            $id = 'page_' . bin2hex(random_bytes(10));
        }

        $fields = [
            'id'               => $id,
            'slug'             => $data['slug'] ?? '',
            'title'            => $data['title'] ?? '',
            'content'          => $data['content'] ?? null,
            'meta_title'       => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'is_published'     => !empty($data['is_published']) ? 1 : 0,
        ];

        if ($isNew) {
            $fields['position'] = (int) static::pdo()
                ->query('SELECT COALESCE(MAX(position), 0) + 1 FROM pages')
                ->fetchColumn();
            $cols = '`' . implode('`, `', array_keys($fields)) . '`';
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = static::pdo()->prepare(
                'INSERT INTO pages (' . $cols . ') VALUES (' . $placeholders . ')'
            );
            $stmt->execute(array_values($fields));
        } else {
            $set = [];
            foreach (array_keys($fields) as $col) {
                if ($col === 'id') {
                    continue;
                }
                $set[] = $col . ' = ?';
            }
            $values = array_values(array_diff_key($fields, ['id' => null]));
            $values[] = $id;
            $stmt = static::pdo()->prepare('UPDATE pages SET ' . implode(', ', $set) . ' WHERE id = ?');
            $stmt->execute($values);
        }

        return $id;
    }

    public static function deleteRow(string $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM pages WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Réécrit les positions en masse selon l'ordre des ids fourni
     * (drag & drop de la liste admin), en une seule transaction :
     * soit tout est écrit, soit rien.
     *
     * Sémantique (même esprit stable que SidebarOrder::reordonner) :
     *  - les ids du payload EXISTANT en base passent devant, dans
     *    l'ordre du payload (doublons et ids inconnus ignorés) ;
     *  - les pages absentes du payload (créées entre-temps dans un
     *    autre onglet, par ex.) restent APRÈS, dans leur ordre
     *    courant — aucun id n'est jamais créé ni supprimé ;
     *  - positions renumérotées 1..N d'un seul tenant (pas de trou).
     *
     * `updated_at` est explicitement préservé : un pur changement
     * d'ordre ne modifie pas le contenu, il ne doit pas rafraîchir le
     * lastmod du sitemap.
     *
     * @param list<mixed> $ids Ordre souhaité des ids de pages.
     * @return int Nombre de positions écrites (toutes les pages).
     */
    public static function reorder(array $ids): int
    {
        $pdo = static::pdo();

        // Ids demandés : chaînes non vides, dédoublonnées, ordre conservé.
        $demandes = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && !in_array($id, $demandes, true)) {
                $demandes[] = $id;
            }
        }

        $pdo->beginTransaction();
        try {
            /** @var list<string> $existantes Ordre courant canonique. */
            $existantes = $pdo
                ->query('SELECT id FROM pages ORDER BY ' . self::ORDER_BY)
                ->fetchAll(\PDO::FETCH_COLUMN);

            $ordre = [];
            foreach ($demandes as $id) {
                if (in_array($id, $existantes, true)) {
                    $ordre[] = $id;
                }
            }
            foreach ($existantes as $id) {
                if (!in_array($id, $ordre, true)) {
                    $ordre[] = $id;
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE pages SET position = ?, updated_at = updated_at WHERE id = ?'
            );
            $position = 0;
            foreach ($ordre as $id) {
                $position++;
                $stmt->execute([$position, $id]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $position;
    }
}
