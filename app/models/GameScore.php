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
}
