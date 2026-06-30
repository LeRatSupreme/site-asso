<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base des tests d'intégration : exécute de VRAIES requêtes HTTP simulées
 * contre l'application branchée sur la base de test `aeic_test`.
 *
 * Chaque requête est jouée dans un sous-processus PHP dédié
 * (tests/Integration/runner.php) car les contrôleurs utilisent `exit` (via
 * redirect()). La réponse est renvoyée sérialisée (code, en-têtes, corps,
 * session, nouvel ID de session).
 *
 * La base `aeic_test` doit exister et avoir importé database/schema.sql.
 * Les tests sautent automatiquement si la base n'est pas joignable.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected ?PDO $pdo = null;
    private string $sessionId = '';
    private string $lastSessionId = '';

    /** Chemin du lanceur de sous-processus. */
    private function runnerPath(): string
    {
        return __DIR__ . '/runner.php';
    }

    /**
     * Connexion à la base de test (null si indisponible).
     */
    protected function connect(): ?PDO
    {
        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    getenv('DB_HOST') ?: '127.0.0.1',
                    getenv('DB_NAME') ?: 'aeic_test'
                ),
                getenv('DB_USER') ?: 'aeic',
                getenv('DB_PASS') ?: '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException) {
            return null;
        }

        return $pdo;
    }

    /**
     * Vérifie la disponibilité de la base de test et marque le test skipped sinon.
     */
    protected function requireDatabase(): PDO
    {
        $pdo = $this->connect();
        if ($pdo === null) {
            self::markTestSkipped('Base aeic_test indisponible : créez-la et importez database/schema.sql.');
        }
        $this->pdo = $pdo;

        return $pdo;
    }

    /**
     * Vide les tables données (contraintes FK temporairement désactivées).
     *
     * @param list<string> $tables
     */
    protected function reset(array $tables): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $this->pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function setUp(): void
    {
        // Nouvelle session logique pour chaque test.
        $this->sessionId = 'test_' . bin2hex(random_bytes(8));
        $this->lastSessionId = $this->sessionId;

        // Le RateLimiter (login) repose sur des fichiers persistants ; on le
        // nettoie pour éviter qu'une accumulation de tentatives (toujours
        // depuis 127.0.0.1) ne verrouille les tests d'authentification.
        $this->clearRateLimiter();
    }

    /**
     * Vide le cache du RateLimiter (compteurs de login) pour isoler les tests.
     */
    private function clearRateLimiter(): void
    {
        $dir = dirname(__DIR__, 2) . '/cache/ratelimit';
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    /**
     * Exécute une requête simulée et renvoie la réponse parsée.
     *
     * @param array<string,mixed>                $post
     * @param array<string,array{name:string,tmp_name:string,size?:int}> $files
     * @param string                             $forceUserId Login forcé (admin) sans passer par /login.
     */
    protected function request(
        string $method,
        string $path,
        array $post = [],
        array $files = [],
        string $forceUserId = ''
    ): array {
        // On hérite de l'environnement système (PATH, SYSTEMROOT, TEMP…)
        // puis on applique les variables de test (surcharge).
        $env = array_merge(
            $this->inheritedEnv(),
            [
                'AEIC_TEST_METHOD'     => strtoupper($method),
                'AEIC_TEST_PATH'       => $path,
                'AEIC_TEST_POST'       => $post !== [] ? json_encode($post, JSON_UNESCAPED_UNICODE) : '',
                'AEIC_TEST_FILES'      => $files !== [] ? json_encode($files, JSON_UNESCAPED_UNICODE) : '',
                'AEIC_TEST_SESSION_ID' => $this->lastSessionId,
                'AEIC_TEST_FORCE_USER' => $forceUserId,
                'APP_TESTING'          => 'true',
                'APP_URL'              => 'https://example.test',
                'DB_HOST'              => getenv('DB_HOST') ?: '127.0.0.1',
                'DB_NAME'              => getenv('DB_NAME') ?: 'aeic_test',
                'DB_USER'              => getenv('DB_USER') ?: 'aeic',
                'DB_PASS'              => getenv('DB_PASS') ?: '',
            ],
            $this->extraEnv()
        );

        $output = $this->runPhp($this->runnerPath(), $env);
        $response = $this->parseResponse($output);

        // Chaînage : on reprend le nouvel ID de session (régénéré au login).
        if ($response['sessionId'] !== '') {
            $this->lastSessionId = $response['sessionId'];
        }

        return $response;
    }

    /**
     * Variables d'environnement additionnelles (surcharge par sous-classe).
     *
     * @return array<string,string>
     */
    protected function extraEnv(): array
    {
        return [];
    }

    /**
     * Récupère l'environnement courant à transmettre au sous-processus
     * (nécessaire sous Windows : SYSTEMROOT, TEMP, PATH…).
     *
     * @return array<string,string>
     */
    private function inheritedEnv(): array
    {
        // getenv() sans argument renvoie toutes les variables (PHP >= 7.1).
        $all = getenv();
        $env = [];
        foreach ($all as $k => $v) {
            $env[(string) $k] = (string) $v;
        }

        return $env;
    }

    /**
     * Connecte un utilisateur via le vrai flux /login et chaîne la session.
     */
    protected function login(string $email, string $password): array
    {
        return $this->request('POST', '/login', [
            'email'    => $email,
            'password' => $password,
        ]);
    }

    /**
     * Renvoie l'en-tête Location d'une réponse (ou '').
     */
    protected function location(array $response): string
    {
        foreach ($response['headers'] ?? [] as $h) {
            if (stripos($h, 'Location:') === 0) {
                return trim(substr($h, 9));
            }
        }

        return '';
    }

    /**
     * Exécute un script PHP dans un sous-processus et renvoie stdout.
     *
     * @param array<string,string> $env
     */
    private function runPhp(string $script, array $env): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // On passe l'environnement via proc_open (pas d'héritage du shell parent).
        $proc = @proc_open([PHP_BINARY, $script], $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            self::fail('Impossible de lancer le sous-processus PHP (' . PHP_BINARY . ').');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        // On transmet stderr vers le rapport de test en cas d'erreur fatale.
        if (trim($stderr) !== '' && str_contains($stderr, 'Fatal error')) {
            self::fail('Erreur fatale dans le sous-processus : ' . $stderr);
        }

        proc_close($proc);

        return $stdout;
    }

    /**
     * Isole l'enveloppe JSON produite par le runner.
     */
    private function parseResponse(string $output): array
    {
        $marker = "\n--AEIC_TEST_RESPONSE--\n";
        $pos = strpos($output, $marker);
        if ($pos === false) {
            self::fail('Réponse du runner illisible (marqueur absent). Sortie : ' . substr($output, 0, 1000));
        }

        $json = substr($output, $pos + strlen($marker));
        $data = json_decode($json, true);
        if (!is_array($data)) {
            self::fail('JSON de réponse invalide : ' . substr($json, 0, 1000));
        }

        return $data;
    }

    // -----------------------------------------------------------------
    //  Helpers de seeding communs
    // -----------------------------------------------------------------

    /**
     * Crée un utilisateur direct en base avec un mot de passe known.
     */
    protected function seedUser(
        string $id,
        string $email,
        string $password = 'Password1',
        string $role = 'ELEVE',
        int $active = 1
    ): string {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $this->pdo->prepare(
            'INSERT INTO users (id, prenom, nom, email, password, role, is_active, email_verified_at)
             VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
        )->execute([$id, 'Test', 'User', $email, $hash, $role, $active]);

        return $id;
    }
}
