<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des mots du Wordle.
 *
 * @table wordle_words
 *
 * Difficultés :
 *   - facile   = 5 lettres
 *   - moyen    = 6 lettres
 *   - difficile = 7 lettres
 *
 * Deux modes de sélection :
 *   - dailyWord()  : mot du jour, identique pour tous, change à minuit (Paris).
 *   - randomWord() : mot aléatoire (mode libre), différent à chaque partie.
 */
final class WordleWord extends Model
{
    protected static string $table = 'wordle_words';

    public const LANGUAGES = ['fr', 'en'];
    public const DIFFICULTIES = ['facile', 'moyen', 'difficile'];

    /**
     * Longueur de grille associée à chaque difficulté.
     */
    public const LENGTHS = [
        'facile'    => 5,
        'moyen'     => 6,
        'difficile' => 7,
    ];

    /**
     * Vérifie qu'une difficulté est valide.
     */
    public static function isValidDifficulty(string $difficulty): bool
    {
        return in_array($difficulty, self::DIFFICULTIES, true);
    }

    /**
     * Date du jour au format YYYY-MM-DD dans le fuseau Europe/Paris.
     * Permet au mot du jour de changer à minuit heure française.
     */
    public static function parisDate(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        return $now->format('Y-m-d');
    }

    /**
     * Index entier déterministe basé sur le nombre de jours écoulés
     * depuis l'epoch (calculé dans le fuseau Europe/Paris).
     */
    public static function dayIndex(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $epoch = new \DateTimeImmutable('1970-01-01', new \DateTimeZone('Europe/Paris'));
        $diff = $epoch->diff($now);
        // Nombre de jours = années*365 + ... ; plus simple via timestamp.
        $days = (int) floor(($now->getTimestamp() - $epoch->getTimestamp()) / 86400);
        return $days;
    }

    /**
     * Retourne le mot du jour pour une langue et une difficulté.
     * Déterministe : identique pour tous les joueurs d'une même journée.
     */
    public static function dailyWord(string $language, string $difficulty): string
    {
        $language = strtolower(trim($language));
        $difficulty = strtolower(trim($difficulty));

        if (!in_array($language, self::LANGUAGES, true) || !self::isValidDifficulty($difficulty)) {
            return '';
        }

        // Liste triée par id (ordre stable).
        $words = self::listForDifficulty($language, $difficulty);
        if ($words === []) {
            return '';
        }

        $count = count($words);
        return $words[self::dayIndex() % $count];
    }

    /**
     * Retourne un mot aléatoire (mode libre) pour une langue et une difficulté.
     */
    public static function randomWord(string $language, string $difficulty): string
    {
        $language = strtolower(trim($language));
        $difficulty = strtolower(trim($difficulty));

        if (!in_array($language, self::LANGUAGES, true) || !self::isValidDifficulty($difficulty)) {
            return '';
        }

        $sql = 'SELECT word FROM ' . static::$table . '
                WHERE language = :lang AND difficulty = :diff AND is_active = 1
                ORDER BY RAND() LIMIT 1';

        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([':lang' => $language, ':diff' => $difficulty]);

        $word = $stmt->fetchColumn();
        return $word !== false ? (string) $word : '';
    }

