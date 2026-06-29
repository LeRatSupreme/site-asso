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

// 1. Bootstrap : autoloader Composer, constantes, .env, PDO, session.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/config/routes.php';

use App\Core\Auth;
use App\Core\Router;
use App\Core\Security\SecurityHeaders;
use App\Core\Security\TwoFactorPolicy;
use App\Models\Setting;
use App\Models\TwoFactor;

// 2. En-têtes de sécurité (CSP, HSTS…) — envoyés LE PLUS TÔT possible afin
//    qu'ils soient présents sur TOUTES les réponses : page normale, erreur
//    4xx/5xx, mode maintenance, etc. Ils sont complétés par le handler de
//    shutdown pour les erreurs fatales survenant avant l'envoi.
SecurityHeaders::send(
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
    Setting::get('csp_directives', '')
);

// 3. Routeur.
$router = new Router();
aeic_register_routes($router);

// 4. Dispatch.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['REQUEST_URI'] ?? '/';

// On retire la query string pour le matching.
$qs = strpos($path, '?');
if ($qs !== false) {
    $path = substr($path, 0, $qs);
}

// Mode maintenance : bloque l'accès public (l'admin y a toujours accès).
// Ignoré en environnement de test (APP_TESTING) pour ne pas polluer les
// tests d'intégration.
if (!APP_TESTING
    && Setting::getBool('maintenance_mode', false)
    && !Auth::isAdmin()
    && !str_starts_with($path, '/login')
    && !str_starts_with($path, '/admin')
) {
    http_response_code(503);
    $siteName = Setting::get('site_name', 'AEIC');
    require AEIC_VIEWS . '/errors/maintenance.php';
    exit;
}

// Obligation 2FA : ADMIN et TRÉSORERIE doivent avoir le 2FA activé.
// On les force vers la configuration, sauf sur les routes de setup/déconnexion.
// Désactivé en environnement de test pour permettre les tests d'intégration
// des flux admin sans activer le 2FA au préalable.
if (!APP_TESTING && Auth::check()) {
    $role = (string) Auth::role();
    $allowed2fa = ['/account/2fa/setup', '/account/2fa/confirm', '/account/2fa/disable', '/logout'];
    if (TwoFactorPolicy::requires($role)
        && !TwoFactor::isEnabled((string) Auth::id())
        && !in_array($path, $allowed2fa, true)
    ) {
        redirect(url('/account/2fa/setup'));
    }
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
