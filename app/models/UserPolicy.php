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
     * Indique si rétrograder (changement de rôle) un ADMIN entraînerait la
     * disparition du dernier administrateur actif.
     *
     * @param string $currentRole Rôle actuel de l'utilisateur ciblé.
     * @param string $newRole     Nouveau rôle souhaité.
     * @param int    $activeAdmins Nombre d'administrateurs actuellement actifs.
     */
    public static function demotionRemovesLastAdmin(
        string $currentRole,
        string $newRole,
        int $activeAdmins
    ): bool {
        if ($currentRole !== Auth::ROLE_ADMIN) {
            return false;
        }

        return $newRole !== Auth::ROLE_ADMIN && $activeAdmins <= 1;
    }

    /**
     * Indique si désactiver un compte admin entraînerait la disparition du
     * dernier administrateur actif.
     *
     * @param string $currentRole   Rôle actuel de l'utilisateur ciblé.
     * @param bool   $currentlyActive Le compte est-il actif ?
     * @param int    $activeAdmins  Nombre d'administrateurs actuellement actifs.
     */
    public static function deactivationRemovesLastAdmin(
        string $currentRole,
        bool $currentlyActive,
        int $activeAdmins
    ): bool {
        if (!$currentlyActive || $currentRole !== Auth::ROLE_ADMIN) {
            return false;
        }

        return $activeAdmins <= 1;
    }
}
