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

use App\Controllers\HomeController;
use App\Controllers\EventController;
use App\Controllers\PageController;
use App\Controllers\AuthController;
use App\Controllers\AccountController;
use App\Controllers\StudentController;
use App\Controllers\RegistrationController;
use App\Controllers\Admin\AdminController;
use App\Controllers\HealthController;
use App\Controllers\Admin\AdminEventController;
use App\Controllers\Admin\AdminCafeteriaController;
use App\Controllers\Admin\AdminUserController;
use App\Controllers\Admin\AdminTeamController;
use App\Controllers\Admin\AdminPageController;
use App\Controllers\Admin\AdminMediaController;
use App\Controllers\Admin\AdminSettingController;
use App\Controllers\Admin\AdminComptaController;
use App\Controllers\SeoController;
use App\Controllers\TwoFactorController;
use App\Core\Auth;
use App\Core\Router;
use App\Core\Security\TwoFactorPolicy;
use App\Core\Security\SecurityHeaders;
use App\Models\Setting;
use App\Models\TwoFactor;

// 2. Routeur.
$router = new Router();

// Pages publiques.
$router->get('/', [HomeController::class, 'index']);

$router->get('/events', [EventController::class, 'index']);
$router->get('/events/{slug}', [EventController::class, 'show']);
$router->post('/events/{slug}/register', [RegistrationController::class, 'register']);
$router->post('/events/{slug}/unregister', [RegistrationController::class, 'unregister']);

$router->get('/presentation', [PageController::class, 'presentation']);
$router->get('/team', [PageController::class, 'team']);
$router->get('/legal', [PageController::class, 'legal']);
$router->get('/privacy', [PageController::class, 'privacy']);
$router->get('/cgu', [PageController::class, 'cgu']);
$router->get('/p/{slug}', [PageController::class, 'show']);

// SEO.
$router->get('/sitemap.xml', [SeoController::class, 'sitemap']);

// Monitoring.
$router->get('/health', [HealthController::class, 'health']);

// Authentification.
$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'registerForm']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/logout', [AuthController::class, 'logout']);
$router->get('/forgot-password', [AuthController::class, 'forgotForm']);
$router->post('/forgot-password', [AuthController::class, 'forgot']);
$router->get('/reset-password', [AuthController::class, 'resetForm']);
$router->post('/reset-password', [AuthController::class, 'reset']);

// Authentification à deux facteurs.
$router->get('/login/verify', [TwoFactorController::class, 'verifyForm']);
$router->post('/login/verify', [TwoFactorController::class, 'verify']);
$router->get('/account/2fa/setup', [TwoFactorController::class, 'setupForm']);
$router->post('/account/2fa/confirm', [TwoFactorController::class, 'setupConfirm']);
$router->post('/account/2fa/disable', [TwoFactorController::class, 'disable']);

// Espace compte (RGPD).
$router->get('/account/privacy', [AccountController::class, 'privacy']);
$router->get('/account/export', [AccountController::class, 'export']);
$router->get('/account/delete', [AccountController::class, 'deleteConfirm']);
$router->post('/account/delete', [AccountController::class, 'delete']);

// Espace élève.
$router->get('/eleve', [StudentController::class, 'index']);
$router->get('/eleve/profile', [StudentController::class, 'profile']);
$router->post('/eleve/profile', [StudentController::class, 'updateProfile']);
$router->get('/eleve/inscriptions', [StudentController::class, 'inscriptions']);
$router->get('/eleve/commandes', [StudentController::class, 'commandes']);
$router->get('/eleve/cafeteria', [StudentController::class, 'cafeteria']);
$router->post('/eleve/cafeteria/add', [StudentController::class, 'cartAdd']);
$router->post('/eleve/cafeteria/remove', [StudentController::class, 'cartRemove']);
$router->post('/eleve/cafeteria/clear', [StudentController::class, 'cartClear']);
$router->post('/eleve/cafeteria/checkout', [StudentController::class, 'checkout']);

// Pages d'erreur.
$router->notFound([PageController::class, 'notFound']);
$router->methodNotAllowed([PageController::class, 'methodNotAllowed']);

// Administration (rôle ADMIN requis — vérifié par chaque contrôleur).
$router->get('/admin', [AdminController::class, 'index']);

$router->get('/admin/events', [AdminEventController::class, 'index']);
$router->get('/admin/events/new', [AdminEventController::class, 'form']);
$router->get('/admin/events/{slug}', [AdminEventController::class, 'form']);
$router->post('/admin/events/save', [AdminEventController::class, 'save']);
$router->post('/admin/events/{slug}/delete', [AdminEventController::class, 'delete']);
$router->get('/admin/events/{slug}/registrations', [AdminEventController::class, 'registrations']);

