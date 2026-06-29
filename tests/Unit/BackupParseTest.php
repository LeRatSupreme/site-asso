<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Backup\Backup;
use PHPUnit\Framework\TestCase;

/**
 * Tests du découpage de dump SQL (logique pure, sans base de données).
 */
final class BackupParseTest extends TestCase
{
    public function test_split_ignore_commentaires_et_lignes_vides(): void
    {
        $sql = "-- Commentaire\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n\n"
            . "-- Encore un commentaire\n"
            . "DROP TABLE IF EXISTS `foo`;\n"
            . "INSERT INTO `foo` VALUES (1, 'a; b');\n";

        $statements = Backup::splitStatements($sql);

        self::assertCount(3, $statements);
        self::assertStringContainsString('SET FOREIGN_KEY_CHECKS', $statements[0]);
    }

    public function test_split_regroupe_les_lignes_d_une_meme_requete(): void
    {
        $sql = "CREATE TABLE `t` (\n  id INT,\n  name VARCHAR(50)\n);\n";

        $statements = Backup::splitStatements($sql);

        self::assertCount(1, $statements);
        self::assertStringContainsString('id INT', $statements[0]);
        self::assertStringContainsString('name VARCHAR(50)', $statements[0]);
    }

    public function test_split_gere_une_requete_sans_point_virgule_finale(): void
    {
        $sql = "SELECT 1";

        $statements = Backup::splitStatements($sql);

        self::assertCount(1, $statements);
    }
}
