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

    // ===================== ADMIN CRUD =====================

    /**
     * Toutes les énigmes pour l'admin (triées par id).
     *
     * @return list<array<string,mixed>>
     */
    public static function allForAdmin(): array
    {
        $stmt = static::pdo()->query('SELECT * FROM ' . static::$table . ' ORDER BY id ASC');
        /** @var list<array<string,mixed>> $result */
        return $stmt->fetchAll();
    }

    /**
     * Trouve une énigme par son id.
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
     * Crée ou met à jour une énigme.
     *
     * @return int l'id de l'énigme
     */
    public static function save(array $data): int
    {
        $questionFr = trim((string) ($data['question_fr'] ?? ''));
        $questionEn = trim((string) ($data['question_en'] ?? ''));
        $answer = trim((string) ($data['answer'] ?? ''));
        $hintFr = trim((string) ($data['hint_fr'] ?? ''));
        $hintEn = trim((string) ($data['hint_en'] ?? ''));

        if ($questionFr === '' || $answer === '') {
            return 0;
        }
        if ($questionEn === '') {
            $questionEn = $questionFr;
        }

        $active = isset($data['is_active']) ? 1 : 0;
        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            $sql = 'UPDATE ' . static::$table . '
                    SET question_fr = :qfr, question_en = :qen, answer = :ans,
                        hint_fr = :hfr, hint_en = :hen, is_active = :active
                    WHERE id = :id';
            $stmt = static::pdo()->prepare($sql);
            $stmt->execute([
                ':qfr' => $questionFr, ':qen' => $questionEn, ':ans' => $answer,
                ':hfr' => ($hintFr !== '' ? $hintFr : null),
                ':hen' => ($hintEn !== '' ? $hintEn : null),
                ':active' => $active, ':id' => $id,
            ]);
            return $id;
        }

        $sql = 'INSERT INTO ' . static::$table . '
                (question_fr, question_en, answer, hint_fr, hint_en, is_active) VALUES
                (:qfr, :qen, :ans, :hfr, :hen, :active)';
        $stmt = static::pdo()->prepare($sql);
        $stmt->execute([
            ':qfr' => $questionFr, ':qen' => $questionEn, ':ans' => $answer,
            ':hfr' => ($hintFr !== '' ? $hintFr : null),
            ':hen' => ($hintEn !== '' ? $hintEn : null),
            ':active' => $active,
        ]);
        return (int) static::pdo()->lastInsertId();
    }

    /**
     * Supprime une énigme.
     */
    public static function deleteRow(int $id): void
    {
        $stmt = static::pdo()->prepare('DELETE FROM ' . static::$table . ' WHERE id = ?');
        $stmt->execute([$id]);
    }
}
