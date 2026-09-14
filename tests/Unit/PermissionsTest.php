<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la matrice rôles → modules de l'espace d'administration.
 */
final class PermissionsTest extends TestCase
{
    public function test_admin_a_tous_les_modules(): void
    {
        foreach (Permissions::roleModules()[Auth::ROLE_ADMIN] as $module) {
            self::assertTrue(Permissions::allows(Auth::ROLE_ADMIN, $module));
        }
        self::assertContains(Permissions::MODULE_COMPTA, Permissions::modulesFor(Auth::ROLE_ADMIN));
        self::assertContains(Permissions::MODULE_EVENTS, Permissions::modulesFor(Auth::ROLE_ADMIN));
    }

    public function test_tresorerie_limite_a_la_compta(): void
    {
        self::assertTrue(Permissions::allows(Auth::ROLE_TRESORERIE, Permissions::MODULE_COMPTA));
        self::assertFalse(Permissions::allows(Auth::ROLE_TRESORERIE, Permissions::MODULE_EVENTS));
        self::assertFalse(Permissions::allows(Auth::ROLE_TRESORERIE, Permissions::MODULE_CONTENT));
    }

    public function test_roles_module_n_ont_qu_un_module(): void
    {
        self::assertSame([Permissions::MODULE_EVENTS], Permissions::modulesFor(Auth::ROLE_EVENEMENTS));
        self::assertSame([Permissions::MODULE_CONTENT], Permissions::modulesFor(Auth::ROLE_COMMUNICATION));
        self::assertSame([Permissions::MODULE_CAFETERIA], Permissions::modulesFor(Auth::ROLE_CAFETERIA));
        self::assertSame([Permissions::MODULE_GAMES], Permissions::modulesFor(Auth::ROLE_JEUX));
    }

    public function test_eleve_et_visiteur_n_ont_acces_a_rien(): void
    {
        self::assertSame([], Permissions::modulesFor(Auth::ROLE_ELEVE));
        self::assertSame([], Permissions::modulesFor(null));
        self::assertFalse(Permissions::isAdminRole(Auth::ROLE_ELEVE));
        self::assertFalse(Permissions::isAdminRole(null));
        self::assertNotContains(Auth::ROLE_ELEVE, Permissions::adminRoles());
    }

    public function test_roles_for_module_incluent_admin(): void
    {
        self::assertContains(Auth::ROLE_ADMIN, Permissions::rolesForModule(Permissions::MODULE_EVENTS));
        self::assertContains(Auth::ROLE_EVENEMENTS, Permissions::rolesForModule(Permissions::MODULE_EVENTS));
        self::assertNotContains(Auth::ROLE_COMMUNICATION, Permissions::rolesForModule(Permissions::MODULE_EVENTS));
        self::assertNotContains(Auth::ROLE_ELEVE, Permissions::rolesForModule(Permissions::MODULE_EVENTS));
    }

    public function test_roles_couvrent_les_modules(): void
    {
        $all = [];
        foreach (Permissions::roleModules() as $modules) {
            $all = array_merge($all, $modules);
        }
        self::assertSame(
            [Permissions::MODULE_COMPTA, Permissions::MODULE_EVENTS, Permissions::MODULE_CONTENT, Permissions::MODULE_CAFETERIA, Permissions::MODULE_GAMES],
            array_values(array_unique($all))
        );
    }
}
