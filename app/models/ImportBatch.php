<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Traçabilité des imports de rapports SumUp (table `import_batches`).
 */
final class ImportBatch extends Model
{
    protected static string $table = 'import_batches';

    /**
     * Crée un lot d'import.
     *
     * @param array<string,mixed> $meta filename, file_hash, period_start,
     *                                  period_end, rows_total, rows_inserted,
     *                                  rows_skipped, imported_by
     */
    public static function create(array $meta): string
    {
        $id = 'batch_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO import_batches
                (id, filename, file_hash, period_start, period_end, rows_total, rows_inserted, rows_skipped, imported_by, imported_at)
             VALUES (?,?,?,?,?,?,?,?,?, NOW())'
        )->execute([
            $id,
            $meta['filename'] ?? null,
            $meta['file_hash'] ?? null,
            $meta['period_start'] ?? null,
            $meta['period_end'] ?? null,
            $meta['rows_total'] ?? null,
            $meta['rows_inserted'] ?? null,
            $meta['rows_skipped'] ?? null,
            $meta['imported_by'] ?? null,
        ]);

        return $id;
    }

    /**
     * Retrouve un lot par empreinte SHA-256 du fichier importé
     * (anti-réimport). Renvoie null si aucun lot ne correspond ou si la
     * colonne n'existe pas encore (base non migrée).
     */
    public static function findByHash(string $hash): ?array
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT * FROM import_batches WHERE file_hash = ? LIMIT 1'
            );
            $stmt->execute([$hash]);

            $row = $stmt->fetch();

            return $row === false ? null : $row;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lots dont la période [period_start, period_end] chevauche
     * [start, end] (bornes incluses), hash identique exclu.
     *
     * Les lots sans période connue (anciens imports) sont ignorés.
     *
     * @return list<array<string,mixed>>
     */
    public static function overlapping(string $start, string $end, ?string $excludeHash = null): array
    {
        $sql = 'SELECT * FROM import_batches
                 WHERE period_start IS NOT NULL AND period_end IS NOT NULL
                   AND period_start <= ? AND period_end >= ?';
        $args = [$end, $start];

        if ($excludeHash !== null && $excludeHash !== '') {
            $sql .= ' AND (file_hash IS NULL OR file_hash <> ?)';
            $args[] = $excludeHash;
        }

        $sql .= ' ORDER BY imported_at DESC';

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
     * Met à jour les compteurs d'un lot après import effectif.
     */
    public static function finalize(string $id, int $inserted, int $skipped): void
    {
        self::pdo()->prepare(
            'UPDATE import_batches SET rows_inserted = ?, rows_skipped = ? WHERE id = ?'
        )->execute([$inserted, $skipped, $id]);
    }

    /**
     * Tous les lots, du plus récent au plus ancien.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query('SELECT * FROM import_batches ORDER BY imported_at DESC')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
