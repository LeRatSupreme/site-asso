<?php

declare(strict_types=1);

/**
 * Lanceur de tests d'intégration — point d'entrée exécuté dans un sous-processus
 * PHP par Tests\Integration\IntegrationTestCase.
 *
 * Il amorce l'application SANS charger config.env (afin de ne jamais pointer
 * sur la base de production), branche la base de test via les variables
 * d'environnement DB_* (aeic_test), et dispatche une requête HTTP simulée
 * (méthode / chemin / POST / fichiers) en réutilisant le même fichier de
 * routes que le front controller.
 *
 * La réponse est sérialisée en JSON sur stdout : code HTTP, en-têtes, corps,
 * instantané de session et nouvel ID de session (pour chaîner les requêtes).
 *
 * Les requêtes POST passent la vérification CSRF grâce au flag APP_TESTING
 * (court-circuité dans App\Core\Csrf::checkRequest).
 */

// --- Paramètres de la requête simulée (passés par variables d'environnement) ---
$method   = getenv('AEIC_TEST_METHOD') ?: 'GET';
$path     = getenv('AEIC_TEST_PATH') ?: '/';
$postJson = getenv('AEIC_TEST_POST') ?: '';
$filesJson = getenv('AEIC_TEST_FILES') ?: '';
$sessionId = getenv('AEIC_TEST_SESSION_ID') ?: '';
$adminId  = getenv('AEIC_TEST_FORCE_USER') ?: ''; // login forcé (bypass) pour tests admin

// --- Constantes applicatives ---
define('AEIC_ROOT', dirname(__DIR__, 2));
define('AEIC_PUBLIC', AEIC_ROOT . '/public');
define('AEIC_VIEWS', AEIC_ROOT . '/views');
define('APP_ENV', 'dev');
define('APP_DEBUG', true);
define('APP_TESTING', true);
define('APP_URL', rtrim((string) (getenv('APP_URL') ?: 'https://example.test'), '/'));

require_once AEIC_ROOT . '/vendor/autoload.php';
require_once AEIC_ROOT . '/app/config/routes.php';

/** Lecture d'une variable d'environnement (équivalent minimal de config.php). */
function env(string $key, string $default = ''): string
{
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
}

/** Connexion PDO partagée vers la base de test. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_NAME', 'aeic_test')),
        env('DB_USER', 'aeic'),
        env('DB_PASS', ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

// --- Session contrôlée (sans cookie, ID fixe fourni par le test) ---
$savePath = sys_get_temp_dir() . '/aeic_test_sessions';
if (!is_dir($savePath)) {
    @mkdir($savePath, 0777, true);
}
ini_set('session.save_path', $savePath);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_strict_mode', '0');
if ($sessionId !== '') {
    session_id($sessionId);
}
session_start();

// Login forcé (mock) pour les tests nécessitant un compte pré-existant
// (admin/trésorerie) : on pose simplement les variables de session, en
// contournant le 2FA (désactivé en APP_TESTING côté index/dispatch).
if ($adminId !== '') {
    $stmt = db()->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$adminId]);
    $u = $stmt->fetch();
    if ($u !== false) {
        $_SESSION['user_id'] = (string) $u['id'];
        $_SESSION['user_role'] = (string) $u['role'];
    }
}

// --- Simulation des superglobales ---
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $path;
$_SERVER['REQUEST_SCHEME'] = 'https';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/Integration';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['HTTP_HOST'] = 'example.test';

$_POST = [];
if ($postJson !== '') {
    $decoded = json_decode($postJson, true);
    if (is_array($decoded)) {
        $_POST = $decoded;
    }
}

$_FILES = [];
if ($filesJson !== '') {
    // Format : { "csv": { "name": "...", "tmp_name": "/path", "size": 123 } }
    $decoded = json_decode($filesJson, true);
    if (is_array($decoded)) {
        foreach ($decoded as $field => $f) {
            $_FILES[$field] = [
                'name'     => $f['name'] ?? 'upload',
                'type'     => 'text/plain',
                'tmp_name' => $f['tmp_name'] ?? '',
                'error'    => UPLOAD_ERR_OK,
                'size'     => $f['size'] ?? filesize($f['tmp_name'] ?? '') ?: 0,
            ];
        }
    }
}

use App\Core\Router;
use App\Core\Security\SecurityHeaders;

// En-têtes de sécurité envoyés (cohérence avec le front controller réel).
SecurityHeaders::send(true, '');

// --- Dispatch ---
$router = new Router();
aeic_register_routes($router);

// Capture du corps de réponse + état final, y compris en cas d'exit (redirect).
ob_start();
register_shutdown_function(static function (): void {
    $body = (string) ob_get_clean();
    $headers = headers_list();
    $payload = [
        'code'      => http_response_code(),
        'headers'   => $headers,
        'body'      => $body,
        'session'   => isset($_SESSION) ? $_SESSION : [],
        'sessionId' => session_id(),
    ];
    // Remplace tout flot de sortie éventuel par le seul envelope JSON.
    echo "\n--AEIC_TEST_RESPONSE--\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

try {
    $router->dispatch($method, $path);
} catch (\RedirectSignal $e) {
    // Redirection du contrôleur en mode test : on laisse le flot se terminer
    // normalement ; l'en-tête `Location` est capturé par headers_list() dans
    // la fonction d'arrêt ci-dessous.
} catch (\Throwable $e) {
    http_response_code(500);
    if (APP_DEBUG) {
        echo '<h1>Erreur 500</h1><pre>' . htmlspecialchars($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
    } else {
        echo '<h1>Une erreur est survenue.</h1>';
    }
}
