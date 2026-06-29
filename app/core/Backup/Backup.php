<?php

declare(strict_types=1);

namespace App\Core\Backup;

use PDO;

/**
 * Sauvegarde et restauration de la base de données (dump SQL, PHP pur).
 *
 * Sans dépendance externe (pas de mysqldump). La logique est pure (basée sur
 * une connexion PDO injectable) afin d'être testable unitairement.
 */
final class Backup
{
    /**
     * Génère le dump SQL complet (structure + données) d'une base.
     *
     * @param list<string>|null $tables Tables à inclure (null = toutes).
     */
    public static function dump(PDO $pdo, ?array $tables = null): string
    {
        if ($tables === null) {
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        }

        $out = "-- Sauvegarde AEIC — " . date('c') . "\n";
        $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
            $out .= "-- Table: {$table}\n";
            $out .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $out .= $create['Create Table'] . ";\n\n";

            $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                continue;
            }

            $columns = array_map(static fn ($c): string => '`' . $c . '`', array_keys($rows[0]));
            $out .= 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ") VALUES\n";

            $values = [];
            foreach ($rows as $row) {
                $escaped = array_map(static function ($v) use ($pdo): string {
                    return $v === null ? 'NULL' : $pdo->quote((string) $v);
                }, array_values($row));
                $values[] = '(' . implode(', ', $escaped) . ')';
            }
            $out .= implode(",\n", $values) . ";\n\n";
        }

        $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        return $out;
    }

    /**
     * Découpe un dump SQL en instructions exécutables (en ignorant commentaires).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        foreach (preg_split('/\r\n|\n|\r/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($trimmed), ';')) {
                $statements[] = $buffer;
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    /**
     * Exécute un dump sur la base donnée et renvoie le nombre de requêtes.
     */
    public static function restore(PDO $pdo, string $sql): int
    {
        $count = 0;
        foreach (self::splitStatements($sql) as $stmt) {
            $pdo->exec($stmt);
            $count++;
        }

        return $count;
    }
}