    /**
     * Retourne tous les mots d'une langue + difficulté, triés par id.
     *
     * @return list<string>
     */
    public static function listForDifficulty(string $language, string $difficulty): array
    {
        $language = strtolower(trim($language));
        $difficulty = strtolower(trim($difficulty));

        if (!in_array($language, self::LANGUAGES, true) || !self::isValidDifficulty($difficulty)) {
            return [];
        }

        $sql = 'SELECT word FROM ' . static::$table . '
                WHERE language = :lang AND difficulty = :diff AND is_active = 1
                ORDER BY id ASC';

        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([':lang' => $language, ':diff' => $difficulty]);

        /** @var list<string> $words */
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Compte les mots disponibles par langue/difficulté.
     */
    public static function countForDifficulty(string $language, string $difficulty): int
    {
        $language = strtolower(trim($language));
        $difficulty = strtolower(trim($difficulty));

        if (!in_array($language, self::LANGUAGES, true) || !self::isValidDifficulty($difficulty)) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) FROM ' . static::$table . '
                WHERE language = :lang AND difficulty = :diff AND is_active = 1';

        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([':lang' => $language, ':diff' => $difficulty]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Normalise un mot : majuscules, sans accents, exactement la longueur voulue.
     * Retourne '' si invalide.
     */
    public static function normalize(string $word): string
    {
        $word = strtoupper(trim($word));
        $normalized = \Normalizer::normalize($word, \Normalizer::FORM_D);
        if ($normalized !== false) {
            $word = preg_replace('/\p{M}/u', '', $normalized) ?? $word;
        }
        if (!preg_match('/^[A-Z]+$/', $word)) {
            return '';
        }
        return $word;
    }

    // ===================== ADMIN CRUD =====================

    /**
     * Liste filtrée pour l'admin (recherche + filtres langue/difficulté).
     *
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public static function adminList(
        ?string $search = null,
        ?string $language = null,
        ?string $difficulty = null,
        int $limit = 500,
        int $offset = 0
    ): array {
        $where = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $where[] = 'word LIKE :q';
            $params[':q'] = '%' . $search . '%';
        }
        if ($language !== null && in_array($language, self::LANGUAGES, true)) {
            $where[] = 'language = :lang';
            $params[':lang'] = $language;
        }
        if ($difficulty !== null && self::isValidDifficulty($difficulty)) {
            $where[] = 'difficulty = :diff';
            $params[':diff'] = $difficulty;
        }

        $clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $countSql = 'SELECT COUNT(*) FROM ' . static::$table . ' ' . $clause;
        $stmt = static::pdo()->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $limit = max(1, min(2000, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT * FROM ' . static::$table . ' ' . $clause .
            ' ORDER BY language ASC, difficulty ASC, word ASC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = static::pdo()->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Trouve un mot par son id.
     *
     * @return array<string,mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = static::pdo()->prepare('SELECT * FROM ' . static::$table . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Crée ou met à jour un mot. Détermine la difficulté à partir de la longueur.
     *
     * @return int l'id du mot
     */
    public static function save(array $data): int
    {
        $word = self::normalize((string) ($data['word'] ?? ''));
        $language = strtolower(trim((string) ($data['language'] ?? 'fr')));
        if ($word === '' || !in_array($language, self::LANGUAGES, true)) {
            return 0;
        }

        $length = strlen($word);
        $difficulty = match ($length) {
            5 => 'facile',
            6 => 'moyen',
            7 => 'difficile',
            default => null,
        };
        if ($difficulty === null) {
            return 0;
        }

        $active = isset($data['is_active']) ? 1 : 0;
        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            // UPDATE (INSERT IGNORE pour gérer les doublons potentiels).
            $sql = 'UPDATE ' . static::$table . '
                    SET word = :word, language = :lang, length = :len, difficulty = :diff, is_active = :active
                    WHERE id = :id';
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([
                ':word' => $word, ':lang' => $language, ':len' => $length,
                ':diff' => $difficulty, ':active' => $active, ':id' => $id,
            ]);
            return $id;
        }

        // INSERT IGNORE (évite l'erreur sur doublon unique word+language).
        $sql = 'INSERT IGNORE INTO ' . static::$table . '
                (word, language, length, difficulty, is_active) VALUES
                (:word, :lang, :len, :diff, :active)';
        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([
            ':word' => $word, ':lang' => $language, ':len' => $length,
            ':diff' => $difficulty, ':active' => $active,
        ]);
        return (int) static::pdo()->lastInsertId();
    }

    /**
     * Supprime un mot.
     */
    public static function deleteRow(int $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM ' . static::$table . ' WHERE id = ?');
        $stmt->execute([$id]);
    }
}
