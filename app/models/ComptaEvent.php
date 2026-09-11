<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Événements suivis par la trésorerie (table compta_events).
 *
 * Le nom de l'événement = libellé exact du bouton SumUp : les ventes
 * importées portant ce nom (product_key OU description) se rattachent
 * automatiquement. Bénéfice = ventes rattachées − coûts saisis.
 */
final class ComptaEvent extends Model
{
    protected static string $table = 'compta_events';

    /**
     * Crée un événement.
     *
     * date_to optionnelle (défaut = date_from) ; si date_to < date_from,
     * les bornes sont échangées.
     *
     * @param array<string,mixed> $data name, date_from (« YYYY-MM-DD »),
     *                                  date_to (optionnel), notes, created_by
     *
     * @return string Identifiant créé ('' si données invalides).
     */
    public static function create(array $data): string
    {
        $name = trim((string) ($data['name'] ?? ''));
        $dateFrom = substr((string) ($data['date_from'] ?? ''), 0, 10);

        if ($name === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) !== 1) {
            return '';
        }

        $dateTo = substr((string) ($data['date_to'] ?? ''), 0, 10);
        if ($dateTo === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) !== 1) {
            $dateTo = $dateFrom;
        }
        if ($dateTo < $dateFrom) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $notes = ($data['notes'] ?? '') !== '' ? (string) $data['notes'] : null;
        $createdBy = ($data['created_by'] ?? '') !== '' ? (string) $data['created_by'] : null;

        $id = 'cev_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO compta_events
                (id, name, date_from, date_to, notes, created_by, created_at)
             VALUES (?,?,?,?,?,?,NOW())'
        )->execute([$id, $name, $dateFrom, $dateTo, $notes, $createdBy]);

        return $id;
    }

    /**
     * Charge un événement pour sa page de détail.
     *
     * @return array<string,mixed>|null
     */
    public static function findForDetail(string $id): ?array
    {
        return self::find($id);
    }

    /**
     * Supprime un événement, ses coûts et les dépenses liées.
     *
     * 1) les dépenses liées aux coûts (expense_id) sont supprimées ;
     * 2) le DELETE déclenche le CASCADE SQL sur compta_event_costs.
     */
    public static function delete(string $id): bool
    {
        foreach (ComptaEventCost::forEvent($id) as $cost) {
            if (!empty($cost['expense_id'])) {
                Expense::delete((string) $cost['expense_id']);
            }
        }

        $stmt = self::pdo()->prepare('DELETE FROM compta_events WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Événements dont date_from est dans la plage de jours (bornes
     * incluses, chaque condition seulement si non null), enrichis des
     * ventes rattachées (ca, qty), des coûts et du bénéfice.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array<string,mixed>>
     */
    public static function listBetween(?string $fromDay, ?string $toDay): array
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'date_from >= ?';
            $args[] = $fromDay;
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'date_from <= ?';
            $args[] = $toDay;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT * FROM compta_events ' . $whereSql . ' ORDER BY date_from DESC'
            );
            $stmt->execute($args);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $stats = self::stats($row);
            $row['ca'] = $stats['ca'];
            $row['qty'] = $stats['qty'];
            $row['costs'] = self::costsTotal((string) $row['id']);
            $row['profit'] = $stats['ca'] - $row['costs'];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Ventes rattachées à un événement sur sa fenêtre de dates
     * [date_from 00:00:00 ; date_to 23:59:59] : les lignes de ventes
     * dont product_key OU description porte le nom de l'événement.
     *
     * @param array<string,mixed> $event Ligne compta_events (name, date_from, date_to).
     *
     * @return array{ca:float, qty:int, avg:float} avg = prix moyen
     *                                               par entrée (ca / qty).
     */
    public static function stats(array $event): array
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(price_ttc), 0) AS ca,
                        COALESCE(SUM(quantity), 0) AS qty
                 FROM sales
                 WHERE (product_key = ? OR description = ?)
                   AND sold_at >= ?
                   AND sold_at <= ?'
            );
            $stmt->execute([
                (string) $event['name'],
                (string) $event['name'],
                (string) $event['date_from'] . ' 00:00:00',
                (string) $event['date_to'] . ' 23:59:59',
            ]);
            $row = $stmt->fetch() ?: [];
        } catch (\Throwable) {
            return ['ca' => 0.0, 'qty' => 0, 'avg' => 0.0];
        }

        $ca = (float) ($row['ca'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);

        return [
            'ca'  => $ca,
            'qty' => $qty,
            'avg' => $qty > 0 ? round($ca / $qty, 2) : 0.0,
        ];
    }

    /**
     * Total TTC des coûts d'un événement (0.0 si aucun).
     */
    public static function costsTotal(string $eventId): float
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(amount_ttc), 0) FROM compta_event_costs WHERE event_id = ?'
            );
            $stmt->execute([$eventId]);

            return (float) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
