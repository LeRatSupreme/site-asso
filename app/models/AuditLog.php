<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle du journal d'audit (table `audit_logs`).
 *
 * Enregistre qui a fait quoi, quand (traçabilité indispensable en comptabilité
 * et pour la gestion des comptes administrateurs).
 */
final class AuditLog extends Model
{
    protected static string $table = 'audit_logs';

    /**
     * Une entrée du journal par identifiant (null si absente) — sert à
     * relire l'archive pré-fusion d'une fusion auto pour la restauration
     * (T1MergeArchive::restaurer). Tolérante sans base.
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $id): ?array
    {
        try {
            $stmt = static::pdo()->prepare('SELECT * FROM audit_logs WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);

            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Journalise une action.
     *
     * @param array<string,mixed>|null $details
     */
    public static function log(
        string $action,
        ?string $userId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $details = null
    ): string {
        $id = 'aud_' . bin2hex(random_bytes(12));

        $stmt = static::pdo()->prepare(
            'INSERT INTO audit_logs (id, user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $action,
            $entityType,
            $entityId,
            $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            client_ip(),
        ]);

        return $id;
    }

    /**
     * Dernières entrées (les plus récentes d'abord).
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 20): array
    {
        try {
            $stmt = static::pdo()->query(
                'SELECT a.*, u.prenom, u.nom
                 FROM audit_logs a
                 LEFT JOIN users u ON u.id = a.user_id
                 ORDER BY a.created_at DESC
                 LIMIT ' . (int) $limit
            );

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Recherche filtrée pour la page « Journal d'audit » (/admin/audit).
     *
     * Filtres (tous optionnels, clés absentes ou vides = non filtré) :
     *  - « user_id » : identifiant exact (menu déroulant d'utilisateurs) ;
     *  - « action »  : action exacte (menu déroulant d'actions connues) ;
     *  - « from »    : date « YYYY-MM-DD » (inclusive, 00:00:00) ;
     *  - « to »      : date « YYYY-MM-DD » (inclusive, 23:59:59).
     *
     * Requête entièrement paramétrée (aucune valeur concaténée) ; seuls
     * LIMIT/OFFSET sont interpolés, après cast en entier. Ordre : du plus
     * récent au plus ancien.
     *
     * @param array<string,mixed> $filters
     *
     * @return list<array<string,mixed>>
     */
    public static function searchFilters(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::buildFilters($filters);

        $sql = 'SELECT a.*, u.prenom, u.nom
                FROM audit_logs a
                LEFT JOIN users u ON u.id = a.user_id'
                . $where .
                ' ORDER BY a.created_at DESC, a.id DESC
                 LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        try {
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute($params);

            /** @var list<array<string,mixed>> $result */
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Compte les entrées correspondant aux mêmes filtres que
     * searchFilters() (pagination côté page).
     *
     * @param array<string,mixed> $filters
     */
    public static function countFilters(array $filters): int
    {
        [$where, $params] = self::buildFilters($filters);

        try {
            $stmt = static::pdo()->prepare('SELECT COUNT(*) FROM audit_logs a' . $where);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Actions distinctes présentes dans le journal (menu « Action » de la
     * page d'audit), triées alphabétiquement.
     *
     * @return list<string>
     */
    public static function distinctActions(): array
    {
        try {
            $rows = static::pdo()
                ->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')
                ->fetchAll();

            $out = [];
            foreach ($rows as $r) {
                $action = (string) ($r['action'] ?? '');
                if ($action !== '') {
                    $out[] = $action;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Entity_id DISTINCTS journalisés pour une action donnée.
     *
     * Sert de liste noire aux « Suggestions de rapprochement » de
     * l'inventaire (T1 lot C) : les paires déjà décidées par l'humain
     * (audit « t1.pair_feedback », entity_id = sha1 de la paire
     * normalisée) ne doivent plus être suggérées.
     *
     * @return list<string>
     */
    /**
     * Fusions automatiques récentes (T1.5) : lignes inventory.merge
     * d'origine auto (niveaux 1 et 2), les plus récentes d'abord —
     * alimente la carte « Fusions automatiques » (section appliquées,
     * bouton de restauration via l'audit id). Tolérante sans base.
     *
     * @return list<array<string,mixed>>
     */
    public static function autoMerges(int $jours = 7, int $limit = 20): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT id, entity_id, details, created_at
                   FROM audit_logs
                  WHERE action = ?
                    AND details LIKE ?
                    AND created_at >= DATE_SUB(NOW(), INTERVAL ' . max(1, $jours) . ' DAY)
                  ORDER BY created_at DESC, id DESC
                  LIMIT ' . max(1, $limit)
            );
            $stmt->execute(['inventory.merge', '%"origine":"auto"%']);

            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $details = json_decode((string) ($row['details'] ?? ''), true);
            if (!is_array($details)) {
                continue;
            }
            $out[] = [
                'audit_id'   => (string) $row['id'],
                'source'     => (string) ($details['source'] ?? ''),
                'target'     => (string) ($details['target'] ?? ''),
                'niveau'     => (int) ($details['niveau'] ?? 0),
                'motif'      => (string) ($details['motif'] ?? ''),
                'quand'      => (string) ($row['created_at'] ?? ''),
                'archivable' => isset($details['archive']),
            ];
        }

        return $out;
    }

    public static function entityIdsForAction(string $action): array
    {
        try {
            $stmt = static::pdo()->prepare(
                'SELECT DISTINCT entity_id FROM audit_logs WHERE action = ? AND entity_id IS NOT NULL'
            );
            $stmt->execute([$action]);

            $out = [];
            /** @var list<string|null> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($rows as $id) {
                $id = (string) $id;
                if ($id !== '') {
                    $out[$id] = $id;
                }
            }

            return array_values($out);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Construit la clause WHERE paramétrée partagée par searchFilters() et
     * countFilters(). Toute valeur de filtre vide ou invalide est ignorée.
     *
     * @param array<string,mixed> $filters
     *
     * @return array{0:string, 1:list<string>}
     */
    private static function buildFilters(array $filters): array
    {
        $conds = [];
        $params = [];

        $userId = trim((string) ($filters['user_id'] ?? ''));
        if ($userId !== '') {
            $conds[] = 'a.user_id = ?';
            $params[] = $userId;
        }

        $action = trim((string) ($filters['action'] ?? ''));
        if ($action !== '') {
            $conds[] = 'a.action = ?';
            $params[] = $action;
        }

        $from = trim((string) ($filters['from'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $conds[] = 'a.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }

        $to = trim((string) ($filters['to'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $conds[] = 'a.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        return [$conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds), $params];
    }
}
