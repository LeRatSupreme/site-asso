<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des scores de jeux (Wordle FR/EN).
 *
 * @table game_scores
 */
final class GameScore extends Model
{
    protected static string $table = 'game_scores';

    private const GAME = 'wordle';

    /**
     * Enregistre (ou ignore) une partie Wordle pour aujourd'hui.
     *
     * Une seule partie est conservée par jour et par mode (contrainte UNIQUE).
     * Le streak est calculé à la volée côté lecture, pas stocké en dur.
     */
    public static function saveWordleResult(
        string $userId,
        string $mode,
        bool $won,
        ?string $word,
        ?int $attempts
    ): void {
        $id = 'gs_' . bin2hex(random_bytes(10));
        $today = date('Y-m-d');

        $sql = 'INSERT IGNORE INTO game_scores
                    (id, user_id, game, mode, score, won, word, attempts, played_at)
                VALUES
                    (:id, :uid, :game, :mode, 0, :won, :word, :att, :day)';

        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([
            'id'   => $id,
            'uid'  => $userId,
            'game' => self::GAME,
            'mode' => $mode,
            'won'  => $won ? 1 : 0,
            'word' => $word,
            'att'  => $attempts,
            'day'  => $today,
        ]);
    }

    /**
     * Indique si l'utilisateur a déjà joué aujourd'hui pour ce mode.
     */
    public static function hasPlayedToday(string $userId, string $mode): bool
    {
        $sql = 'SELECT 1 FROM game_scores
                WHERE user_id = ? AND game = ? AND mode = ? AND played_at = ?
                LIMIT 1';

        try {
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([$userId, self::GAME, $mode, date('Y-m-d')]);

            return $stmt->fetch() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Série de victoires consécutives en cours.
     *
     * Compte les jours consécutifs gagnés en remontant depuis aujourd'hui.
     * Tolérance : si le joueur n'a pas encore joué aujourd'hui, on part
     * d'hier (la série reste vivante jusqu'à la fin de la journée).
     */
    public static function getUserStreak(string $userId, string $mode): int
    {
        $sql = 'SELECT played_at, won FROM game_scores
                WHERE user_id = ? AND game = ? AND mode = ?
                ORDER BY played_at DESC';

        try {
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([$userId, self::GAME, $mode]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return 0;
        }

        if (empty($rows)) {
            return 0;
        }

        $today = strtotime(date('Y-m-d'));
        $yesterday = strtotime('-1 day', $today);

        // Curseur de départ : aujourd'hui si joué aujourd'hui, sinon hier.
        $firstDay = strtotime((string) $rows[0]['played_at']);
        if ($firstDay === $today) {
            $cursor = $today;
        } elseif ($firstDay === $yesterday) {
            $cursor = $yesterday;
        } else {
            return 0; // Dernière partie avant hier : série cassée.
        }

        $streak = 0;
        foreach ($rows as $row) {
            $day = strtotime((string) $row['played_at']);
            if ($day === $cursor) {
                if ((int) $row['won'] === 1) {
                    $streak++;
                    $cursor = strtotime('-1 day', $cursor);
                } else {
                    break;
                }
            } elseif ($day < $cursor) {
                break;
            }
        }

        return $streak;
    }

    /**
     * Statistiques complètes d'un joueur pour un mode.
     *
     * @return array{played:int, won:int, currentStreak:int, maxStreak:int}
     */
    public static function getUserStats(string $userId, string $mode): array
    {
        $defaults = ['played' => 0, 'won' => 0, 'currentStreak' => 0, 'maxStreak' => 0];

        try {
            $stmt = static::pdo()->prepare(
                'SELECT played_at, won FROM game_scores
                 WHERE user_id = ? AND game = ? AND mode = ?
                 ORDER BY played_at ASC'
            );
            $stmt->execute([$userId, self::GAME, $mode]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return $defaults;
        }

        if ($rows === []) {
            return $defaults;
        }

        $played = count($rows);
        $won = 0;
        foreach ($rows as $r) {
            if ((int) $r['won'] === 1) {
                $won++;
            }
        }

        // Série max : remonte les jours consécutifs gagnés.
        $maxStreak = 0;
        $currentRun = 0;
        $prevDay = null;
        foreach ($rows as $r) {
            $day = (string) $r['played_at'];
            $isWin = (int) $r['won'] === 1;

            if ($isWin) {
                $consecutive = $prevDay !== null
                    && (strtotime($day) === strtotime('+1 day', strtotime($prevDay)));
                if ($consecutive) {
                    $currentRun++;
                } else {
                    $currentRun = 1;
                }
                if ($currentRun > $maxStreak) {
                    $maxStreak = $currentRun;
                }
            } else {
                $currentRun = 0;
            }
            $prevDay = $day;
        }

        $currentStreak = self::getUserStreak($userId, $mode);

        return [
            'played'        => $played,
            'won'           => $won,
            'currentStreak' => $currentStreak,
            'maxStreak'     => max($maxStreak, $currentStreak),
        ];
    }

    /**
     * Classement : top joueurs par série de victoires en cours.
     *
     * Le calcul de la série (courante et max) se fait en PHP à partir des
     * parties ordonnées par date pour chaque joueur.
     *
     * @return list<array<string,mixed>>
     */
    public static function getLeaderboard(string $mode, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        try {
            $sql = 'SELECT g.user_id, g.played_at, g.won,
                           u.prenom, u.nom, u.email
                    FROM game_scores g
                    INNER JOIN users u ON u.id = g.user_id
                    WHERE g.game = ? AND g.mode = ?
                    ORDER BY g.user_id, g.played_at ASC';
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([self::GAME, $mode]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        // Regroupe les parties par utilisateur.
        /** @var array<string, array{prenom:string,nom:string,email:string,rows:list<array{played_at:string,won:int}>,played:int,won:int}> $byUser */
        $byUser = [];
        foreach ($rows as $row) {
            $uid = (string) $row['user_id'];
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'prenom' => (string) $row['prenom'],
                    'nom'    => (string) $row['nom'],
                    'email'  => (string) $row['email'],
                    'rows'   => [],
                ];
            }
            $byUser[$uid]['rows'][] = [
                'played_at' => (string) $row['played_at'],
                'won'       => (int) $row['won'],
            ];
        }

        // Calcule les séries pour chaque joueur.
        $todayTs = strtotime(date('Y-m-d'));
        $yesterdayTs = strtotime('-1 day', $todayTs);
        $board = [];
        foreach ($byUser as $uid => $data) {
            $rowsAsc = $data['rows'];

            // Série max (toutes les victoires consécutives historiques).
            $maxStreak = 0;
            $run = 0;
            $prev = null;
            foreach ($rowsAsc as $r) {
                if ($r['won'] === 1) {
                    $consecutive = $prev !== null
                        && (strtotime($r['played_at']) === strtotime('+1 day', strtotime($prev)));
                    $run = $consecutive ? $run + 1 : 1;
                    if ($run > $maxStreak) {
                        $maxStreak = $run;
                    }
                } else {
                    $run = 0;
                }
                $prev = $r['played_at'];
            }

            // Série en cours (remonte depuis aujourd'hui/hier).
            $currentStreak = self::currentStreakFromRows($rowsAsc);

            $played = count($rowsAsc);
            $won = 0;
            foreach ($rowsAsc as $r) {
                if ($r['won'] === 1) {
                    $won++;
                }
            }

            $board[] = [
                'id'            => $uid,
                'prenom'        => $data['prenom'],
                'nom'           => $data['nom'],
                'email'         => $data['email'],
                'played'        => $played,
                'won'           => $won,
                'currentStreak' => $currentStreak,
                'maxStreak'     => $maxStreak,
            ];
        }

        // Tri : série en cours décroissante, puis série max, puis victoires.
        usort($board, function (array $a, array $b): int {
            if ($a['currentStreak'] !== $b['currentStreak']) {
                return $b['currentStreak'] <=> $a['currentStreak'];
            }
            if ($a['maxStreak'] !== $b['maxStreak']) {
                return $b['maxStreak'] <=> $a['maxStreak'];
            }
            return $b['won'] <=> $a['won'];
        });

        return array_slice($board, 0, $limit);
    }

    /**
     * Calcule la série en cours à partir des parties (triées desc).
     * Tolérance : si la dernière partie n'est pas aujourd'hui, on accepte hier.
     *
     * @param list<array{played_at:string,won:int}> $rowsAsc  parties triées par date croissante
     */
    private static function currentStreakFromRows(array $rowsAsc): int
    {
        if (empty($rowsAsc)) {
            return 0;
        }
        // Trie par date décroissante.
        $desc = array_reverse($rowsAsc);

        $today = strtotime(date('Y-m-d'));
        $yesterday = strtotime('-1 day', $today);

        $firstDay = strtotime((string) $desc[0]['played_at']);
        if ($firstDay === $today) {
            $cursor = $today;
        } elseif ($firstDay === $yesterday) {
            $cursor = $yesterday;
        } else {
            return 0;
        }

        $streak = 0;
        foreach ($desc as $r) {
            $day = strtotime((string) $r['played_at']);
            if ($day === $cursor) {
                if ($r['won'] === 1) {
                    $streak++;
                    $cursor = strtotime('-1 day', $cursor);
                } else {
                    break;
                }
            } elseif ($day < $cursor) {
                break;
            }
        }

        return $streak;
    }

    /**
     * Classement global (toutes langues confondues du mode quotidien).
     *
     * Affiche TOUS les joueurs ayant au moins une partie enregistrée, avec :
     *   - leur pseudo (ou à défaut prénom + initiale du nom)
     *   - leur meilleure série en cours (FR ou EN)
     *   - leur meilleure série max (FR ou EN)
     *   - le nombre total de parties (FR + EN)
     *   - le nombre total de victoires (FR + EN)
     *
     * @return list<array<string,mixed>>
     */
    public static function getGlobalLeaderboard(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        try {
            // Toutes les parties du mode quotidien (FR + EN).
            $sql = 'SELECT g.user_id, g.mode, g.played_at, g.won,
                           u.prenom, u.nom, u.pseudo
                    FROM game_scores g
                    INNER JOIN users u ON u.id = g.user_id
                    WHERE g.game = ? AND g.mode IN (?, ?)
                    ORDER BY g.user_id, g.played_at ASC';
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([self::GAME, 'daily_fr', 'daily_en']);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        // Regroupe par utilisateur, en séparant FR et EN pour le calcul des séries.
        /** @var array<string,array{pseudo:?string,prenom:string,nom:string,fr:list,won:int}> $byUser */
        $byUser = [];
        foreach ($rows as $row) {
            $uid = (string) $row['user_id'];
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'pseudo' => ($row['pseudo'] ?? null) !== null && $row['pseudo'] !== '' ? (string) $row['pseudo'] : null,
                    'prenom' => (string) $row['prenom'],
                    'nom'    => (string) $row['nom'],
                    'fr'     => [],
                    'en'     => [],
                ];
            }
            $entry = [
                'played_at' => (string) $row['played_at'],
                'won'       => (int) $row['won'],
            ];
            if ((string) $row['mode'] === 'daily_en') {
                $byUser[$uid]['en'][] = $entry;
            } else {
                $byUser[$uid]['fr'][] = $entry;
            }
        }

        $board = [];
        foreach ($byUser as $uid => $data) {
            // Séries : on prend le meilleur entre FR et EN.
            $frCur = self::currentStreakFromRows($data['fr']);
            $enCur = self::currentStreakFromRows($data['en']);
            $frMax = self::maxStreakFromRows($data['fr']);
            $enMax = self::maxStreakFromRows($data['en']);

            $currentStreak = max($frCur, $enCur);
            $maxStreak = max($frMax, $enMax);

            $played = count($data['fr']) + count($data['en']);
            $won = 0;
            foreach ($data['fr'] as $r) { if ($r['won'] === 1) $won++; }
            foreach ($data['en'] as $r) { if ($r['won'] === 1) $won++; }

            // Nom affiché : pseudo > prénom + initiale.
            $displayName = $data['pseudo'];
            if ($displayName === null || $displayName === '') {
                $initial = mb_substr(trim($data['nom']), 0, 1);
                $displayName = trim($data['prenom']) . ($initial !== '' ? ' ' . mb_strtoupper($initial) . '.' : '');
            }

            $board[] = [
                'id'            => $uid,
                'pseudo'        => $data['pseudo'],
                'displayName'   => $displayName,
                'played'        => $played,
                'won'           => $won,
                'currentStreak' => $currentStreak,
                'maxStreak'     => $maxStreak,
            ];
        }

        // Tri : série en cours, puis série max, puis victoires, puis parties.
        usort($board, function (array $a, array $b): int {
            if ($a['currentStreak'] !== $b['currentStreak']) {
                return $b['currentStreak'] <=> $a['currentStreak'];
            }
            if ($a['maxStreak'] !== $b['maxStreak']) {
                return $b['maxStreak'] <=> $a['maxStreak'];
            }
            if ($a['won'] !== $b['won']) {
                return $b['won'] <=> $a['won'];
            }
            return $b['played'] <=> $a['played'];
        });

        return array_slice($board, 0, $limit);
    }

    /**
     * Calcule la série max (historique) à partir des parties triées par date croissante.
     *
     * @param list<array{played_at:string,won:int}> $rowsAsc
     */
    private static function maxStreakFromRows(array $rowsAsc): int
    {
        $max = 0;
        $run = 0;
        $prev = null;
        foreach ($rowsAsc as $r) {
            if ($r['won'] === 1) {
                $consecutive = $prev !== null
                    && strtotime($r['played_at']) === strtotime('+1 day', strtotime($prev));
                $run = $consecutive ? $run + 1 : 1;
                if ($run > $max) {
                    $max = $run;
                }
            } else {
                $run = 0;
            }
            $prev = $r['played_at'];
        }
        return $max;
    }

    /**
     * Liste de tous les joueurs avec leurs stats, pour l'admin.
     * Inclut les utilisateurs qui n'ont jamais joué (pseudo vide, 0 parties).
     *
     * @return list<array<string,mixed>>
     */
    public static function playersForAdmin(): array
    {
        try {
            // Tous les scores quotidiens.
            $sql = 'SELECT g.user_id, g.mode, g.played_at, g.won,
                           u.prenom, u.nom, u.email, u.pseudo, u.is_active
                    FROM game_scores g
                    INNER JOIN users u ON u.id = g.user_id
                    WHERE g.game = ? AND g.mode IN (?, ?)
                    ORDER BY g.user_id, g.played_at ASC';
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([self::GAME, 'daily_fr', 'daily_en']);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }

        /** @var array<string,array{pseudo:?string,prenom:string,nom:string,email:string,is_active:int,fr:list,en:list}> $byUser */
        $byUser = [];
        foreach ($rows as $row) {
            $uid = (string) $row['user_id'];
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'pseudo'    => ($row['pseudo'] ?? null) !== null && $row['pseudo'] !== '' ? (string) $row['pseudo'] : null,
                    'prenom'    => (string) $row['prenom'],
                    'nom'       => (string) $row['nom'],
                    'email'     => (string) $row['email'],
                    'is_active' => (int) $row['is_active'],
                    'fr'        => [],
                    'en'        => [],
                ];
            }
            $entry = ['played_at' => (string) $row['played_at'], 'won' => (int) $row['won']];
            if ((string) $row['mode'] === 'daily_en') {
                $byUser[$uid]['en'][] = $entry;
            } else {
                $byUser[$uid]['fr'][] = $entry;
            }
        }

        $players = [];
        foreach ($byUser as $uid => $d) {
            $frCur = self::currentStreakFromRows($d['fr']);
            $enCur = self::currentStreakFromRows($d['en']);
            $frMax = self::maxStreakFromRows($d['fr']);
            $enMax = self::maxStreakFromRows($d['en']);

            $played = count($d['fr']) + count($d['en']);
            $won = 0;
            foreach ($d['fr'] as $r) { if ($r['won'] === 1) $won++; }
            foreach ($d['en'] as $r) { if ($r['won'] === 1) $won++; }

            $players[] = [
                'id'            => $uid,
                'pseudo'        => $d['pseudo'],
                'prenom'        => $d['prenom'],
                'nom'           => $d['nom'],
                'email'         => $d['email'],
                'is_active'     => $d['is_active'],
                'played'        => $played,
                'won'           => $won,
                'currentStreak' => max($frCur, $enCur),
                'maxStreak'     => max($frMax, $enMax),
            ];
        }

        // Tri par série en cours, puis parties.
        usort($players, function (array $a, array $b): int {
            if ($a['currentStreak'] !== $b['currentStreak']) {
                return $b['currentStreak'] <=> $a['currentStreak'];
            }
            return $b['played'] <=> $a['played'];
        });

        return $players;
    }

    /**
     * Supprime tous les scores d'un joueur (réinitialise ses stats Wordle).
     */
    public static function resetPlayer(string $userId): int
    {
        $stmt = static::pdo()->prepare('DELETE FROM ' . static::$table . ' WHERE user_id = ? AND game = ?');
        $stmt->execute([$userId, self::GAME]);
        return $stmt->rowCount();
    }
}
