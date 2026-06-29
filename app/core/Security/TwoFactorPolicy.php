<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Auth;

/**
 * Politique d'authentification à deux facteurs.
 *
 * Logique pure et testable : définit quels rôles exigent le 2FA et s'il est
 * satisfait. Utilisée par le middleware et le flux de connexion.
 */
final class TwoFactorPolicy
{
    /**
     * Rôles pour lesquels le 2FA est obligatoire.
     *
     * @return list<string>
     */
    public static function requiredRoles(): array
    {
        return [Auth::ROLE_ADMIN, Auth::ROLE_TRESORERIE];
    }

    /**
     * Indique si un rôle donné exige le 2FA.
     */
    public static function requires(string $role): bool
    {
        return in_array($role, self::requiredRoles(), true);
    }

    /**
     * Indique si l'obligation 2FA est satisfaite pour un utilisateur.
     *
     * @param string $role     Rôle de l'utilisateur.
     * @param bool   $enabled  Le 2FA est-il activé pour ce compte ?
     */
    public static function satisfied(string $role, bool $enabled): bool
    {
        if (!self::requires($role)) {
            return true;
        }

        return $enabled;
    }
}
