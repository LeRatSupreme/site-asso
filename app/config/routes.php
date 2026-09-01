<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\Admin\AdminAnalyticsController;
use App\Controllers\Admin\AdminCafeteriaController;
use App\Controllers\Admin\AdminComptaController;
use App\Controllers\Admin\AdminController;
use App\Controllers\Admin\AdminEventController;
use App\Controllers\Admin\AdminGameController;
use App\Controllers\Admin\AdminMembershipController;
use App\Controllers\Admin\AdminMediaController;
use App\Controllers\Admin\AdminPageController;
use App\Controllers\Admin\AdminPollController;
use App\Controllers\Admin\AdminPromotionController;
use App\Controllers\Admin\AdminSettingController;
use App\Controllers\Admin\AdminSumupController;
use App\Controllers\Admin\AdminTeamController;
use App\Controllers\Admin\AdminUserController;
use App\Controllers\AuthController;
use App\Controllers\EventController;
use App\Controllers\GameController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\LocaleController;
use App\Controllers\NotificationController;
use App\Controllers\PageController;
use App\Controllers\PollController;
use App\Controllers\RegistrationController;
use App\Controllers\SeoController;
use App\Controllers\StudentController;
use App\Controllers\TwoFactorController;
use App\Core\Router;

/**
 * Enregistre toutes les routes de l'application sur le routeur donné.
 *
 * Centralisation partagée entre le front controller (public/index.php) et le
 * lanceur de tests d'intégration (tests/Integration/runner.php) afin d'éviter
 * toute divergence entre les deux points d'entrée.
 */
