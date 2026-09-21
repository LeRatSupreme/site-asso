<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Mouvements manuels de la caisse (table `cash_movements`).
 *
 * Les ventes en liquide ne sont pas dupliquées ici : le solde théorique
 * les calcule en direct depuis `sales` (payment_method = 'LIQUIDE').
 * Cette table ne trace que les événements manuels :
 *   - FOND       : fond de caisse initial / remise en caisse (montant +) ;
 *   - DEPOT      : dépôt à la banque (montant −, stocké positif) ;
 *   - AJUSTEMENT : écart appliqué après comptage physique (signé).
 *
 * Lignes immuables : pas de modification ni suppression — une correction
 * passe par un nouveau mouvement (traçabilité).
 */
final class CashMovement extends Model
{
    public const TYPE_FOND = 'FOND';
    public const TYPE_DEPOT = 'DEPOT';
    public const TYPE_AJUSTEMENT = 'AJUSTEMENT';

    protected static string $table = 'cash_movements';

    /**
     * Enregistre un mouvement signé.
     *
     * @param array{type:string, amount:float|int|string, label?:?string, created_by?:?string, created_at?:?string} $data
     */
    public static function add(array $data): string
    {
        $id = 'cash_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO cash_movements (id, type, amount, label, created_by, created_at)
             VALUES (?,?,?,?,?, COALESCE(?, NOW()))'
        )->execute([
            $id,
            $data['type'],
            number_format((float) $data['amount'], 2, '.', ''),
            (string) ($data['label'] ?? ''),
            $data['created_by'] ?? null,
            $data['created_at'] ?? null,
        ]);

        return $id;
    }

    /**
     * Somme signée de tous les mouvements (la base du solde théorique).
     */
    public static function sum(): float
    {
        /** @var array<string,mixed> $row */
        $row = self::pdo()
            ->query('SELECT COALESCE(SUM(amount), 0) AS total FROM cash_movements')
            ->fetch();

        return (float) ($row['total'] ?? 0);
    }

    /**
     * Derniers mouvements (historique affiché).
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 50): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT * FROM cash_movements ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute();

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }
}
