<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Budgets prévisionnels (table `budgets`).
 *
 * Ligne = (année, mois, catégorie) avec un montant prévu. Catégorie
 * spéciale 'CA' pour l'objectif de chiffre d'affaires ; les autres
 * catégories correspondent aux dépenses (Expense::CATEGORIES).
 */
final class Budget extends Model
{
    protected static string $table = 'budgets';

    /**
     * Crée ou met à jour une ligne budgétaire.
     *
     * Un montant prévu <= 0 supprime la ligne si elle existe
     * (zéro = pas de budget).
     */
    public static function upsert(int $year, int $month, string $category, float $planned): void
    {
        $category = trim($category);

        if ($month < 1 || $month > 12 || $year < 2020 || $category === '') {
            return;
        }

        if ($planned <= 0) {
            self::pdo()->prepare(
                'DELETE FROM budgets WHERE year = ? AND month = ? AND category = ?'
            )->execute([$year, $month, $category]);

            return;
        }

        self::pdo()->prepare(
            'INSERT INTO budgets (id, year, month, category, planned_amount, created_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE planned_amount = VALUES(planned_amount)'
        )->execute(['budget_' . bin2hex(random_bytes(10)), $year, $month, $category, $planned]);
    }

    /**
     * Budgets d'un mois donné.
     *
     * @return array<string,float> Clé = catégorie, valeur = montant prévu.
     */
    public static function forMonth(int $year, int $month): array
    {
        try {
            $stmt = self::pdo()->prepare(
                'SELECT category, planned_amount FROM budgets WHERE year = ? AND month = ?'
            );
            $stmt->execute([$year, $month]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['category']] = (float) $r['planned_amount'];
        }

        return $out;
    }
}
