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
 * Le groupe « Système » (Utilisateurs, Caisses, Inventaire, Coûts de
 * revient, Paramètres) exige en plus ADMIN dans la liste SYSTEM_ADMINS
 * (guardSystem = Middleware::resolve + isSystemAdmin). L'inventaire et
 * les coûts de revient appartiennent à ce groupe.
 *
 * On teste Middleware::resolve() et Permissions::isSystemAdmin() (logique
 * pure, sans redirection/exit réels).
 */
final class AdminGuardTest extends TestCase
{
    /** Valeur de SYSTEM_ADMINS avant le test (null = variable absente). */
    private ?string $systemAdminsBackup;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];

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

    public function test_role_communication_accede_aux_events_et_contenu_pas_compta(): void
    {
        $_SESSION['user_id'] = 'com1';
        $_SESSION['user_role'] = Auth::ROLE_COMMUNICATION;

        // Communication gère le contenu ET les événements.
        self::assertSame(Middleware::OK, Middleware::resolve(Permissions::rolesForModule(Permissions::MODULE_EVENTS)));
        self::assertSame(Middleware::OK, Middleware::resolve(Permissions::rolesForModule(Permissions::MODULE_CONTENT)));
        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve(Permissions::rolesForModule(Permissions::MODULE_COMPTA)));
        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    public function test_roles_module_refusent_le_module_compta(): void
    {
        $compta = Permissions::rolesForModule(Permissions::MODULE_COMPTA);

        foreach ([Auth::ROLE_COMMUNICATION, Auth::ROLE_CAFETERIA, Auth::ROLE_JEUX] as $role) {
            $_SESSION['user_id'] = strtolower($role) . '1';
            $_SESSION['user_role'] = $role;

            self::assertSame(Middleware::FORBIDDEN, Middleware::resolve($compta), $role);
            self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_ADMIN]), $role);
        }
    }

    public function test_admin_roles_excluent_eleve(): void
    {
        self::assertContains(Auth::ROLE_COMMUNICATION, Permissions::adminRoles());
        self::assertNotContains(Auth::ROLE_ELEVE, Permissions::adminRoles());
    }

    // -----------------------------------------------------------------
    //  Groupe « Système » : ADMIN + SYSTEM_ADMINS (guardSystem)
    // -----------------------------------------------------------------

    public function test_admin_hors_liste_refuse_users_et_settings(): void
    {
        putenv('SYSTEM_ADMINS=autre@aeic.fr');
        $_SESSION['user_id'] = 'adm1';
        $_SESSION['user_role'] = Auth::ROLE_ADMIN;

        // Le rôle passe, mais l'email n'est pas dans SYSTEM_ADMINS :
        // guardSystem() renvoie 403 sur /admin/users et /admin/settings.
        self::assertSame(Middleware::OK, Middleware::resolve([Auth::ROLE_ADMIN]));
        self::assertFalse(Permissions::isSystemAdmin());
        self::assertFalse(Permissions::isSystemAdmin('adm1@aeic.fr'));
    }

    public function test_admin_sans_liste_reserve_systeme_au_fondateur(): void
    {
        // Hiérarchie « Fondateur » : liste absente, le groupe Système est
        // réservé au SUPERADMIN ; un ADMIN simple n'y accède pas.
        putenv('SYSTEM_ADMINS');
        $_SESSION['user_id'] = 'adm1';
        $_SESSION['user_role'] = Auth::ROLE_ADMIN;

        self::assertSame(Middleware::OK, Middleware::resolve([Auth::ROLE_ADMIN]));
        self::assertFalse(Permissions::isSystemAdmin('admin@aeic.fr'));
    }

    public function test_fondateur_accede_toujours_au_systeme(): void
    {
        // Le SUPERADMIN (Fondateur) accède au groupe Système sans condition.
        putenv('SYSTEM_ADMINS');
        $_SESSION['user_id'] = 'founder';
        $_SESSION['user_role'] = Auth::ROLE_SUPERADMIN;

        self::assertSame(Middleware::OK, Middleware::resolve([Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN]));
        self::assertTrue(Permissions::isSystemAdmin());
        self::assertTrue(Permissions::isAdminRole(Auth::ROLE_SUPERADMIN));
    }

    public function test_admin_email_liste_accede_users_et_settings(): void
    {
        putenv('SYSTEM_ADMINS=adrien.remond@protonmail.com');
        $_SESSION['user_id'] = 'adm1';
        $_SESSION['user_role'] = Auth::ROLE_ADMIN;

        // guardSystem() : rôle OK + email listé → accès.
        self::assertSame(Middleware::OK, Middleware::resolve([Auth::ROLE_ADMIN]));
        self::assertTrue(Permissions::isSystemAdmin('Adrien.Remond@ProtonMail.com'));
    }

    public function test_tresorerie_refuse_users_et_settings_meme_email_liste(): void
    {
        putenv('SYSTEM_ADMINS=tresorerie@aeic.fr');
        $_SESSION['user_id'] = 'tres1';
        $_SESSION['user_role'] = Auth::ROLE_TRESORERIE;

        // Le rôle TRESORERIE ne suffit pas, l'email listé ne change rien.
        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_ADMIN]));
    }

    // -----------------------------------------------------------------
    //  Inventaire & coûts : groupe Système (TRESORERIE garde
    //  achats/pertes/réappro)
    // -----------------------------------------------------------------

    public function test_tresorerie_refuse_inventaire_mais_garde_achats_pertes_reappro(): void
    {
        $_SESSION['user_id'] = 'tres1';
        $_SESSION['user_role'] = Auth::ROLE_TRESORERIE;

        // Inventaire et coûts de revient sont passés dans le groupe « Système »
        // (guardSystem) : TRESORERIE est refusé à la fois par le rôle
        // (requireRole = SUPERADMIN + ADMIN) et par isSystemAdmin().
        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve([Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN]));

        // Achats, pertes, réappro restent dans le module compta : OK.
        $compta = Permissions::rolesForModule(Permissions::MODULE_COMPTA);
        self::assertSame(Middleware::OK, Middleware::resolve($compta));
    }

    // -----------------------------------------------------------------
    //  Comptage de caisse (Comptabilité) : tout le bureau, élèves exclus
    // -----------------------------------------------------------------

    public function test_comptage_caisse_ouvert_tout_le_bureau_sauf_eleve(): void
    {
        // Tous les rôles du bureau passent par adminRoles() (garde de la page).
        foreach (Permissions::adminRoles() as $role) {
            $_SESSION['user_id'] = 'u_' . strtolower((string) $role);
            $_SESSION['user_role'] = $role;

            self::assertSame(Middleware::OK, Middleware::resolve(Permissions::adminRoles()), $role);
        }

        // Les élèves restent exclus.
        $_SESSION['user_id'] = 'eleve1';
        $_SESSION['user_role'] = Auth::ROLE_ELEVE;

        self::assertSame(Middleware::FORBIDDEN, Middleware::resolve(Permissions::adminRoles()));
    }
}
