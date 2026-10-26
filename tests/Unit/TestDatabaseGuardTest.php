<?php

declare(strict_types=1);

namespace Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

require_once __DIR__ . '/db_test_bridge.php';

/**
 * Non-régression de la GARDE ANTI-PRODUCTION (incident du 26/10/2026 :
 * un run PHPUnit dans un shell où config.env avait été sourc —
 * set -a → DB_NAME=aeic exporté — a TRUNCATE sales/products/
 * product_aliases/scan_labels de la base de PRODUCTION).
 *
 * Cause racine : getenv('DB_NAME') héritait du shell, phpunit.xml ne
 * surchargeait pas la variable (<env> sans force), et aucun point de
 * connexion ne vérifiait le nom de base avant d'écrire.
 *
 * Ces tests prouvent que chaque point de connexion de l'infrastructure
 * (TestDatabaseTrait, IntegrationTestCase, pont db() du bridge) REFUSE,
 * AVANT toute connexion ou requête, un DB_NAME autre que « aeic_test ».
 */
final class TestDatabaseGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        db_test_bridge_disable();
    }

    /**
     * Exécute $scenario avec DB_NAME (et optionnellement DB_HOST) temporaires,
     * puis restaure l'environnement quoi qu'il arrive.
     *
     * @param callable():mixed $scenario
     */
    private function withPoisonedEnvironment(string $name, string $host, callable $scenario): void
    {
        $previousName = getenv('DB_NAME');
        $previousHost = getenv('DB_HOST');
        putenv('DB_NAME=' . $name);
        putenv('DB_HOST=' . $host);
        try {
            $scenario();
        } finally {
            $previousName === false ? putenv('DB_NAME') : putenv('DB_NAME=' . $previousName);
            $previousHost === false ? putenv('DB_HOST') : putenv('DB_HOST=' . $previousHost);
        }
    }

    /**
     * Expose les méthodes protégées du trait sans impliquer la moindre
     * connexion réelle.
     */
    private function traitConnector(): object
    {
        return new class () {
            use TestDatabaseTrait;

            public function exposeConnect(): ?PDO
            {
                return $this->connect();
            }

            /** @param list<string> $tables */
            public function exposeReset(PDO $pdo, array $tables): void
            {
                $this->reset($pdo, $tables);
            }
        };
    }

    /**
     * Sous-classe minimale d'IntegrationTestCase pour atteindre les méthodes
     * protégées connect()/requireDatabase().
     */
    private function integrationCase(): IntegrationTestCase
    {
        return new class ('garde-anti-prod') extends IntegrationTestCase {
            public function exposeConnect(): ?PDO
            {
                return $this->connect();
            }

            public function exposeRequireDatabase(): PDO
            {
                return $this->requireDatabase();
            }
        };
    }

    public function test_le_trait_refuse_db_name_de_production_avant_toute_connexion(): void
    {
        $connector = $this->traitConnector();

        try {
            // DB_HOST injoignable : si une connexion était tentée, on aurait
            // une PDOException (ou un retour null après timeout) — jamais ce
            // refus instantané. L'exception prouve qu'aucun PDO n'est construit.
            $this->withPoisonedEnvironment('aeic', '10.255.255.1', static fn () => $connector->exposeConnect());
            self::fail('connect() devait refuser DB_NAME=aeic (base de production).');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(
                'REFUS : les tests ne peuvent cibler que aeic_test, DB_NAME actuel = aeic',
                $e->getMessage()
            );
        }
    }

    public function test_integration_connect_refuse_db_name_de_production(): void
    {
        $case = $this->integrationCase();

        try {
            $this->withPoisonedEnvironment('aeic', '10.255.255.1', static fn () => $case->exposeConnect());
            self::fail('IntegrationTestCase::connect() devait refuser DB_NAME=aeic.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(
                'REFUS : les tests ne peuvent cibler que aeic_test, DB_NAME actuel = aeic',
                $e->getMessage()
            );
        }
    }

    public function test_integration_require_database_propage_le_refus_au_lieu_de_sauter(): void
    {
        $case = $this->integrationCase();

        try {
            $this->withPoisonedEnvironment('aeic', '10.255.255.1', static fn () => $case->exposeRequireDatabase());
            self::fail('requireDatabase() devait refuser DB_NAME=aeic, pas marquer le test skipped.');
        } catch (RuntimeException $e) {
            // Un environnement détourné vers la prod doit faire ÉCHOUER le
            // run (erreur bruyante), jamais le sauter silencieusement.
            self::assertStringContainsString('REFUS', $e->getMessage());
        }
    }

    /**
     * Ceinture et bretelles : reset() (le TRUNCATE) re-vérifie la base
     * RÉELLEMENT connectée. Le faux PDO prétend être sur « aeic » — le
     * reset doit refuser avant le moindre TRUNCATE (instance jamais
     * connectée : aucun risque réel ici, et un TRUNCATE levait de toute
     * façon une erreur).
     */
    public function test_reset_refuse_une_connexion_branchee_sur_une_autre_base(): void
    {
        $fake = (new ReflectionClass(GuardFakeProdPdo::class))->newInstanceWithoutConstructor();

        try {
            $this->traitConnector()->exposeReset($fake, ['sales']);
            self::fail('reset() devait refuser de TRUNCATE une connexion hors aeic_test.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(
                'REFUS : les tests ne peuvent cibler que aeic_test, DB_NAME actuel = aeic',
                $e->getMessage()
            );
        }
    }

    /**
     * Pont db() global (db_test_bridge.php) : même refus, prouvé dans un
     * sous-processus dédié pour éviter le cache statique de PDO du pont
     * (déjà peuplé si CostLotStockDbTest a tourné avant dans le même run).
     */
    public function test_le_pont_db_test_bridge_refuse_db_name_de_production(): void
    {
        $code = sprintf(
            "putenv('DB_NAME=aeic'); require %s; db_test_bridge_enable();"
            . " try { db(); exit('CONNECTE'); }"
            . " catch (RuntimeException \$e) { echo \$e->getMessage(); }",
            var_export(__DIR__ . '/db_test_bridge.php', true)
        );

        exec(
            sprintf('%s -r %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($code)),
            $output,
            $exitCode
        );
        $stdout = implode("\n", $output);

        self::assertStringNotContainsString('CONNECTE', $stdout, 'db() ne doit JAMAIS se connecter avec DB_NAME=aeic.');
        self::assertStringContainsString(
            'REFUS : les tests ne peuvent cibler que aeic_test, DB_NAME actuel = aeic',
            $stdout
        );
        self::assertSame(0, $exitCode);
    }

    public function test_la_garde_accepte_le_nom_de_base_de_test(): void
    {
        aeic_tests_assert_test_database('aeic_test');

        self::assertTrue(true, 'aeic_test passe la garde sans lever.');
    }
}

/**
 * Faux PDO qui prétend (via SELECT DATABASE()) être connecté à la base de
 * production « aeic ». Créé par réflexion SANS constructeur : aucune
 * connexion réelle n'existe, le test est totalement inoffensif.
 */
final class GuardFakeProdPdo extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchArgs): PDOStatement|false
    {
        return new GuardFakeProdStatement();
    }
}

final class GuardFakeProdStatement extends PDOStatement
{
    public function fetchColumn(int $column = 0): mixed
    {
        return 'aeic';
    }
}
