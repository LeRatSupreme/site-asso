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
     * Retourne l'instance PDO partagée.
     */
    final protected static function pdo(): \PDO
    {
        return db();
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
