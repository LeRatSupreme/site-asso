<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Middleware;
use App\Core\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * Tests du contrôle d'accès de l'espace d'administration.
 *
 * L'espace admin est segmenté par module (voir App\Core\Permissions) :
 * un visiteur est redirigé vers la connexion, un rôle sans le module
 * requis reçoit 403, les rôles autorisés passent.
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

    public function test_role_evenements_accede_au_module_events_pas_aux_autres(): void
    {
        $_SESSION['user_id'] = 'evt1';
        $_SESSION['user_role'] = Auth::ROLE_EVENEMENTS;

        self::assertSame(Middleware::OK, Middleware::resolve(Permissions::rolesForModule(Permissions::MODULE_EVENTS)));
        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve(Permissions::rolesForModule(Permissions::MODULE_COMPTA)));
        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve(Permissions::rolesForModule(Permissions::MODULE_CONTENT)));
        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    public function test_roles_module_refusent_le_module_compta(): void
    {
        $compta = Permissions::rolesForModule(Permissions::MODULE_COMPTA);

        foreach ([Auth::ROLE_EVENEMENTS, Auth::ROLE_COMMUNICATION, Auth::ROLE_CAFETERIA, Auth::ROLE_JEUX] as $role) {
            $_SESSION['user_id'] = strtolower($role) . '1';
            $_SESSION['user_role'] = $role;

            self::assertSame(Middleware::FORBIDDEN, Middleware::resolve($compta), $role);
            self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_ADMIN]), $role);
        }
    }

    public function test_admin_roles_excluent_eleve(): void
    {
        self::assertContains(Auth::ROLE_EVENEMENTS, Permissions::adminRoles());
        self::assertNotContains(Auth::ROLE_ELEVE, Permissions::adminRoles());
    }
}
