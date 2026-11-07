<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Journal des pertes (table `losses`).
 *
 * Une perte = quantité perdue d'un produit pour un motif (casse, périmé,
 * vol, offert, erreur). Déduite du stock théorique (dernier comptage +
 * achats − ventes − pertes) : une perte enregistrée explique un écart
 * d'inventaire. La valorisation se fait au coût de revient du lot
 * applicable à la date de perte.
 */
final class Loss extends Model
{
    protected static string $table = 'losses';

    /** Motifs de perte disponibles. */
    public const REASONS = ['CASSE', 'PERIME', 'VOL', 'OFFERT', 'ERREUR', 'DIVERS'];

    /**
     * Crée une perte.
     *
     * Le motif est normalisé (majuscules) et doit faire partie de REASONS,
     * sinon il retombe sur 'DIVERS'.
     *
     * @param array<string,mixed> $data lost_at (« YYYY-MM-DD »), product_key,
     *                                  quantity (>= 1), reason, note,
     *                                  created_by
     *
     * @return string Identifiant créé ('' si données invalides).
     */
    public static function create(array $data): string
    {
        $lostAt = substr((string) ($data['lost_at'] ?? ''), 0, 10);
        $productKey = trim((string) ($data['product_key'] ?? ''));
        $quantity = (int) ($data['quantity'] ?? 0);

        if ($lostAt === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lostAt) || $productKey === '' || $quantity < 1) {
            return '';
        }

        $reason = strtoupper(trim((string) ($data['reason'] ?? '')));
        if (!in_array($reason, self::REASONS, true)) {
            $reason = 'DIVERS';
        }

        $note = ($data['note'] ?? '') !== '' ? mb_substr((string) $data['note'], 0, 500) : null;
        $createdBy = ($data['created_by'] ?? '') !== '' ? (string) $data['created_by'] : null;

        $id = 'loss_' . bin2hex(random_bytes(10));

        self::pdo()->prepare(
            'INSERT INTO losses
                (id, lost_at, product_key, quantity, reason, note, created_by, created_at)
             VALUES (?,?,?,?,?,?,?,NOW())'
        )->execute([$id, $lostAt, $productKey, $quantity, $reason, $note, $createdBy]);

        // La perte sort du stock : la référence (réappro) suit le théorique.
        ProductStock::adjust($productKey, -$quantity);

