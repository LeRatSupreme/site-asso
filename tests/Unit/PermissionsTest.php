<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Permissions;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la matrice rôles → modules de l'espace d'administration
 * et de la liste SYSTEM_ADMINS (groupe « Système »).
 */
final class PermissionsTest extends TestCase
{
    /** Valeur de SYSTEM_ADMINS avant le test (null = variable absente). */
    private ?string $systemAdminsBackup;

    protected function setUp(): void
    {
        $backup = getenv('SYSTEM_ADMINS');
        $this->systemAdminsBackup = $backup === false ? null : $backup;
    }

    protected function tearDown(): void
    {
        if ($this->systemAdminsBackup === null) {
            putenv('SYSTEM_ADMINS');
        } else {
            putenv('SYSTEM_ADMINS=' . $this->systemAdminsBackup);
        }
    }

    public function test_admin_a_tous_les_modules(): void
    {
        foreach (Permissions::roleModules()[Auth::ROLE_ADMIN] as $module) {
            self::assertTrue(Permissions::allows(Auth::ROLE_ADMIN, $module));
        }
        self::assertContains(Permissions::MODULE_COMPTA, Permissions::modulesFor(Auth::ROLE_ADMIN));
        self::assertContains(Permissions::MODULE_EVENTS, Permissions::modulesFor(Auth::ROLE_ADMIN));
    }

    public function test_tresorerie_compta_plus_modules_gestion(): void
    {
        // La trésorerie a la compta + les jeux, le contenu (communication,
        // événements inclus) et la cafétéria.
        self::assertTrue(Permissions::allows(Auth::ROLE_TRESORERIE, Permissions::MODULE_COMPTA));
        self::assertTrue(Permissions::allows(Auth::ROLE_TRESORERIE, Permissions::MODULE_CONTENT));
        self::assertTrue(Permissions::allows(Auth::ROLE_TRESORERIE, Permissions::MODULE_EVENTS));
        self::assertTrue(Permissions::allows(Auth::ROLE_TRESORERIE, Permissions::MODULE_CAFETERIA));
        self::assertTrue(Permissions::allows(Auth::ROLE_TRESORERIE, Permissions::MODULE_GAMES));
    }

    public function test_roles_modules_specifiques(): void
    {
        // Communication : contenu + événements + cafétéria.
        self::assertSame(
            [Permissions::MODULE_CONTENT, Permissions::MODULE_EVENTS, Permissions::MODULE_CAFETERIA],
            Permissions::modulesFor(Auth::ROLE_COMMUNICATION)
        );
        // Jeux : les jeux + la cafétéria.
        self::assertSame(
            [Permissions::MODULE_GAMES, Permissions::MODULE_CAFETERIA],
            Permissions::modulesFor(Auth::ROLE_JEUX)
        );
        self::assertSame([Permissions::MODULE_CAFETERIA], Permissions::modulesFor(Auth::ROLE_CAFETERIA));
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
        self::assertContains(Auth::ROLE_COMMUNICATION, Permissions::rolesForModule(Permissions::MODULE_EVENTS));
        // Trésorerie : accès aux événements voulu (commit 1935a7e).
        self::assertContains(Auth::ROLE_TRESORERIE, Permissions::rolesForModule(Permissions::MODULE_EVENTS));
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

    public function test_system_admins_absente_ou_vide_aucun_acces(): void
    {
        putenv('SYSTEM_ADMINS');
        self::assertFalse(Permissions::isSystemAdmin('admin@aeic.fr'));

        putenv('SYSTEM_ADMINS=');
        self::assertFalse(Permissions::isSystemAdmin('admin@aeic.fr'));
        self::assertFalse(Permissions::isSystemAdmin());
    }

    public function test_system_admins_email_liste_insensible_casse_et_espaces(): void
    {
        putenv('SYSTEM_ADMINS= Adrien.Remond@ProtonMail.com , autre@aeic.fr');

        self::assertTrue(Permissions::isSystemAdmin('adrien.remond@protonmail.com'));
        self::assertTrue(Permissions::isSystemAdmin('  ADRIEN.REMOND@PROTONMAIL.COM '));
        self::assertFalse(Permissions::isSystemAdmin('intrus@aeic.fr'));
    }

    public function test_system_admins_entrees_vides_ignorees(): void
    {
        putenv('SYSTEM_ADMINS=a@b.fr ,,');

        self::assertTrue(Permissions::isSystemAdmin('a@b.fr'));
        self::assertFalse(Permissions::isSystemAdmin(''));
        self::assertFalse(Permissions::isSystemAdmin(null));
    }

    public function test_granted_pages_null_ou_vide(): void
    {
        self::assertSame([], Permissions::grantedPages(null));
        self::assertSame([], Permissions::grantedPages(''));
        self::assertSame([], Permissions::grantedPages(' , , '));
    }

    public function test_granted_pages_cles_connues(): void
    {
        self::assertSame(['inventory', 'costs'], Permissions::grantedPages('inventory,costs'));
        self::assertSame(['cash'], Permissions::grantedPages('cash'));
    }

    public function test_granted_pages_trim_et_cle_inconnue_ignoree(): void
    {
        self::assertSame(['inventory', 'cash'], Permissions::grantedPages(' inventory , bogus , cash '));
    }

    public function test_granted_pages_doublons_dedupliques(): void
    {
        self::assertSame(['inventory', 'cash'], Permissions::grantedPages('inventory,inventory,cash,inventory'));
    }
}