$router->get('/admin/cafeteria', [AdminCafeteriaController::class, 'products']);
$router->get('/admin/cafeteria/new', [AdminCafeteriaController::class, 'productForm']);
$router->get('/admin/cafeteria/{id}/edit', [AdminCafeteriaController::class, 'productForm']);
$router->post('/admin/cafeteria/save', [AdminCafeteriaController::class, 'saveProduct']);
$router->post('/admin/cafeteria/{id}/delete', [AdminCafeteriaController::class, 'deleteProduct']);
$router->get('/admin/cafeteria/categories', [AdminCafeteriaController::class, 'categories']);
$router->post('/admin/cafeteria/categories/save', [AdminCafeteriaController::class, 'saveCategory']);
$router->post('/admin/cafeteria/categories/{id}/delete', [AdminCafeteriaController::class, 'deleteCategory']);
$router->get('/admin/cafeteria/commandes', [AdminCafeteriaController::class, 'orders']);
$router->post('/admin/cafeteria/commandes/{id}/status', [AdminCafeteriaController::class, 'changeStatus']);
$router->get('/admin/cafeteria/pos', [AdminCafeteriaController::class, 'pos']);
$router->post('/admin/cafeteria/pos', [AdminCafeteriaController::class, 'posCheckout']);

$router->get('/admin/users', [AdminUserController::class, 'index']);
$router->post('/admin/users/{id}/role', [AdminUserController::class, 'changeRole']);
$router->post('/admin/users/{id}/toggle-active', [AdminUserController::class, 'toggleActive']);

$router->get('/admin/team', [AdminTeamController::class, 'index']);
$router->get('/admin/team/new', [AdminTeamController::class, 'form']);
$router->get('/admin/team/{id}', [AdminTeamController::class, 'form']);
$router->post('/admin/team/save', [AdminTeamController::class, 'save']);
$router->post('/admin/team/{id}/delete', [AdminTeamController::class, 'delete']);

$router->get('/admin/pages', [AdminPageController::class, 'index']);
$router->get('/admin/pages/new', [AdminPageController::class, 'form']);
$router->get('/admin/pages/{slug}', [AdminPageController::class, 'form']);
$router->post('/admin/pages/save', [AdminPageController::class, 'save']);
$router->post('/admin/pages/{slug}/delete', [AdminPageController::class, 'delete']);

$router->get('/admin/media', [AdminMediaController::class, 'index']);
$router->post('/admin/media/upload', [AdminMediaController::class, 'upload']);
$router->post('/admin/media/{id}/delete', [AdminMediaController::class, 'delete']);

$router->get('/admin/settings', [AdminSettingController::class, 'index']);
$router->post('/admin/settings/save', [AdminSettingController::class, 'save']);

// Comptabilité & gestion des achats (rôle ADMIN ou TRESORERIE).
$router->get('/admin/compta', [AdminComptaController::class, 'dashboard']);
$router->get('/admin/compta/import', [AdminComptaController::class, 'importForm']);
$router->post('/admin/compta/import', [AdminComptaController::class, 'import']);
$router->get('/admin/compta/ventes', [AdminComptaController::class, 'sales']);
$router->get('/admin/compta/produits', [AdminComptaController::class, 'products']);
$router->get('/admin/compta/categories', [AdminComptaController::class, 'categories']);
$router->get('/admin/compta/couts', [AdminComptaController::class, 'costs']);
$router->post('/admin/compta/couts/save', [AdminComptaController::class, 'saveCost']);
$router->post('/admin/compta/couts/{id}/close', [AdminComptaController::class, 'closeCost']);
$router->get('/admin/compta/aliases', [AdminComptaController::class, 'aliases']);
$router->post('/admin/compta/aliases/save', [AdminComptaController::class, 'saveAlias']);
$router->post('/admin/compta/aliases/{id}/delete', [AdminComptaController::class, 'deleteAlias']);
$router->get('/admin/compta/reappro', [AdminComptaController::class, 'reorder']);

// 3. Dispatch.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['REQUEST_URI'] ?? '/';

// On retire la query string pour le matching.
$qs = strpos($path, '?');
if ($qs !== false) {
    $path = substr($path, 0, $qs);
}

// Mode maintenance : bloque l'accès public (l'admin y a toujours accès).
if (Setting::getBool('maintenance_mode', false) && !Auth::isAdmin() && !str_starts_with($path, '/login') && !str_starts_with($path, '/admin')) {
    http_response_code(503);
    $siteName = Setting::get('site_name', 'AEIC');
    require AEIC_VIEWS . '/errors/maintenance.php';
    exit;
}

// En-têtes de sécurité (CSP, HSTS…).
SecurityHeaders::send(
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
    Setting::get('csp_directives', '')
);

// Obligation 2FA : ADMIN et TRÉSORERIE doivent avoir le 2FA activé.
// On les force vers la configuration, sauf sur les routes de setup/déconnexion.
if (Auth::check()) {
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
