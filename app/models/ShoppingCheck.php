<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Cases cochées de la Liste de courses (table `shopping_checks`).
 *
 * État PARTAGÉ entre tous les appareils (kiosque et admin) : cocher ici
 * se voit partout. La trace « qui a coché » est conservée ; décocher
 * supprime la ligne (retour à « à acheter »).
 */
final class ShoppingCheck extends Model
{
    protected static string $table = 'shopping_checks';

    /**
     * Toutes les cases cochées : clé produit → auteur + horodatage.
     *
     * @return array<string,array{by:string,at:string}>
     */
    public static function all(): array
    {
        try {
            $rows = self::pdo()
                ->query('SELECT product_key, checked_by, checked_at FROM shopping_checks')
                ->fetchAll();
        } catch (\Throwable) {
            // Table pas encore migrée : rien de coché.
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['product_key']] = [
                'by' => (string) $r['checked_by'],
                'at' => (string) $r['checked_at'],
            ];
        }

        return $out;
    }

    /**
     * Coche un produit (upsert) avec la trace de qui l'a fait.
     */
    public static function mark(string $key, string $checkedBy): void
    {
        self::pdo()->prepare(
            'INSERT INTO shopping_checks (product_key, checked_by, checked_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE checked_by = VALUES(checked_by), checked_at = NOW()'
        )->execute([$key, $checkedBy]);
    }

    /**
     * Décoche un produit (la ligne disparaît).
     */
    public static function unmark(string $key): void
    {
        self::pdo()->prepare(
            'DELETE FROM shopping_checks WHERE product_key = ?'
        )->execute([$key]);
    }
}
