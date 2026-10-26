<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/db_guard.php';

/**
 * Garde de ciblage de scripts/restore.php (incident du 26/10/2026 20:00:39) :
 *
 *  - sans --db ni --force : refus (exit 1) de restaurer dans la base de
 *    config.env, SANS connexion ni écriture — y compris quand MySQL est
 *    absent (fail-closed : le refus ne dépend pas de la connectivité) ;
 *  - --db : identifiant simple exigé (rien d'exotique dans le DSN) ;
 *  - round-trip backup→restore : uniquement sur la base jetable
 *    aeic_restore_test (grant MySQL dédié), recréée puis droppée par le test.
 *
 * restore.php écrit dans sa base cible : il n'est donc JAMAIS exécuté dans
 * le process PHPUnit, toujours en sous-processus à environnement maîtrisé.
 * DB_NAME est volontairement ABSENT de cet environnement : la cible par
 * défaut doit venir de config.env, jamais de l'héritage phpunit
 * (DB_NAME=aeic_test forcé par phpunit.xml).
 */
final class RestoreScriptTargetingTest extends TestCase
{
    use TestDatabaseTrait;

    private const THROWAWAY_DB = 'aeic_restore_test';

    /** @return array{0: int, 1: string} [code de sortie, sortie stdout+stderr] */
    private function runScript(string $script, array $args, array $extraEnv = []): array
    {
        $proc = proc_open(
            array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/' . $script], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            array_merge($this->baseEnv(), $extraEnv)
        );
        self::assertIsResource($proc, "Impossible de lancer le sous-processus ({$script}).");

        fclose($pipes[0]); // STDIN fermé : jamais de TTY → confirmation interactive impossible
        $output = (string) stream_get_contents($pipes[1])
            . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [$exitCode, $output];
    }

    /** @return array<string,string> env minimal — DB_NAME volontairement absent */
    private function baseEnv(): array
    {
        return [
            'PATH'    => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME'    => getenv('HOME') ?: '/tmp',
            'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
            'DB_USER' => getenv('DB_USER') ?: 'aeic',
            'DB_PASS' => getenv('DB_PASS') ?: '',
        ];
    }

    /** Petit dump légitime (jamais restauré ailleurs que sur la base jetable). */
    private function tinyDumpPath(): string
    {
        $path = sys_get_temp_dir() . '/aeic_restore_refusal_' . bin2hex(random_bytes(4)) . '.sql';
        file_put_contents(
            $path,
            "-- Dump minimal de test\nSET FOREIGN_KEY_CHECKS = 0;\n"
            . "DROP TABLE IF EXISTS `marker`;\nCREATE TABLE `marker` (id INT) ENGINE=InnoDB;\n"
            . "SET FOREIGN_KEY_CHECKS = 1;\n"
        );

        return $path;
    }

    private function removeFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function test_sans_db_ni_force_la_base_de_config_env_est_refusee(): void
    {
        $dump = $this->tinyDumpPath();
        try {
            // Aucun DB_NAME dans l'env : la cible par défaut = base de
            // config.env (production sur le VPS). STDIN non interactif.
            [$exitCode, $output] = $this->runScript('scripts/restore.php', [$dump]);
        } finally {
            $this->removeFile($dump);
        }

        self::assertSame(1, $exitCode, "Sans --db ni --force, restore.php doit refuser (exit 1). Sortie :\n{$output}");
        self::assertStringContainsString('REFUS', $output);
        self::assertStringContainsString('--force', $output);
        self::assertStringContainsString('Tables du dump        : 1', $output);
        self::assertStringNotContainsString('Restauration terminée', $output, 'Aucune écriture ne doit être effectuée.');
    }

    public function test_option_db_rejette_un_nom_de_base_invalide(): void
    {
        $dump = $this->tinyDumpPath();
        try {
            [$exitCode, $output] = $this->runScript('scripts/restore.php', [$dump, '--db', 'ae;ic']);
        } finally {
            $this->removeFile($dump);
        }

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--db', $output);
        self::assertStringContainsString('invalide', $output);
    }

