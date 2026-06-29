<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Models\UserPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests de la politique de sécurité des comptes (protection du dernier admin).
 *
 * Logique pure, sans base de données.
 */
final class UserPolicyTest extends TestCase
{
    public function test_retrograder_le_dernier_admin_est_bloque(): void
    {
        self::assertTrue(UserPolicy::demotionRemovesLastAdmin(Auth::ROLE_ADMIN, Auth::ROLE_ELEVE, 1));
        self::assertTrue(UserPolicy::demotionRemovesLastAdmin(Auth::ROLE_ADMIN, Auth::ROLE_TRESORERIE, 1));
    }

    public function test_retrograder_un_admin_quand_il_en_reste_est_autorise(): void
    {
        self::assertFalse(UserPolicy::demotionRemovesLastAdmin(Auth::ROLE_ADMIN, Auth::ROLE_ELEVE, 2));
        self::assertFalse(UserPolicy::demotionRemovesLastAdmin(Auth::ROLE_ADMIN, Auth::ROLE_ELEVE, 5));
    }

    public function test_changer_le_role_d_un_non_admin_n_est_jamais_bloque(): void
    {
        self::assertFalse(UserPolicy::demotionRemovesLastAdmin(Auth::ROLE_ELEVE, Auth::ROLE_ADMIN, 1));
        self::assertFalse(UserPolicy::demotionRemovesLastAdmin(Auth::ROLE_TRESORERIE, Auth::ROLE_ELEVE, 1));
    }

    public function test_garder_le_role_admin_n_est_pas_une_retrogradation(): void
    {
        self::assertFalse(UserPolicy::demotionRemovesLastAdmin(Auth::ROLE_ADMIN, Auth::ROLE_ADMIN, 1));
    }

    public function test_desactiver_le_dernier_admin_est_bloque(): void
    {
        self::assertTrue(UserPolicy::deactivationRemovesLastAdmin(Auth::ROLE_ADMIN, true, 1));
    }

    public function test_desactiver_un_admin_quand_il_en_reste_est_autorise(): void
    {
        self::assertFalse(UserPolicy::deactivationRemovesLastAdmin(Auth::ROLE_ADMIN, true, 2));
    }

    public function test_desactiver_un_non_admin_n_est_pas_bloque_par_la_regle(): void
    {
        self::assertFalse(UserPolicy::deactivationRemovesLastAdmin(Auth::ROLE_ELEVE, true, 0));
    }

    public function test_desactiver_un_comdeja_inactif_ne_compte_pas(): void
    {
        self::assertFalse(UserPolicy::deactivationRemovesLastAdmin(Auth::ROLE_ADMIN, false, 1));
    }
}
