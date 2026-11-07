<?php

declare(strict_types=1);

namespace Tests\Unit;

use DOMDocument;
use DOMElement;
use DOMNode;
use PHPUnit\Framework\TestCase;

/**
 * Non-régression de la CONFIG anti-production (incident du 26/10/2026) :
 *
 * phpunit.xml doit porter force="true" sur DB_NAME/DB_HOST/DB_USER — sans
 * force, PHPUnit ne surcharge PAS une variable déjà définie dans le shell
 * (vendor/.../Handler/PhpHandler.php), et un « set -a; source config.env »
 * (DB_NAME=aeic, la PROD) détournait silencieusement les tests vers la
 * base de production.
 *
 * DB_PASS en revanche ne doit JAMAIS être forcé : sa valeur vient du shell
 * (unique variable à exporter — jamais config.env entier).
 */
final class PhpunitXmlGuardTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../phpunit.xml';

    private const XML_COMMENT_NODE = 8;

    /**
     * @return array<string,DOMElement> env[@name] => élément <env>
     */
    private function envVariables(): array
    {
        $doc = new DOMDocument();
        self::assertTrue($doc->load(self::CONFIG), 'phpunit.xml doit rester lisible.');

        $vars = [];
        /** @var DOMElement $node */
        foreach ($doc->getElementsByTagName('env') as $node) {
            $vars[$node->getAttribute('name')] = $node;
        }

        return $vars;
    }

    public function test_db_name_est_force_a_aeic_test(): void
    {
        $env = $this->envVariables();

        self::assertArrayHasKey('DB_NAME', $env, 'phpunit.xml doit définir DB_NAME.');
        self::assertSame(
            'aeic_test',
            $env['DB_NAME']->getAttribute('value'),
            'DB_NAME doit cibler la base de test aeic_test.'
        );
        self::assertSame(
            'true',
            $env['DB_NAME']->getAttribute('force'),
            'DB_NAME doit porter force="true" : sinon un DB_NAME=aeic hérité du shell (config.env '
            . 'sourcé) détourne les tests vers la production — incident du 26/10/2026.'
        );
    }

    public function test_db_host_et_db_user_sont_aussi_forces(): void
    {
        $env = $this->envVariables();

        foreach (['DB_HOST', 'DB_USER'] as $name) {
            self::assertArrayHasKey($name, $env, "phpunit.xml doit définir {$name}.");
            self::assertSame(
                'true',
                $env[$name]->getAttribute('force'),
                "{$name} doit porter force=\"true\" : la valeur du shell n'a pas priorité sur la config de test."
            );
        }
    }

    public function test_db_pass_n_est_jamais_force(): void
    {
        $env = $this->envVariables();

        if (!isset($env['DB_PASS'])) {
            // Absent de la config = fourni uniquement par le shell : parfait.
            self::assertTrue(true);

            return;
        }

        self::assertNotSame(
            'true',
            $env['DB_PASS']->getAttribute('force'),
            'DB_PASS ne doit pas être forcé : la valeur du shell (seule variable à exporter) doit gagner.'
        );
    }

    /**
     * Le commentaire de configuration doit continuer d'interdire d'exporter
     * config.env entier : seul DB_PASS passe par l'environnement.
     */
    public function test_le_commentaire_interdit_d_exporter_config_env(): void
    {
        $doc = new DOMDocument();
        self::assertTrue($doc->load(self::CONFIG));

        $comments = '';
        /** @var DOMNode $child */
        foreach ($doc->getElementsByTagName('php')[0]->childNodes as $child) {
            if ($child->nodeType === self::XML_COMMENT_NODE) {
                $comments .= ' ' . trim((string) $child->nodeValue);
            }
        }

        self::assertStringContainsString('DB_PASS', $comments, 'Le commentaire doit documenter DB_PASS.');
        self::assertStringContainsString(
            'NE JAMAIS exporter config.env',
            $comments,
            'Le commentaire doit interdire d\'exporter config.env entier (DB_NAME de prod détournerait les tests).'
        );
    }
}
