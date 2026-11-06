<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Compta\CashBreakdown;

/**
 * Détail par coupures d'un mouvement de caisse (table
 * `cash_movement_breakdown`) : pièces et billets comptés, en CENTIMES
 * ENTIERS — valeurs du catalogue verrouillé CashBreakdown::UNITS,
 * contrainte CHECK en base en dernier filet.
 *
 * Attaché à N'IMPORTE QUEL mouvement de caisse (PRISE « chez soi » mais
 * aussi dépôt banque) ; le détail est OPTIONNEL : un mouvement sans
 * coupures reste légal (rétro-compatibilité des dépôts existants).
 * Lignes immuables comme cash_movements : pas d'UPDATE ni de DELETE —
 * une correction passe par un nouveau mouvement. L'invariant
 * ABS(amount) = Σ(unit_cents × qty)/100 est garanti par le service
 * (CashLedger), jamais recalculé ici.
 */
final class CashMovementBreakdown extends Model
{
    protected static string $table = 'cash_movement_breakdown';

    /**
     * Insère les lignes de coupures d'un mouvement qui VIENT d'être créé.
     * Mouvement sans coupures (breakdown null ou vide) : no-op, reste
     * légal (détail optionnel sur les dépôts existants).
     */
    public static function insertFor(string $movementId, ?CashBreakdown $breakdown): void
    {
        if ($breakdown === null || $breakdown->isEmpty()) {
            return;
        }

        $stmt = self::pdo()->prepare(
            'INSERT INTO cash_movement_breakdown (movement_id, unit_cents, qty) VALUES (?,?,?)'
        );
        foreach ($breakdown->quantities() as $unit => $qty) {
            $stmt->execute([$movementId, $unit, $qty]);
        }
    }

    /**
     * Sous-lignes « coupures » des mouvements d'une période, groupées
     * par mouvement (requête Q2 de database/queries/traceabilite_liquide.sql :
     * jointure PHP côté movement_id, grosses coupures d'abord — le
     * formatage « ×20€ »/« ×50c » reste à CashBreakdown).
     *
     * @param string $fromDay jour 'YYYY-MM-DD' (borne incluse)
     * @param string $toDay   jour 'YYYY-MM-DD' (borne incluse)
     *
     * @return array<string,list<array{unit_cents:int,qty:int}>>
     */
    public static function forPeriod(string $fromDay, string $toDay): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT b.movement_id, b.unit_cents, b.qty
             FROM cash_movement_breakdown AS b
             JOIN cash_movements AS m ON m.id = b.movement_id
             WHERE m.created_at >= ?
               AND m.created_at <= ?
             ORDER BY b.movement_id ASC, b.unit_cents DESC"
        );
        $stmt->execute([$fromDay . ' 00:00:00', $toDay . ' 23:59:59']);

        /** @var array<string,list<array{unit_cents:int,qty:int}>> $byMovement */
        $byMovement = [];
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $byMovement[(string) $row['movement_id']][] = [
                'unit_cents' => (int) $row['unit_cents'],
                'qty'        => (int) $row['qty'],
            ];
        }

        return $byMovement;
    }
}
