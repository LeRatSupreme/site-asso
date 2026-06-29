<?php

declare(strict_types=1);

/**
 * AEIC — Front controller (point d'entrée unique).
 *
 * Toute requête HTTP passe par ce fichier. Il charge la configuration,
 * instancie le routeur et dispatche vers le bon contrôleur.
 *
 * DocumentRoot Apache/Nginx = /var/www/aeic/public
 */

// 1. Bootstrap : constantes, .env, PDO, session.
require_once __DIR__ . '/../app/config/database.php';

use App\Controllers\HomeController;
use App\Controllers\EventController;
use App\Controllers\PageController;
use App\Core\Router;

// 2. Routeur.
$router = new Router();

// Pages publiques.
$router->get('/', [HomeController::class, 'index']);

$router->get('/events', [EventController::class, 'index']);
$router->get('/events/{slug}', [EventController::class, 'show']);

$router->get('/presentation', [PageController::class, 'presentation']);
$router->get('/team', [PageController::class, 'team']);
$router->get('/legal', [PageController::class, 'legal']);
$router->get('/privacy', [PageController::class, 'privacy']);
$router->get('/p/{slug}', [PageController::class, 'show']);

// Pages d'erreur.
$router->notFound([PageController::class, 'notFound']);
$router->methodNotAllowed([PageController::class, 'methodNotAllowed']);

// 3. Dispatch.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['REQUEST_URI'] ?? '/';

// On retire la query string pour le matching.
$qs = strpos($path, '?');
if ($qs !== false) {
    $path = substr($path, 0, $qs);
}

try {
    $router->dispatch($method, $path);
} catch (\Throwable $e) {
    http_response_code(500);
    if (APP_DEBUG) {
        echo '<h1>Erreur 500</h1><pre>' . e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
    } else {
        echo '<h1>Une erreur est survenue.</h1>';
    }
}
