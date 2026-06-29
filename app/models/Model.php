<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle de base : fournit l'accès PDO partagé et des helpers de CRUD.
 *
 * Les classes filles définissent la propriété $table.
 */
abstract class Model
{
    /** Nom de la table SQL. */
    protected static string $table;

    /**
     * Connexion PDO injectable, utilisée uniquement par les tests unitaires
     * pour brancher une base de test (aeic_test) sans dépendre de db().
     * En production, cette propriété reste null et l'on utilise db().
     */
    private static ?\PDO $testPdo = null;

    /**
     * Injecte une connexion PDO de test (remplace db()).
     * À n'utiliser que dans l'environnement de test PHPUnit.
     */
    public static function setTestPdo(?\PDO $pdo): void
    {
        self::$testPdo = $pdo;
    }

    /**
     * Retourne l'instance PDO partagée (ou celle injectée pour les tests).
     */
    final protected static function pdo(): \PDO
    {
        return self::$testPdo ?? db();
    }

    /**
     * Retourne toutes les lignes de la table.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        $stmt = static::pdo()->query('SELECT * FROM ' . static::$table);

        /** @var list<array<string,mixed>> $result */
        $result = $stmt->fetchAll();

        return $result;
    }

    /**
     * Retourne une ligne par sa clé primaire (colonne `id`).
     *
     * @return array<string,mixed>|null
     */
    public static function find(string $id): ?array
    {
        $stmt = static::pdo()->prepare('SELECT * FROM ' . static::$table . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        $row = $stmt->fetch();

        return $row ?: null;
    }
}
