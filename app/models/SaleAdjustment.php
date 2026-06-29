<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Ajustements de vente (table `sale_adjustments`).
 *
 * Les ventes importées (`sales`) sont immuables : toute correction se fait
 * par écriture d'ajustement horodatée et motivée, afin de conserver une
 * piste d'audit conforme aux obligations comptables (10 ans).
 */
final class SaleAdjustment extends Model
{
    protected static string $table = 'sale_adjustments';

    /**
     * @param array<string,mixed> $data sale_id, amount, reason, created_by
     */
    public static function create(array $data): string
    {
        $id = 'adj_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO sale_adjustments (id, sale_id, amount, reason, created_by, created_at)
             VALUES (?,?,?,?,?, NOW())'
        )->execute([
            $id,
            (string) ($data['sale_id'] ?? ''),
            (float) ($data['amount'] ?? 0),
            $data['reason'] ?? null,
            $data['created_by'] ?? null,
        ]);

        return $id;
    }
}
