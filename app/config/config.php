<?php

declare(strict_types=1);

/**
 * Configuration globale de l'application AEIC.
 *
 * Charge les variables d'environnement depuis le fichier `config.env` situé
 * à la racine du projet (via un parse maison léger), puis expose des
 * constantes et configure le reporting d'erreurs selon l'environnement.
 */

define('AEIC_ROOT', dirname(__DIR__));
define('AEIC_PUBLIC', AEIC_ROOT . '/public');
define('AEIC_VIEWS', AEIC_ROOT . '/views');

/**
 * Charge un fichier .env simple (KEY=VALUE) sans bibliothèque externe.
 * Les variables sont injectées via putenv() + $_ENV + $_SERVER pour
 * pouvoir être lues par getenv() n'importe où.
 *
 * Les lignes vides et les commentaires (# ...) sont ignorés. Aucune
 * interpolation de variables n'est gérée (volontairement : KISS).
 */
function aeic_load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));

        // Retire les guillemets facultatifs autour de la valeur.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if ($key === '') {
            continue;
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

aeic_load_env(AEIC_ROOT . '/config.env');

/**
 * Helper de lecture d'une variable d'environnement.
 */
function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

// --- Constantes applicatives -------------------------------------------------

define('APP_ENV', env('APP_ENV', 'prod'));
define('APP_DEBUG', env('APP_DEBUG', APP_ENV === 'dev' ? 'true' : 'false') === 'true');
define('APP_URL', rtrim(env('APP_URL', ''), '/'));

// --- Configuration du reporting d'erreurs -----------------------------------

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

ini_set('log_errors', '1');
ini_set('error_log', AEIC_ROOT . '/logs/php-error.log');

// Session : paramètres de sécurité (seront effectifs au session_start).
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
}

// Handler d'erreurs fatals : affiche une page d'erreur propre en production.
function aeic_shutdown_handler(): void
{
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if (APP_DEBUG) {
        echo '<h1>Erreur fatale</h1><pre>' . htmlspecialchars(
            $error['message'] . "\n" . $error['file'] . ':' . $error['line']
        ) . '</pre>';
    } else {
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
            . '<title>Erreur serveur — AEIC</title></head><body>'
            . '<h1>Une erreur est survenue.</h1>'
            . '<p>Merci de réessayer dans quelques instants.</p>'
            . '</body></html>';
    }
}

register_shutdown_function('aeic_shutdown_handler');
