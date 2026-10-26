<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;

/**
 * Garde anti-dérive du schéma de la base de test (post-incident 26/10/2026).
 *
 * Cause A des 13 échecs du 26/10 : la table `kiosques` avait été ajoutée à
 * database/schema.sql SANS réimporter le schéma dans `aeic_test` → les tests
 * touchant cette table mouraient en 42S02 (« Base table or view not found »)
 * dispersés sur plusieurs classes (KiosquesPersonnalisesTest,
 * KiosqueSecuriteTest, DashboardPageTest, AdminPagesSmokeTest), au lieu d'un
 * message unique et actionnable.
 *
 * Ce garde vérifie AVANT toute la suite que `aeic_test` possède TOUTES les
 * tables déclarées dans database/schema.sql (parse des
 * `CREATE TABLE IF NOT EXISTS`). En cas de manque, l'échec nomme les tables
 * absentes avec la consigne de réimport — plus jamais d'erreurs 42S02
 * dispersées.
 *
 * Sécurité : lecture seule (SHOW TABLES + lecture du fichier de schéma) ;
 * la garde anti-production db_guard.php s'applique via IntegrationTestCase
 * (refus de tout DB_NAME autre que `aeic_test` AVANT connexion).
 */
final class TestDatabaseSchemaGuardTest extends IntegrationTestCase
{
    /** Chemin du schéma de référence (racine du repo). */
    private const SCHEMA_PATH = AEIC_ROOT . '/database/schema.sql';

    /**
     * Garde principale : chaque table du schema.sql existe dans aeic_test.
     */
    public function test_la_base_de_test_possede_toutes_les_tables_du_schema(): void
    {
        $pdo = $this->requireDatabase();
        $manquantes = $this->tablesManquantes($this->tablesAttendues(), $this->tablesPresentes($pdo));

        self::assertSame(
            [],
            $manquantes,
            $this->messageEchec($manquantes)
        );
    }

    /**
     * Anti-dérive du parse : le regex doit identifier les tables clés du
     * schéma (un parse silencieusement vide rendrait la garde ci-dessus
     * trivialement verte). `kiosques` est la dernière table ajoutée (celle
     * qui a causé l'incident du 26/10) : elle doit être couverte.
     */
    public function test_le_parse_du_schema_identifie_les_tables_cles(): void
    {
        $attendues = $this->tablesAttendues();

        self::assertGreaterThan(
            50,
            count($attendues),
            'Le parse de database/schema.sql trouve moins de 50 tables : format du schéma modifié ? Regex à corriger.'
        );
        foreach (['users', 'sales', 'product_packs', 'kiosques'] as $tableCle) {
            self::assertContains(
                $tableCle,
                $attendues,
                "La table clé {$tableCle} n'est pas détectée dans database/schema.sql : regex à corriger."
            );
        }
    }

    /**
     * La garde doit ÉCHOUER PROPREMENT avec son message actionnable si une
     * table du schéma manque — vérifié par simulation (une table attendue
     * factice ajoutée à la liste), SANS jamais dropper de vraie table.
     */
    public function test_le_garde_echoue_avec_un_message_explicite_si_une_table_manque(): void
    {
        $pdo = $this->requireDatabase();
        $presentes = $this->tablesPresentes($pdo);

        // Simulation de la dérive : le « schéma » contient une table que la
        // base de test n'a pas (sans y toucher réellement).
        $attenduesSimulees = array_merge($this->tablesAttendues(), ['table_factice_du_garde']);
        $manquantes = $this->tablesManquantes($attenduesSimulees, $presentes);

        self::assertSame(
            ['table_factice_du_garde'],
            $manquantes,
            'La simulation doit détecter exactement la table factice comme manquante.'
        );
        $message = $this->messageEchec($manquantes);
        self::assertStringContainsString('Base de test périmée', $message);
        self::assertStringContainsString('réimporter database/schema.sql', $message);
        self::assertStringContainsString('table_factice_du_garde', $message);
    }

    // -----------------------------------------------------------------
    //  Helpers (isolés pour rester testables sans toucher à la base)
    // -----------------------------------------------------------------

    /**
     * Tables déclarées dans database/schema.sql
     * (parse des `CREATE TABLE IF NOT EXISTS …`).
     *
     * @return list<string>
     */
    private function tablesAttendues(): array
    {
        $schema = (string) file_get_contents(self::SCHEMA_PATH);
        self::assertNotSame('', $schema, 'database/schema.sql illisible (chemin : ' . self::SCHEMA_PATH . ').');

        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?\s*\(/i', $schema, $m);

        $tables = array_values(array_unique($m[1] ?? []));
        sort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * Tables réellement présentes dans la base de test connectée.
     *
     * @return list<string>
     */
    private function tablesPresentes(PDO $pdo): array
    {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_values(array_map('strval', $tables));
        sort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * Tables du schéma absentes de la base (triées pour un message stable).
     *
     * @param list<string> $attendues
     * @param list<string> $presentes
     *
     * @return list<string>
     */
    private function tablesManquantes(array $attendues, array $presentes): array
    {
        return array_values(array_diff($attendues, $presentes));
    }

    /**
     * Message d'échec actionnable (à la place des 42S02 dispersés).
     *
     * @param list<string> $manquantes
     */
    private function messageEchec(array $manquantes): string
    {
        if ($manquantes === []) {
            return '';
        }

        $liste = implode(', ', $manquantes);
        $pluriel = count($manquantes) > 1 ? 's' : '';

        return 'Base de test périmée : réimporter database/schema.sql'
            . " (table{$pluriel} {$liste} manquante{$pluriel})"
            . ' — sinon les tests concernés échouent en 42S02 dispersés.'
            . ' Commande : mysql -u aeic -p aeic_test < database/schema.sql'
            . ' (et réimporter database/seed.sql si besoin).';
    }
}
