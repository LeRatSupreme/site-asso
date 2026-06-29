<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Middleware;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de la logique de contrôle d'accès (Middleware::resolve).
 *
 * On manipule directement $_SESSION pour simuler l'authentification, sans
 * déclencher les effets de bord (redirection / exit) des guards.
 */
final class MiddlewareTest extends TestCase
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

    public function test_visiteur_non_connecte_doit_se_connecter(): void
    {
        self::assertSame(Middleware::LOGIN, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    public function test_eleve_n_a_pas_acces_admin(): void
    {
        $_SESSION['user_id'] = 'u1';
        $_SESSION['user_role'] = Auth::ROLE_ELEVE;

        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    public function test_admin_a_acces_admin(): void
    {
        $_SESSION['user_id'] = 'u2';
        $_SESSION['user_role'] = Auth::ROLE_ADMIN;

        self::assertSame(Middleware::OK, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    public function test_tresorerie_accede_a_la_compta_uniquement(): void
    {
        $_SESSION['user_id'] = 'u3';
        $_SESSION['user_role'] = Auth::ROLE_TRESORERIE;

        self::assertSame(Middleware::OK, Middleware::resolve([Auth::ROLE_ADMIN, Auth::ROLE_TRESORERIE]));
        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    public function test_eleve_accede_a_l_espace_membre(): void
    {
        $_SESSION['user_id'] = 'u4';
        $_SESSION['user_role'] = Auth::ROLE_ELEVE;

        $membre = [Auth::ROLE_ELEVE, Auth::ROLE_ADMIN];
        self::assertTrue(Middleware::isAuthorized($membre));
    }

    public function test_visiteur_n_est_jamais_autorise(): void
    {
        self::assertFalse(Middleware::isAuthorized([Auth::ROLE_ELEVE, Auth::ROLE_ADMIN]));
    }
}
