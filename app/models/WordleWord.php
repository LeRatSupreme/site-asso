<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle des mots du Wordle.
 *
 * @table wordle_words
 *
 * La sélection du mot du jour est déterministe : tous les joueurs ont le
 * même mot un jour donné, et aucun mot ne se répète tant que toute la liste
 * n'a pas été parcourue (modulo la taille de la liste).
 */
final class WordleWord extends Model
{
    protected static string $table = 'wordle_words';

    /**
     * Langues acceptées par le jeu.
     */
    public const LANGUAGES = ['fr', 'en'];

    /**
     * Retourne tous les mots actifs d'une langue, triés par id (ordre stable).
     *
     * @return list<string>
     */
    public static function wordsForLanguage(string $language): array
    {
        $language = strtolower(trim($language));
        if (!in_array($language, self::LANGUAGES, true)) {
            return [];
        }

        $sql = 'SELECT word FROM ' . static::$table . '
                WHERE language = :lang AND is_active = 1
                ORDER BY id ASC';

        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([':lang' => $language]);

        /** @var list<string> $words */
        $words = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return $words;
    }

    /**
     * Nombre de mots actifs pour une langue.
     */
    public static function countForLanguage(string $language): int
    {
        $language = strtolower(trim($language));
        if (!in_array($language, self::LANGUAGES, true)) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) FROM ' . static::$table . '
                WHERE language = :lang AND is_active = 1';

        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([':lang' => $language]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Détermine le mot du jour pour une langue.
     *
     * L'index est calculé à partir du nombre de jours écoulés depuis
     * l'epoch Unix (UTC), modulo le nombre total de mots. Ainsi :
     *   - le mot est identique pour tous les joueurs d'une même journée,
     *   - aucun mot n'est répété avant que toute la liste ne soit parcourue,
     *   - la rotation reprend ensuite cycliquement.
     *
     * @param string $language 'fr' ou 'en'
     * @return string Mot de 5 lettres majuscules, ou '' si aucun mot disponible.
     */
    public static function wordOfDay(string $language): string
    {
        $words = self::wordsForLanguage($language);
        if ($words === []) {
            return '';
        }

        $count = count($words);
        $dayIndex = (int) floor(time() / 86400); // jours écoulés depuis le 01/01/1970 (UTC)

        return $words[$dayIndex % $count];
    }

    /**
     * Retourne la liste des mots pour une langue, indexée par position.
     * Utilisé pour transmettre la liste au client (sélection identique côté JS).
     *
     * @return list<string>
     */
    public static function allForLanguage(string $language): array
    {
        return self::wordsForLanguage($language);
    }

    /**
     * Ajoute un mot (normalisé en majuscules, sans accents) pour une langue.
     *
     * @return bool true si inséré, false si déjà présent (doublon ignoré).
     */
    public static function add(string $word, string $language): bool
    {
        $word = self::normalize($word);
        $language = strtolower(trim($language));

        if ($word === '' || !in_array($language, self::LANGUAGES, true)) {
            return false;
        }

        $sql = 'INSERT IGNORE INTO ' . static::$table . ' (word, language) VALUES (:word, :lang)';

        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([':word' => $word, ':lang' => $language]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Active ou désactive un mot.
     */
    public static function setActive(int $id, bool $active): void
    {
        $sql = 'UPDATE ' . static::$table . ' SET is_active = :active WHERE id = :id';

        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([':active' => $active ? 1 : 0, ':id' => $id]);
    }

    /**
     * Normalise un mot : majuscules, sans accents, exactement 5 lettres A-Z.
     * Retourne '' si le mot ne respecte pas ces critères.
     */
    public static function normalize(string $word): string
    {
        // Supprime les accents, met en majuscules.
        $word = strtoupper(trim($word));
        $word = preg_replace('/[\x{0300}-\x{036f}]/u', '', \Normalizer::normalize($word, \Normalizer::FORM_D)) ?? $word;

        if (!preg_match('/^[A-Z]{5}$/', $word)) {
            return '';
        }

        return $word;
    }
}
