<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Coûts attachés à un événement (table compta_event_costs).
 *
 * Chaque coût crée aussi une dépense liée (catégorie EVENEMENT) pour
 * alimenter le journal des dépenses, les budgets et le résultat net ;
 * expense_id garde le lien pour la suppression en cascade.
 */
final class ComptaEventCost extends Model
{
    protected static string $table = 'compta_event_costs';

    /**
     * Crée un coût rattaché à un événement (et sa dépense liée).
     *
     * La dépense est créée EN PREMIER (catégorie EVENEMENT, libellé
     * « Événement « nom » — libellé ») ; expense_id garde le lien
     * (null si la dépense n'a pas pu être créée).
     *
     * @param string               $eventId Événement porteur.
     * @param array<string,mixed>  $data    spent_at (« YYYY-MM-DD », défaut
     *                                      aujourd'hui), label, amount_ttc,
     *                                      linked_event_name (nom de
     *                                      l'événement, pour le libellé de la
     *                                      dépense), created_by
     *
     * @return string Identifiant créé ('' si données invalides).
     */
    public static function create(string $eventId, array $data): string
    {
        $spentAt = substr((string) ($data['spent_at'] ?? ''), 0, 10);
        if ($spentAt === '') {
            $spentAt = date('Y-m-d');
        }
        $label = trim((string) ($data['label'] ?? ''));
        $amountTtc = (float) ($data['amount_ttc'] ?? 0);

        if ($label === '' || $amountTtc <= 0) {
            return '';
        }

        // Dépense liée : alimente le journal des dépenses, les budgets
        // et le résultat net (catégorie EVENEMENT).
        $expenseId = Expense::create([
            'spent_at'   => $spentAt,
            'category'   => 'EVENEMENT',
            'label'      => sprintf('Événement « %s » — %s', (string) ($data['linked_event_name'] ?? ''), $label),
            'amount_ttc' => $amountTtc,
            'created_by' => $data['created_by'] ?? null,
        ]);

        $createdBy = ($data['created_by'] ?? '') !== '' ? (string) $data['created_by'] : null;

        $id = 'cec_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO compta_event_costs
                (id, event_id, spent_at, label, amount_ttc, expense_id, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,NOW())'
        )->execute([$id, $eventId, $spentAt, $label, $amountTtc, $expenseId !== '' ? $expenseId : null, $createdBy]);

        return $id;
    }

    /**
     * Coûts d'un événement, du plus récent au plus ancien.
     *
     * @return list<array<string,mixed>>
     */
    public static function forEvent(string $eventId): array
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT * FROM compta_event_costs WHERE event_id = ? ORDER BY spent_at DESC'
            );
            $stmt->execute([$eventId]);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Supprime un coût et sa dépense liée (si présente).
     */
    public static function delete(string $id): bool
    {
        $row = self::find($id);

        if ($row !== null && !empty($row['expense_id'])) {
            Expense::delete((string) $row['expense_id']);
        }

        $stmt = self::pdo()->prepare('DELETE FROM compta_event_costs WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Derniers coûts tous événements confondus, avec le nom de l'événement.
     *
     * Utilisé par la page de liste pour gérer les coûts sans passer par
     * les pages de détail.
     *
     * @return list<array<string,mixed>>
     */
    public static function recentWithEvent(int $limit = 20): array
    {
        $limit = max(1, (int) $limit);

        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query(
                    'SELECT c.*, e.name AS event_name
                     FROM compta_event_costs c
                     JOIN compta_events e ON e.id = c.event_id
                     ORDER BY c.spent_at DESC, c.created_at DESC
                     LIMIT ' . $limit
                )
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
