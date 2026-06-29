<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Middleware;
use PHPUnit\Framework\TestCase;

/**
 * Tests du contrôle d'accès de l'espace d'administration.
 *
 * L'espace admin exige le rôle ADMIN : un visiteur est redirigé vers la
 * connexion, un élève ou la trésorerie reçoit 403, seul l'admin passe.
 *
 * On teste Middleware::resolve() (logique pure, sans redirection réelle).
 */
final class AdminGuardTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_visiteur_redirige_vers_login(): void
    {
        self::assertSame(Middleware::LOGIN, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    public function test_eleve_refuse_403(): void
    {
        $_SESSION['user_id'] = 'eleve1';
        $_SESSION['user_role'] = Auth::ROLE_ELEVE;

        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    public function test_tresorerie_refuse_403_sur_admin_general(): void
    {
        $_SESSION['user_id'] = 'tres1';
        $_SESSION['user_role'] = Auth::ROLE_TRESORERIE;

        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    public function test_admin_autorise(): void
    {
        $_SESSION['user_id'] = 'adm1';
        $_SESSION['user_role'] = Auth::ROLE_ADMIN;

        self::assertSame(Middleware::OK, Middleware::resolve([Auth::ROLE_ADMIN]));
        self::assertTrue(Middleware::isAuthorized([Auth::ROLE_ADMIN]));
    }
}