function aeic_register_routes(Router $router): void
{
    // Pages publiques.
    $router->get('/', [HomeController::class, 'index']);
    $router->get('/galerie', [HomeController::class, 'galerie']);

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

    // Sondages.
    $router->get('/sondages', [PollController::class, 'index']);
    $router->get('/sondages/{slug}', [PollController::class, 'show']);
    $router->post('/sondages/{slug}/vote', [PollController::class, 'vote']);

    // Zone jeux (Wordle FR/EN, 3 difficultés, 2 modes + énigme quotidienne + classement).
    $router->get('/jeux', [GameController::class, 'index']);
    $router->post('/jeux/set-pseudo', [GameController::class, 'setPseudo']);
    $router->get('/jeux/wordle', [GameController::class, 'wordle']);
    $router->get('/jeux/wordle/word', [GameController::class, 'getWord']);
    $router->post('/jeux/wordle/submit', [GameController::class, 'submitWordle']);
    $router->get('/jeux/enigme', [GameController::class, 'enigma']);
    $router->post('/jeux/enigme/check', [GameController::class, 'checkEnigma']);
    $router->get('/jeux/leaderboard', [GameController::class, 'leaderboard']);

    // SEO.
    $router->get('/sitemap.xml', [SeoController::class, 'sitemap']);
    $router->get('/search', [SeoController::class, 'search']);

    // Notifications in-app (API, Auth requise).
    $router->get('/api/notifications', [NotificationController::class, 'index']);
    $router->post('/api/notifications/read-all', [NotificationController::class, 'readAll']);

    // Monitoring.
    $router->get('/health', [HealthController::class, 'health']);

    // Sélecteur de langue (FR/EN, cookie 1 an).
    $router->post('/set-lang', [LocaleController::class, 'setLang']);
    $router->get('/set-lang', [LocaleController::class, 'setLang']);

    // Authentification.
    $router->get('/login', [AuthController::class, 'loginForm']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->get('/register', [AuthController::class, 'registerForm']);
    $router->post('/register', [AuthController::class, 'register']);
    $router->get('/logout', [AuthController::class, 'logout']);
    $router->get('/verify-email', [AuthController::class, 'verifyEmail']);
    $router->get('/resend-verification', [AuthController::class, 'resendVerification']);
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
    $router->post('/account/password', [AccountController::class, 'changePassword']);
    $router->get('/account/export', [AccountController::class, 'export']);
    $router->get('/account/delete', [AccountController::class, 'deleteConfirm']);
    $router->post('/account/delete', [AccountController::class, 'delete']);

    // Espace élève.
    $router->get('/eleve', [StudentController::class, 'index']);
    $router->get('/eleve/profile', [StudentController::class, 'profile']);
    $router->post('/eleve/profile', [StudentController::class, 'updateProfile']);
    $router->get('/eleve/inscriptions', [StudentController::class, 'inscriptions']);

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
    $router->get('/admin/events/{slug}/checkin', [AdminEventController::class, 'checkinForm']);
    $router->post('/admin/events/{slug}/checkin', [AdminEventController::class, 'checkinScan']);
    $router->post('/admin/events/{slug}/toggle-checkin', [AdminEventController::class, 'toggleCheckedIn']);
    $router->post('/admin/events/{slug}/promote', [AdminEventController::class, 'promoteWaitlist']);

    $router->get('/admin/cafeteria', [AdminCafeteriaController::class, 'products']);
    $router->get('/admin/cafeteria/new', [AdminCafeteriaController::class, 'productForm']);
    $router->get('/admin/cafeteria/{id}/edit', [AdminCafeteriaController::class, 'productForm']);
    $router->post('/admin/cafeteria/save', [AdminCafeteriaController::class, 'saveProduct']);
    $router->post('/admin/cafeteria/{id}/delete', [AdminCafeteriaController::class, 'deleteProduct']);
    $router->get('/admin/cafeteria/categories', [AdminCafeteriaController::class, 'categories']);
    $router->post('/admin/cafeteria/categories/save', [AdminCafeteriaController::class, 'saveCategory']);
    $router->post('/admin/cafeteria/categories/{id}/delete', [AdminCafeteriaController::class, 'deleteCategory']);

    $router->get('/admin/users', [AdminUserController::class, 'index']);
    $router->post('/admin/users/{id}/role', [AdminUserController::class, 'changeRole']);
    $router->post('/admin/users/{id}/toggle-active', [AdminUserController::class, 'toggleActive']);
    $router->post('/admin/users/{id}/reset-password', [AdminUserController::class, 'resetPassword']);
    $router->post('/admin/users/{id}/delete', [AdminUserController::class, 'delete']);

    // Adhésions / cotisations (rôle ADMIN requis).
    $router->get('/admin/memberships', [AdminMembershipController::class, 'index']);
    $router->post('/admin/memberships/{id}/mark-paid', [AdminMembershipController::class, 'markPaid']);
    $router->post('/admin/users/{id}/membership', [AdminMembershipController::class, 'createForMember']);

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

    $router->get('/admin/sondages', [AdminPollController::class, 'index']);
    $router->get('/admin/sondages/new', [AdminPollController::class, 'form']);
    $router->get('/admin/sondages/{id}', [AdminPollController::class, 'form']);
    $router->post('/admin/sondages/save', [AdminPollController::class, 'save']);
    $router->post('/admin/sondages/{id}/delete', [AdminPollController::class, 'delete']);

    $router->get('/admin/promotions', [AdminPromotionController::class, 'index']);
    $router->get('/admin/promotions/new', [AdminPromotionController::class, 'form']);
    $router->get('/admin/promotions/{id}', [AdminPromotionController::class, 'form']);
    $router->post('/admin/promotions/save', [AdminPromotionController::class, 'save']);
    $router->post('/admin/promotions/{id}/delete', [AdminPromotionController::class, 'delete']);

    $router->get('/admin/media', [AdminMediaController::class, 'index']);
    $router->post('/admin/media/upload', [AdminMediaController::class, 'upload']);
    $router->post('/admin/media/{id}/delete', [AdminMediaController::class, 'delete']);
    $router->post('/admin/media/{id}/update', [AdminMediaController::class, 'update']);

    // Jeux (Wordle + Énigme + classement/pseudos).
    $router->get('/admin/jeux', [AdminGameController::class, 'index']);
    $router->get('/admin/jeux/scores', [AdminGameController::class, 'scores']);
    $router->post('/admin/jeux/set-pseudo', [AdminGameController::class, 'setPseudo']);
    $router->post('/admin/jeux/reset-player', [AdminGameController::class, 'resetPlayer']);
    $router->get('/admin/jeux/wordle', [AdminGameController::class, 'wordleIndex']);
    $router->get('/admin/jeux/wordle/new', [AdminGameController::class, 'wordleForm']);
    $router->get('/admin/jeux/wordle/{id}', [AdminGameController::class, 'wordleForm']);
    $router->post('/admin/jeux/wordle/save', [AdminGameController::class, 'saveWordle']);
    $router->post('/admin/jeux/wordle/{id}/delete', [AdminGameController::class, 'deleteWordle']);
    $router->get('/admin/jeux/enigmes', [AdminGameController::class, 'enigmaIndex']);
    $router->get('/admin/jeux/enigmes/new', [AdminGameController::class, 'enigmaForm']);
    $router->get('/admin/jeux/enigmes/{id}', [AdminGameController::class, 'enigmaForm']);
    $router->post('/admin/jeux/enigmes/save', [AdminGameController::class, 'saveEnigma']);
    $router->post('/admin/jeux/enigmes/{id}/delete', [AdminGameController::class, 'deleteEnigma']);

    $router->get('/admin/settings', [AdminSettingController::class, 'index']);
    $router->get('/admin/wiki', [AdminController::class, 'wiki']);
    $router->post('/admin/settings/save', [AdminSettingController::class, 'save']);
    $router->post('/admin/settings/test-email', [AdminSettingController::class, 'testEmail']);

    // Comptabilité & gestion des achats (rôle ADMIN ou TRESORERIE).
    $router->get('/admin/compta', [AdminComptaController::class, 'dashboard']);
    $router->get('/admin/compta/import', [AdminComptaController::class, 'importForm']);
    $router->post('/admin/compta/import', [AdminComptaController::class, 'import']);
    $router->get('/admin/compta/ventes', [AdminComptaController::class, 'sales']);
    $router->get('/admin/compta/produits', [AdminComptaController::class, 'products']);
    $router->get('/admin/compta/categories', [AdminComptaController::class, 'categories']);
    $router->get('/admin/compta/couts', [AdminComptaController::class, 'costs']);
    $router->post('/admin/compta/couts/save', [AdminComptaController::class, 'saveCost']);
    $router->post('/admin/compta/couts/{id}/update', [AdminComptaController::class, 'updateCost']);
    $router->post('/admin/compta/couts/{id}/close', [AdminComptaController::class, 'closeCost']);
    $router->post('/admin/compta/couts/{id}/delete', [AdminComptaController::class, 'deleteCost']);
    $router->get('/admin/compta/aliases', [AdminComptaController::class, 'aliases']);
    $router->post('/admin/compta/aliases/save', [AdminComptaController::class, 'saveAlias']);
    $router->post('/admin/compta/aliases/{id}/delete', [AdminComptaController::class, 'deleteAlias']);
    $router->get('/admin/compta/aliases/auto', [AdminComptaController::class, 'aliasesAuto']);
    $router->post('/admin/compta/aliases/apply', [AdminComptaController::class, 'aliasesApply']);
    $router->get('/admin/compta/reappro', [AdminComptaController::class, 'reorder']);
    $router->post('/admin/compta/reappro/stocks', [AdminComptaController::class, 'saveStocks']);

    // Mini dashboard SumUp (fondé sur les ventes importées ; ADMIN/TRESORERIE).
    $router->get('/admin/sumup', [AdminSumupController::class, 'index']);

    // Dashboard analytics (graphiques Chart.js).
    $router->get('/admin/analytics', [AdminAnalyticsController::class, 'index']);
}