    public function test_round_trip_backup_puis_restore_sur_base_jetable(): void
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base de test aeic_test indisponible (MySQL) — round-trip restore sauté.');
        }

        // Marqueur dédié : rend le test autonome vis-à-vis de l'état de la suite.
        $pdo->exec('DROP TABLE IF EXISTS `restore_roundtrip_marker`');
        $pdo->exec(
            'CREATE TABLE `restore_roundtrip_marker` ('
            . 'id INT NOT NULL PRIMARY KEY, label VARCHAR(64) NOT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec("INSERT INTO `restore_roundtrip_marker` (id, label) VALUES (1, 'avant-dump')");

        $dump = sys_get_temp_dir() . '/aeic_roundtrip_' . bin2hex(random_bytes(4)) . '.sql';

        try {
            // 1) Dump de la base de test via la vraie chaîne backup.php
            //    (valide aussi son autoload en bout en bout).
            [$bkCode, $bkOut] = $this->runScript('scripts/backup.php', [$dump], ['DB_NAME' => 'aeic_test']);
            self::assertSame(0, $bkCode, "backup.php doit réussir. Sortie :\n{$bkOut}");
            self::assertFileExists($dump);

            // 2) Base jetable recréée (le script de restauration ne la crée pas).
            $pdo->exec('DROP DATABASE IF EXISTS `' . self::THROWAWAY_DB . '`');
            $pdo->exec(
                'CREATE DATABASE `' . self::THROWAWAY_DB . '`'
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            // 3) Restauration --db vers la jetable (≠ base de config.env →
            //    autorisée sans --force). Env SANS DB_NAME : la cible par
            //    défaut doit être résolue depuis config.env, pas héritée.
            [$rsCode, $rsOut] = $this->runScript(
                'scripts/restore.php',
                [$dump, '--db', self::THROWAWAY_DB]
            );
            self::assertSame(0, $rsCode, "restore.php --db doit réussir. Sortie :\n{$rsOut}");
            self::assertStringContainsString(self::THROWAWAY_DB, $rsOut);
            self::assertStringContainsString('Restauration terminée', $rsOut);
            self::assertStringNotContainsString('REFUS', $rsOut);

            // 4) Vérifications lecture seule, depuis la connexion aeic_test
            //    (requêtes qualifiées : aucun point de connexion supplémentaire).
            $tablesTest = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'aeic_test'"
            )->fetchColumn();
            $tablesThrow = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '
                . var_export(self::THROWAWAY_DB, true)
            )->fetchColumn();
            self::assertGreaterThan(0, $tablesTest, 'aeic_test doit contenir des tables pour un round-trip significatif.');
            self::assertSame($tablesTest, $tablesThrow, 'La base jetable doit contenir autant de tables que aeic_test.');

            $label = $pdo->query(
                'SELECT label FROM `' . self::THROWAWAY_DB . '`.`restore_roundtrip_marker` WHERE id = 1'
            )->fetchColumn();
            self::assertSame('avant-dump', (string) $label, 'Les données du dump doivent être restaurées à l\'identique.');

            $hasSales = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables"
                . " WHERE table_schema = 'aeic_test' AND table_name = 'sales'"
            )->fetchColumn();
            if ($hasSales === 1) {
                $salesTest = (int) $pdo->query('SELECT COUNT(*) FROM `aeic_test`.`sales`')->fetchColumn();
                $salesThrow = (int) $pdo->query(
                    'SELECT COUNT(*) FROM `' . self::THROWAWAY_DB . '`.`sales`'
                )->fetchColumn();
                self::assertSame($salesTest, $salesThrow, 'COUNT(sales) doit être identique après round-trip.');
            }
        } finally {
            // Nettoyage systématique, même en cas d'échec.
            $this->removeFile($dump);
            $pdo->exec('DROP TABLE IF EXISTS `restore_roundtrip_marker`');
            $pdo->exec('DROP DATABASE IF EXISTS `' . self::THROWAWAY_DB . '`');
        }
    }
}