        return $id;
    }

    /**
     * Supprime une perte.
     */
    public static function delete(string $id): bool
    {
        $stmt = self::pdo()->prepare('DELETE FROM losses WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Dernières pertes, de la plus récente à la plus ancienne.
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 100): array
    {
        $limit = max(1, (int) $limit);

        try {
            /** @var list<array<string,mixed>> $r */
            return self::pdo()
                ->query('SELECT * FROM losses ORDER BY lost_at DESC, created_at DESC LIMIT ' . $limit)
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Quantité totale perdue d'un produit depuis un jour donné (inclus).
     *
     * @param string $day Jour « YYYY-MM-DD » (borne inférieure incluse).
     */
    public static function qtySince(string $productKey, string $day): int
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT COALESCE(SUM(quantity), 0)
                 FROM losses
                 WHERE product_key = ? AND lost_at >= ?'
            );
            $stmt->execute([$productKey, $day]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Pertes d'une période : filtrée par année (et par mois si non null),
     * de la plus récente à la plus ancienne.
     *
     * @return list<array<string,mixed>>
     */
    public static function forPeriod(?int $year, ?int $month): array
    {
        $where = [];
        $args = [];
        if ($year !== null) {
            $where[] = 'YEAR(lost_at) = ?';
            $args[] = $year;
        }
        if ($month !== null) {
            $where[] = 'MONTH(lost_at) = ?';
            $args[] = $month;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare('SELECT * FROM losses ' . $whereSql . ' ORDER BY lost_at DESC');
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Valeur totale des pertes d'une période, valorisées au coût du lot
     * applicable à chaque date de perte (0.0 si rien).
     */
    public static function valueForPeriod(?int $year, ?int $month): float
    {
        $total = 0.0;
        foreach (self::forPeriod($year, $month) as $r) {
            $total += self::rowValue($r);
        }

        return $total;
    }

    /**
     * Valeur des pertes des N derniers jours (fenêtre glissante), avec la
     * même valorisation au coût du lot applicable (0.0 si rien).
     */
    public static function valueForDays(int $days): float
    {
        $days = max(1, (int) $days);

        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = self::pdo()
                ->query('SELECT * FROM losses WHERE lost_at >= DATE_SUB(CURDATE(), INTERVAL ' . $days . ' DAY)')
                ->fetchAll();
        } catch (\Throwable) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($rows as $r) {
            $total += self::rowValue($r);
        }

        return $total;
    }

    /**
     * Agrégats par motif sur une période : quantité (SUM en SQL) et valeur
     * (valorisation PHP au coût du lot applicable), triés par valeur
     * décroissante.
     *
     * @return list<array{reason:string, qty:int, value:float}>
     */
    public static function byReason(?int $year, ?int $month): array
    {
        $where = [];
        $args = [];
        if ($year !== null) {
            $where[] = 'YEAR(lost_at) = ?';
            $args[] = $year;
        }
        if ($month !== null) {
            $where[] = 'MONTH(lost_at) = ?';
            $args[] = $month;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT reason, COALESCE(SUM(quantity), 0) AS qty
                 FROM losses ' . $whereSql . '
                 GROUP BY reason'
            );
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $grouped */
            $grouped = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        // Valeur par motif : même valorisation que valueForPeriod().
        $values = [];
        foreach (self::forPeriod($year, $month) as $r) {
            $reason = (string) $r['reason'];
            $values[$reason] = ($values[$reason] ?? 0.0) + self::rowValue($r);
        }

        $out = [];
        foreach ($grouped as $g) {
            $out[] = [
                'reason' => (string) $g['reason'],
                'qty'    => (int) $g['qty'],
                'value'  => (float) ($values[(string) $g['reason']] ?? 0.0),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $out;
    }

    /**
     * Pertes sur une plage de jours (bornes incluses sur lost_at), de la
     * plus récente à la plus ancienne. Bornes null = tout l'historique.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array<string,mixed>>
     */
    public static function between(?string $fromDay, ?string $toDay): array
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'lost_at >= ?';
            $args[] = $fromDay;
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'lost_at <= ?';
            $args[] = $toDay;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare('SELECT * FROM losses ' . $whereSql . ' ORDER BY lost_at DESC');
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $r */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Agrégats par motif sur une plage de jours (bornes incluses), avec la
     * même valorisation et le même tri que byReason().
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     *
     * @return list<array{reason:string, qty:int, value:float}>
     */
    public static function byReasonBetween(?string $fromDay, ?string $toDay): array
    {
        $where = [];
        $args = [];
        if ($fromDay !== null && $fromDay !== '') {
            $where[] = 'lost_at >= ?';
            $args[] = $fromDay;
        }
        if ($toDay !== null && $toDay !== '') {
            $where[] = 'lost_at <= ?';
            $args[] = $toDay;
        }
        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        try {
            $stmt = self::pdo()->prepare(
                'SELECT reason, COALESCE(SUM(quantity), 0) AS qty
                 FROM losses ' . $whereSql . '
                 GROUP BY reason'
            );
            $stmt->execute($args);

            /** @var list<array<string,mixed>> $grouped */
            $grouped = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        // Valeur par motif : même valorisation que valueBetween().
        $values = [];
        foreach (self::between($fromDay, $toDay) as $r) {
            $reason = (string) $r['reason'];
            $values[$reason] = ($values[$reason] ?? 0.0) + self::rowValue($r);
        }

        $out = [];
        foreach ($grouped as $g) {
            $out[] = [
                'reason' => (string) $g['reason'],
                'qty'    => (int) $g['qty'],
                'value'  => (float) ($values[(string) $g['reason']] ?? 0.0),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $out;
    }

    /**
     * Valeur totale des pertes sur une plage de jours (bornes incluses),
     * valorisées au coût du lot applicable à chaque date de perte
     * (0.0 si rien). Bornes null = tout l'historique.
     *
     * @param string|null $fromDay Jour de début « YYYY-MM-DD » (inclus), ou null.
     * @param string|null $toDay   Jour de fin « YYYY-MM-DD » (inclus), ou null.
     */
    public static function valueBetween(?string $fromDay, ?string $toDay): float
    {
        $total = 0.0;
        foreach (self::between($fromDay, $toDay) as $r) {
            $total += self::rowValue($r);
        }

        return $total;
    }

    /**
     * Valeur d'une ligne de perte : quantité × coût du lot applicable à la
     * date de perte (0 si aucun lot connu).
     *
     * @param array<string,mixed> $row
     */
    private static function rowValue(array $row): float
    {
        $cost = ProductCost::costAt((string) $row['product_key'], (string) $row['lost_at']);

        return (int) $row['quantity'] * (float) ($cost ?? 0);
    }
}
