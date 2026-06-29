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
     * @param array<string,mixed> $meta filename, period_start, period_end,
     *                                  rows_total, rows_inserted, rows_skipped,
     *                                  imported_by
     */
    public static function create(array $meta): string
    {
        $id = 'batch_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO import_batches
                (id, filename, period_start, period_end, rows_total, rows_inserted, rows_skipped, imported_by, imported_at)
             VALUES (?,?,?,?,?,?,?,?, NOW())'
        )->execute([
            $id,
            $meta['filename'] ?? null,
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
