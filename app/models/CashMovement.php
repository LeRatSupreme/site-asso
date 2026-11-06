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
 *   - AJUSTEMENT : écart appliqué après comptage physique (signé) ;
 *   - PRISE      : transfert SIGNÉ caisse <-> « chez soi » (négatif =
 *                  argent pris chez soi, positif = argent ramené — même
 *                  convention de signe que DEPOT/AJUSTEMENT).
 *
 * Lignes immuables : pas de modification ni suppression — une correction
 * passe par un nouveau mouvement (traçabilité).
 */
final class CashMovement extends Model
{
    public const TYPE_FOND = 'FOND';
    public const TYPE_DEPOT = 'DEPOT';
    public const TYPE_AJUSTEMENT = 'AJUSTEMENT';
    public const TYPE_PRISE = 'PRISE';

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
     * Total des dépôts à la banque (montants DÉPOSÉS, positifs) : les
     * mouvements DEPOT sont stockés négatifs (sortie de caisse), on
     * additionne donc les valeurs absolues. Source du bloc « Banque » de
     * la page Trésorerie — aucun autre calcul ne refait cette somme.
     */
    public static function depositsTotal(): float
    {
        $stmt = self::pdo()->prepare(
            'SELECT COALESCE(SUM(ABS(amount)), 0) AS total FROM cash_movements WHERE type = ?'
        );
        $stmt->execute([self::TYPE_DEPOT]);

        /** @var array<string,mixed> $row */
        $row = $stmt->fetch();

        return round((float) ($row['total'] ?? 0), 2);
    }

    /**
     * Solde « chez soi » (POINT D'ASSEMBLAGE UNIQUE, requête Q3 de
     * database/queries/traceabilite_liquide.sql) : les prises sont
     * stockées négatives → chez soi = −Σ(PRISE), toutes périodes.
     * Un retour (PRISE positif) le diminue mécaniquement. Affiché sur
     * la page Caisses, inclus au total de la Trésorerie et dans la
     * section « Traçabilité du liquide » du livre — jamais recalculé
     * ailleurs.
     */
    public static function homeTotal(): float
    {
        $stmt = self::pdo()->prepare(
            'SELECT COALESCE(-SUM(m.amount), 0) AS chez_soi FROM cash_movements AS m WHERE m.type = ?'
        );
        $stmt->execute([self::TYPE_PRISE]);

        /** @var array<string,mixed> $row */
        $row = $stmt->fetch();

        return round((float) ($row['chez_soi'] ?? 0), 2);
    }

    /**
     * Mouvements de caisse d'une période, par ordre chronologique
     * (requête Q1 de database/queries/traceabilite_liquide.sql — base de
     * la section « Traçabilité du liquide » du livre comptable).
     * Convention de bornes du projet (cf. Sale::dailyRevenueBetween) :
     * jours 'YYYY-MM-DD', bornes incluses (00:00:00 → 23:59:59).
     *
     * @return list<array<string,mixed>>
     */
    public static function between(string $fromDay, string $toDay): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT m.id, m.type, m.amount, m.label, m.created_by, m.created_at
             FROM cash_movements AS m
             WHERE m.created_at >= ?
               AND m.created_at <= ?
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->execute([$fromDay . ' 00:00:00', $toDay . ' 23:59:59']);

        /** @var list<array<string,mixed>> $r */
        return $stmt->fetchAll();
    }

    /**
     * Prises et dépôts de la période (montants POSITIFS, pour l'en-tête
     * « Pris : X € · Déposé : Y € » de la traçabilité — requête Q3 de
     * database/queries/traceabilite_liquide.sql).
     *
     * @return array{pris:float,depose:float}
     */
    public static function takesAndDepositsBetween(string $fromDay, string $toDay): array
    {
        $stmt = self::pdo()->prepare(
            'SELECT COALESCE(SUM(ABS(m.amount)), 0) AS pris,
                    0 AS depose
             FROM cash_movements AS m
             WHERE m.type = ? AND m.created_at >= ? AND m.created_at <= ?
             UNION ALL
             SELECT 0 AS pris,
                    COALESCE(SUM(ABS(m.amount)), 0) AS depose
             FROM cash_movements AS m
             WHERE m.type = ? AND m.created_at >= ? AND m.created_at <= ?'
        );
        $stmt->execute([
            self::TYPE_PRISE, $fromDay . ' 00:00:00', $toDay . ' 23:59:59',
            self::TYPE_DEPOT, $fromDay . ' 00:00:00', $toDay . ' 23:59:59',
        ]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return [
            'pris'   => round((float) ($rows[0]['pris'] ?? 0), 2),
            'depose' => round((float) ($rows[1]['depose'] ?? 0), 2),
        ];
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
