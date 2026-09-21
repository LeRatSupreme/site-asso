<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Comptages physiques de la caisse (table `cash_counts`).
 *
 * Chaque comptage fige le théorique du moment et l'écart constaté
 * (compté − théorique ; négatif = manquant = vol potentiel). Si l'écart
 * est non nul, un mouvement AJUSTEMENT est créé en même temps (voir
 * CashLedger::recordCount) pour réaligner le théorique sur le compté.
 *
 * Lignes immuables : pas de modification ni suppression.
 */
final class CashCount extends Model
{
    protected static string $table = 'cash_counts';

    /**
     * Connexion PDO partagée (transactions du CashLedger à cheval sur
     * `cash_counts` et `cash_movements` — même PDO que les modèles en test).
     */
    public static function connection(): \PDO
    {
        return self::pdo();
    }

    /**
     * Enregistre un comptage.
     *
     * @param array{counted:float|int|string, theoretical:float|int|string, label?:?string, created_by?:?string, created_at?:?string} $data
     * @return array{id:string, ecart:float}
     */
    public static function add(array $data): array
    {
        $id = 'count_' . bin2hex(random_bytes(10));
        $counted = round((float) $data['counted'], 2);
        $theoretical = round((float) $data['theoretical'], 2);
        $ecart = round($counted - $theoretical, 2);

        self::pdo()->prepare(
            'INSERT INTO cash_counts (id, counted_amount, theoretical_amount, ecart, label, created_by, created_at)
             VALUES (?,?,?,?,?,?, COALESCE(?, NOW()))'
        )->execute([
            $id,
            number_format($counted, 2, '.', ''),
            number_format($theoretical, 2, '.', ''),
            number_format($ecart, 2, '.', ''),
            (string) ($data['label'] ?? ''),
            $data['created_by'] ?? null,
            $data['created_at'] ?? null,
        ]);

        return ['id' => $id, 'ecart' => $ecart];
    }

    /**
     * Comptages avec écart non nul sur les N derniers jours
     * (section « ⚠️ Écarts détectés »).
     *
     * @return list<array<string,mixed>>
     */
    public static function recentEcarts(int $days = 30): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT * FROM cash_counts
              WHERE ecart <> 0
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([max(1, $days)]);

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Derniers comptages (historique affiché).
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 50): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT * FROM cash_counts ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute();

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }
}
