<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PDOException;

/**
 * Trait de connexion à la base de test `aeic_test` pour les tests de modèles.
 *
 * Renvoie null (au lieu de lever) si la base n'est pas joignable, afin que
 * les tests puissent être marqués "skipped" plutôt qu'en erreur.
 */
trait TestDatabaseTrait
{
    /**
     * Tente de se connecter à la base de test. Retourne null si impossible.
     */
    protected function connect(): ?PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $name = getenv('DB_NAME') ?: 'aeic_test';
        $user = getenv('DB_USER') ?: 'aeic';
        $pass = getenv('DB_PASS') ?: '';

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name),
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException) {
            return null;
        }

        // Force la timezone MySQL sur UTC (cohérence avec database.php).
        try {
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException) {
            // certains environnements n'ont pas les tables tz : non bloquant.
        }

        return $pdo;
    }

    /**
     * Vide (TRUNCATE) les tables données dans une transaction, en respectant
     * les contraintes de clé étrangère.
     *
     * @param list<string> $tables
     */
    protected function reset(PDO $pdo, array $tables): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
