<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des adhésions / cotisations annuelles.
 *
 * Une saison scolaire s'écrit « 2026-2027 » : à partir de juillet (mois >= 7),
 * la saison courante est année/année+1 ; sinon elle vaut (année-1)/année.
 *
 * @table memberships
 */
final class Membership extends Model
{
    protected static string $table = 'memberships';

    /**
     * Libellé de la saison scolaire courante (ex: "2026-2027").
     */
    public static function seasonLabel(): string
    {
        $now = new \DateTimeImmutable('now');

        return self::seasonLabelFor((int) $now->format('Y'), (int) $now->format('n'));
    }

    /**
     * Calcule le libellé de saison pour une année/mois donnés (helper pur, testable).
     */
    public static function seasonLabelFor(int $year, int $month): string
    {
        // Mois >= 7 (juillet) : la nouvelle saison démarre.
        if ($month >= 7) {
            return $year . '-' . ($year + 1);
        }

        return ($year - 1) . '-' . $year;
    }

    /**
     * Saison active en tenant compte d'un éventuel override (setting membership_season).
     */
    public static function currentSeason(): string
    {
        $override = trim(\App\Models\Setting::get('membership_season', ''));

        return $override !== '' ? $override : self::seasonLabel();
    }

    /**
     * Adhésion payée de la saison en cours pour un utilisateur.
     *
     * @return array<string,mixed>|null
     */
    public static function currentForUser(string $userId): ?array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT * FROM memberships
                 WHERE user_id = ? AND season = ? AND status = ? LIMIT 1'
            );
            $stmt->execute([$userId, self::currentSeason(), 'PAID']);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * L'utilisateur est-il à jour de cotisation pour la saison courante ?
     */
    public static function isMember(string $userId): bool
    {
        return self::currentForUser($userId) !== null;
    }

    /**
     * Toutes les adhésions pour l'admin, triées par saison DESC puis date de paiement.
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT m.*, u.prenom, u.nom, u.email
                 FROM memberships m
                 LEFT JOIN users u ON u.id = m.user_id
                 ORDER BY m.season DESC, m.paid_at DESC, m.created_at DESC'
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Adhésions filtrées par saison (admin).
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdminBySeason(string $season): array
    {
        $season = trim($season);
        if ($season === '') {
            return self::allForAdmin();
        }

        try {
            $stmt = static::pdo()->prepare(
                'SELECT m.*, u.prenom, u.nom, u.email
                 FROM memberships m
                 LEFT JOIN users u ON u.id = m.user_id
                 WHERE m.season = ?
                 ORDER BY m.paid_at DESC, m.created_at DESC'
            );
            $stmt->execute([$season]);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Toutes les saisons distinctes (pour le filtre admin), triées DESC.
     *
     * @return list<string>
     */
    public static function seasons(): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT DISTINCT season FROM memberships ORDER BY season DESC'
            );

            /** @var list<string> $result */
            return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Statistiques par statut pour la saison courante.
     *
     * @return array{PAID:int,PENDING:int,EXPIRED:int,total:int}
     */
    public static function stats(): array
    {
        $base = ['PAID' => 0, 'PENDING' => 0, 'EXPIRED' => 0, 'total' => 0];

        try {
            $stmt = static::pdo()->prepare(
                'SELECT status, COUNT(*) AS n FROM memberships WHERE season = ? GROUP BY status'
            );
            $stmt->execute([self::currentSeason()]);

            foreach ($stmt->fetchAll() as $row) {
                $status = (string) $row['status'];
                if (isset($base[$status])) {
                    $base[$status] = (int) $row['n'];
                }
            }
        } catch (\Throwable) {
            return $base;
        }

        $base['total'] = $base['PAID'] + $base['PENDING'] + $base['EXPIRED'];

        return $base;
    }

    /**
     * Nombre de membres à jour pour la saison courante (alias concis).
     */
    public static function countPaidCurrent(): int
    {
        return self::stats()['PAID'];
    }

    /**
     * Liste des identifiants utilisateurs à jour pour une saison donnée.
     *
     * @return array<string,true>  map user_id => true (recherche O(1)).
     */
    public static function paidUserIds(string $season): array
    {
        $map = [];
        try {
            $stmt = static::pdo()->prepare(
                'SELECT user_id FROM memberships WHERE season = ? AND status = \'PAID\''
            );
            $stmt->execute([$season]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $uid) {
                $map[(string) $uid] = true;
            }
        } catch (\Throwable) {
            return [];
        }

        return $map;
    }

    /**
     * Crée une adhésion PENDING pour un utilisateur et une saison données.
     *
     * @return string L'identifiant de la nouvelle adhésion.
     */
    public static function create(string $userId, string $season): string
    {
        $id = self::generateId();

        $stmt = static::pdo()->prepare(
            'INSERT INTO memberships (id, user_id, season, status)
             VALUES (?, ?, ?, \'PENDING\')'
        );
        $stmt->execute([$id, $userId, $season]);

        return $id;
    }

    /**
     * Crée une adhésion PENDING pour la saison courante (idempotente : si une
     * adhésion existe déjà pour cette saison, on renvoie son id sans recréer).
     *
     * @return string|null L'id de l'adhésion, ou null en cas d'échec.
     */
    public static function ensureForCurrentSeason(string $userId): ?string
    {
        $season = self::currentSeason();

        try {
            $existing = static::pdo()->prepare(
                'SELECT id FROM memberships WHERE user_id = ? AND season = ? LIMIT 1'
            );
            $existing->execute([$userId, $season]);
            $row = $existing->fetch();
            if ($row !== false) {
                return (string) $row['id'];
            }

            return self::create($userId, $season);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Marque une adhésion comme payée (montant saisi + référence SumUp optionnelle).
     */
    public static function markPaid(string $id, float $amount, ?string $sumupRef = null): void
    {
        $stmt = static::pdo()->prepare(
            'UPDATE memberships
             SET status = \'PAID\', amount_paid = ?, paid_at = CURRENT_TIMESTAMP, sumup_ref = ?
             WHERE id = ?'
        );
        $stmt->execute([$amount, $sumupRef, $id]);
    }

    /**
     * Marque EXPIRED toutes les adhésions des saisons antérieures à la saison
     * courante qui ne sont pas encore payées.
     *
     * À appeler occasionnellement (cron ou à l'occasion).
     */
    public static function expireOld(): void
    {
        try {
            $stmt = static::pdo()->prepare(
                'UPDATE memberships
                 SET status = \'EXPIRED\'
                 WHERE season < ? AND status = \'PENDING\''
            );
            $stmt->execute([self::currentSeason()]);
        } catch (\Throwable) {
            // Silencieux : peut être appelé dans un cron.
        }
    }

    /**
     * Génère un identifiant unique suffisamment long.
     */
    public static function generateId(): string
    {
        return 'mbr_' . bin2hex(random_bytes(12));
    }
}
