<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Non-régression de l'incident du 26/10/2026 20:00:39 : aeic_load_env()
 * ne doit JAMAIS écraser une variable déjà définie dans l'environnement
 * réel du process (sémantique .env « immutable », cf. phpdotenv).
 *
 * Invariant : `DB_NAME=autre_base php …` prime sur le DB_NAME de config.env.
 *
 * config.php a des effets de bord globaux (constantes, ini, shutdown
 * handler) : il est chargé dans un sous-processus dédié, sur une fixture
 * config.env isolée (AEIC_ROOT redéfini vers un dossier temporaire).
 */
final class ConfigEnvPrecedenceTest extends TestCase
{
    /**
     * Charge app/config/config.php dans un sous-processus dont AEIC_ROOT
     * pointe sur la fixture, puis retourne env('DB_NAME') résolu.
     *
     * L'environnement du sous-processus est EXPLICITE et minimal : le
     * process PHPUnit parent porte DB_NAME=aeic_test (phpunit.xml
     * force=true) qui ne doit surtout pas fuiter dans l'enfant.
     */
    private function resolvedDbName(string $fixtureDir, bool $withRealEnvOverride): string
    {
        $configPhp = dirname(__DIR__, 2) . '/app/config/config.php';

        $code = implode('', [
            "ini_set('display_errors', '0');",
            'define(\'AEIC_ROOT\', ' . var_export($fixtureDir, true) . ');',
            "define('AEIC_PUBLIC', AEIC_ROOT . '/public');",
            "define('AEIC_VIEWS', AEIC_ROOT . '/views');",
            $withRealEnvOverride ? "putenv('DB_NAME=overridden');" : '',
            'require ' . var_export($configPhp, true) . ';',
            "echo env('DB_NAME');",
        ]);

        $proc = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
                'HOME' => getenv('HOME') ?: '/tmp',
            ]
        );
        self::assertIsResource($proc, 'Le sous-processus de test doit démarrer.');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        self::assertSame(0, $exitCode, "Le sous-processus a échoué. stderr : {$stderr}");

        return trim($stdout);
    }

    /** Crée une fixture config.env (DB_NAME=fixture) dans un dossier temporaire. */
    private function makeFixture(): string
    {
        $dir = sys_get_temp_dir() . '/aeic_env_precedence_' . bin2hex(random_bytes(4));
        mkdir($dir . '/logs', 0777, true);
        file_put_contents(
            $dir . '/config.env',
            "# Fixture de test (précédence env > config.env)\nAPP_ENV=dev\nAPP_DEBUG=false\nDB_NAME=fixture\n"
        );

        return $dir;
    }

    private function removeFixture(string $dir): void
    {
        @unlink($dir . '/config.env');
        @rmdir($dir . '/logs');
        @rmdir($dir);
    }

    public function test_l_environnement_reel_prime_sur_config_env(): void
    {
        $dir = $this->makeFixture();
        try {
            $resolved = $this->resolvedDbName($dir, true);
        } finally {
            $this->removeFixture($dir);
        }

        // Cœur de la spec A : l'env réel (DB_NAME=overridden) doit primer sur
        // le DB_NAME=fixture de config.env — incident du 26/10/2026 20:00:39.
        self::assertSame(
            'overridden',
            $resolved,
            "env('DB_NAME') doit valoir 'overridden' (env réel), pas 'fixture' (config.env)."
        );
    }

    public function test_controle_sans_override_la_fixture_est_chargee(): void
    {
        $dir = $this->makeFixture();
        try {
            $resolved = $this->resolvedDbName($dir, false);
        } finally {
            $this->removeFixture($dir);
        }

        // Contre-témoin : sans variable dans l'env réel, config.env fournit
        // bien la valeur (prouve que le test de précédence n'est pas vacuo).
        self::assertSame(
            'fixture',
            $resolved,
            "Sans override, env('DB_NAME') doit venir de la fixture config.env."
        );
    }
}
