<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle de l'énigme quotidienne.
 *
 * @table daily_enigmas
 *
 * Une devinette par jour, identique pour tous, change à minuit (heure Paris).
 * La sélection est déterministe (index du jour modulo la taille du pool).
 */
final class DailyEnigma extends Model
{
    protected static string $table = 'daily_enigmas';

    /**
     * Normalise une réponse : minuscules, sans accents, sans ponctuation,
     * espaces repliés. Sert à comparer la réponse du joueur à la solution.
     */
    public static function normalizeAnswer(string $answer): string
    {
        $answer = trim($answer);
        // Supprime les accents.
        $normalized = \Normalizer::normalize($answer, \Normalizer::FORM_D);
        if ($normalized !== false) {
            $answer = preg_replace('/\p{M}/u', '', $normalized) ?? $answer;
        }
        // Minuscules + retire la ponctuation superflue (garde lettres, chiffres, espaces).
        $answer = mb_strtolower($answer);
        $answer = preg_replace('/[^\p{L}\p{N}\s|]/u', '', $answer) ?? $answer;
        // Replie les espaces multiples.
        $answer = preg_replace('/\s+/u', ' ', $answer) ?? $answer;

        return trim($answer);
    }

    /**
     * Vérifie si une réponse utilisateur correspond à la solution acceptée.
     * La colonne `answer` peut contenir plusieurs variantes séparées par '|'.
     */
    public static function isCorrect(string $userAnswer, string $accepted): bool
    {
        $userNorm = self::normalizeAnswer($userAnswer);
        if ($userNorm === '') {
            return false;
        }

        $variants = explode('|', $accepted);
        foreach ($variants as $variant) {
            if (self::normalizeAnswer($variant) === $userNorm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index du jour (Europe/Paris) — identique à WordleWord::dayIndex().
     */
    public static function dayIndex(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $epoch = new \DateTimeImmutable('1970-01-01', new \DateTimeZone('Europe/Paris'));
        return (int) floor(($now->getTimestamp() - $epoch->getTimestamp()) / 86400);
    }

    /**
     * Date du jour au format lisible français.
     */
    public static function parisDate(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        return $now->format('Y-m-d');
    }

    /**
     * Retourne l'énigme du jour (sélection déterministe).
     *
     * @return array<string,mixed>|null
     */
    public static function enigmaOfDay(?string $language = null): ?array
    {
        $sql = 'SELECT * FROM ' . static::$table . ' WHERE is_active = 1 ORDER BY id ASC';
        $stmt = static::pdo()->query($sql);

        $all = $stmt->fetchAll();
        if (empty($all)) {
            return null;
        }

        $idx = self::dayIndex() % count($all);
        return $all[$idx] ?: null;
    }

    /**
     * Nombre total d'énigmes actives.
     */
    public static function countActive(): int
    {
        $stmt = static::pdo()->query('SELECT COUNT(*) FROM ' . static::$table . ' WHERE is_active = 1');
        return (int) $stmt->fetchColumn();
    }
}
