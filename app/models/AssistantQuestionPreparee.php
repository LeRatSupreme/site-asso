<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Questions préparées de l'assistant admin (table `assistant_questions_preparees`).
 *
 * V1+ rapidité : une question type du journal (logs/assistant.jsonl) est
 * enregistrée avec le ROUTAGE d'outils de son dernier succès
 * ([{outil, params}]) et un GABARIT de réponse pré-écrit
 * (gabarit_reponse + mapping_json) ; le fast-path de
 * AdminAssistantController::ask() rejoue le tout sans LLM quand la
 * question normalisée correspond EXACTEMENT.
 *
 * `cree_le` date la capture du routage : le fast-path l'ignore au-delà
 * d'un TTL de 30 jours (7 jours si le routage FIGE des dates absolues),
 * rafraîchi par un nouveau « Préparer ».
 *
 * Lectures tolérantes aux tables absentes (avant migration : [] / null),
 * écritures lèvent — même discipline que Recommendation.
 */
final class AssistantQuestionPreparee extends Model
{
    protected static string $table = 'assistant_questions_preparees';

    /** Toutes les lignes, ordre d'affichage d'abord.
     * @return list<array<string,mixed>> */
    public static function toutes(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT * FROM assistant_questions_preparees ORDER BY ordre ASC, id ASC')
                ->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /** Lignes actives uniquement (chips + fast-path).
     * @return list<array<string,mixed>> */
    public static function actives(): array
    {
        try {
            $stmt = self::pdo()->query(
                'SELECT * FROM assistant_questions_preparees WHERE active = 1 ORDER BY ordre ASC, id ASC'
            );
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /** Ligne ACTIVE correspondant EXACTEMENT à la question normalisée. */
    public static function activeParNorm(string $norm): ?array
    {
        if ($norm === '') {
            return null;
        }
        try {
            $stmt = self::pdo()->prepare(
                'SELECT * FROM assistant_questions_preparees WHERE question_norm = ? AND active = 1 LIMIT 1'
            );
            $stmt->execute([$norm]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $row ?: null;
    }

    /**
     * Insère (ou rafraîchit) une question préparée depuis le journal :
     * libellé + routage + gabarit/mapping auto-générés (si fournis),
     * cree_le rafraîchi (TTL redémarré), réactivation, jamais de doublon.
     * Gabarit NULL → conservé tel quel lors d'un re-« Préparer » sans
     * génération possible (le formateur générique prendra le relais).
     */
    public static function upsertDepuisJournal(
        string $norm,
        string $affichee,
        string $routageJson,
        ?string $gabarit = null,
        ?string $mappingJson = null
    ): void {
        $pdo = self::pdo();
        $ordre = 1;
        try {
            $max = (int) $pdo->query('SELECT COALESCE(MAX(ordre), 0) FROM assistant_questions_preparees')->fetchColumn();
            $ordre = $max + 1;
        } catch (\Throwable) {
            $ordre = 1; // première ligne d'une base fraîche
        }

        $pdo->prepare(
            'INSERT INTO assistant_questions_preparees
                (question_norm, question_affichee, categorie, active, ordre, routage_json, gabarit_reponse, mapping_json, source_journal, cree_le)
             VALUES (?, ?, \'autre\', 1, ?, ?, ?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                question_affichee = VALUES(question_affichee),
                routage_json      = VALUES(routage_json),
                gabarit_reponse   = COALESCE(VALUES(gabarit_reponse), gabarit_reponse),
                mapping_json      = COALESCE(VALUES(mapping_json), mapping_json),
                active            = 1,
                source_journal    = 1,
                cree_le           = NOW()'
        )->execute([$norm, $affichee, $ordre, $routageJson, $gabarit, $mappingJson]);
    }

    /** Édite le gabarit d'une question préparée (mapping inchangé). */
    public static function majGabarit(int $id, string $gabarit): bool
    {
        $stmt = self::pdo()->prepare('UPDATE assistant_questions_preparees SET gabarit_reponse = ? WHERE id = ?');
        $stmt->execute([$gabarit, $id]);

        return $stmt->rowCount() >= 0; // rowCount 0 si gabarit identique : pas une erreur
    }

    /** Active/désactive une question préparée. */
    public static function definirActive(int $id, bool $active): bool
    {
        $stmt = self::pdo()->prepare('UPDATE assistant_questions_preparees SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Déplace une question d'un cran ($sens = -1 monter, +1 descendre) :
     * échange les valeurs `ordre` avec la voisine dans l'ordre courant.
     */
    public static function deplacer(int $id, int $sens): bool
    {
        if ($sens !== -1 && $sens !== 1) {
            return false;
        }

        $pdo = self::pdo();
        $lignes = $pdo->query('SELECT id, ordre FROM assistant_questions_preparees ORDER BY ordre ASC, id ASC')
            ->fetchAll();
        if (!is_array($lignes)) {
            return false;
        }

        $index = null;
        foreach ($lignes as $i => $l) {
            if ((int) $l['id'] === $id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return false;
        }
        $cible = $index + $sens;
        if ($cible < 0 || $cible >= count($lignes)) {
            return false; // bord de liste : rien à faire
        }

        $pdo->beginTransaction();
        try {
            $maj = $pdo->prepare('UPDATE assistant_questions_preparees SET ordre = ? WHERE id = ?');
            $maj->execute([(int) $lignes[$cible]['ordre'], (int) $lignes[$index]['id']]);
            $maj->execute([(int) $lignes[$index]['ordre'], (int) $lignes[$cible]['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /** Supprime une question préparée. */
    public static function supprimer(int $id): bool
    {
        $stmt = self::pdo()->prepare('DELETE FROM assistant_questions_preparees WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
