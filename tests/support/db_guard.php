<?php

declare(strict_types=1);

/**
 * Garde anti-production partagée par toute l'infrastructure de tests.
 *
 * Incident du 26/10/2026 09h29 : un run PHPUnit démarré dans un shell où
 * config.env avait été sourcé (`set -a; source config.env` → DB_NAME=aeic
 * exporté) a hérité de la base de PRODUCTION — phpunit.xml (<env> sans
 * force) ne surcharge pas une variable déjà définie, et reset() a TRUNCATE
 * sales, products, product_aliases et scan_labels (données restaurées
 * depuis). Ne plus jamais recommencer.
 *
 * Depuis, TOUT point de connexion des tests (TestDatabaseTrait,
 * IntegrationTestCase, runner.php, db_test_bridge.php) refuse un nom de
 * base autre que « aeic_test » AVANT d'ouvrir la moindre connexion :
 * la double protection est assurée par phpunit.xml (force="true" sur
 * DB_NAME/DB_HOST/DB_USER) puis par cette garde au code.
 */

if (!function_exists('aeic_tests_assert_test_database')) {
    /**
     * Refuse tout nom de base autre que la base de test `aeic_test`.
     *
     * À appeler AVANT toute construction PDO ou requête : aucune connexion
     * ne doit même être tentée vers une base non-test.
     */
    function aeic_tests_assert_test_database(string $name): void
    {
        if ($name !== 'aeic_test') {
            throw new RuntimeException(
                'REFUS : les tests ne peuvent cibler que aeic_test, DB_NAME actuel = ' . $name
                . ' — incident du 26/10/2026 : un run a TRUNCATE la base de production ;'
                . ' ne sourcez/exportez jamais config.env avant les tests.'
            );
        }
    }
}
