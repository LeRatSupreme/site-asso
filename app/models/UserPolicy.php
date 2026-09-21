<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;

/**
 * Règles de sécurité applicables aux comptes (sans dépendance à la base).
 *
 * Logique pure et testable unitairement, utilisée par les contrôleurs admin
 * pour décider si une action sur un compte est autorisée.
 */
final class UserPolicy
{
    /**
     * Indique si rétrograder (changement de rôle) un compte de niveau
     * administration (SUPERADMIN ou ADMIN) entraînerait la disparition du
     * dernier administrateur actif.
     *
     * @param string $currentRole Rôle actuel de l'utilisateur ciblé.
     * @param string $newRole     Nouveau rôle souhaité.
     * @param int    $activeAdmins Nombre de comptes de niveau admin actifs.
     */
    public static function demotionRemovesLastAdmin(
        string $currentRole,
        string $newRole,
        int $activeAdmins
    ): bool {
        if (!in_array($currentRole, [Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN], true)) {
            return false;
        }

        return !in_array($newRole, [Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN], true)
            && $activeAdmins <= 1;
    }

    /**
     * Indique si désactiver un compte admin entraînerait la disparition du
     * dernier administrateur actif.
     *
     * @param string $currentRole   Rôle actuel de l'utilisateur ciblé.
     * @param bool   $currentlyActive Le compte est-il actif ?
     * @param int    $activeAdmins  Nombre de comptes de niveau admin actifs.
     */
    public static function deactivationRemovesLastAdmin(
        string $currentRole,
        bool $currentlyActive,
        int $activeAdmins
    ): bool {
        if (!$currentlyActive
            || !in_array($currentRole, [Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN], true)) {
            return false;
        }

        return $activeAdmins <= 1;
    }
}
