<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Matrice des rôles et des modules de l'espace d'administration.
 *
 * Un rôle (autre que ADMIN, qui a tous les droits) ouvre accès à un ou
 * plusieurs modules. Chaque module correspond à une famille de routes
 * /admin/* protégée par AdminBaseController::guardModule().
 *
 * Logique pure (sans DB) : testable unitairement et réutilisable par les
 * contrôleurs, les layouts et les vues.
 */
final class Permissions
{
    /** Modules de l'espace d'administration. */
    public const MODULE_COMPTA     = 'compta';
    public const MODULE_EVENTS     = 'events';
    public const MODULE_CONTENT    = 'content';
    public const MODULE_CAFETERIA  = 'cafeteria';
    public const MODULE_GAMES      = 'games';

    /**
     * Rôles existants, du plus privilégié au moins privilégié.
     *
     * @return array<string,string> Clé = rôle (constante Auth::ROLE_*), valeur = libellé.
     */
    public static function roles(): array
    {
        return [
            Auth::ROLE_ADMIN         => 'Administrateur',
            Auth::ROLE_TRESORERIE    => 'Trésorerie',
            Auth::ROLE_EVENEMENTS    => 'Événements',
            Auth::ROLE_COMMUNICATION => 'Communication',
            Auth::ROLE_CAFETERIA     => 'Cafétéria',
            Auth::ROLE_JEUX          => 'Jeux',
            Auth::ROLE_ELEVE         => 'Élève',
        ];
    }

    /**
     * Rôles donnant accès à l'espace d'administration (au moins un module).
     *
     * @return list<string>
     */
    public static function adminRoles(): array
    {
        $roles = array_keys(self::roleModules());

        return array_values(array_diff($roles, [Auth::ROLE_ELEVE]));
    }

    /**
     * Indique si le rôle ouvre l'accès à l'espace d'administration.
     */
    public static function isAdminRole(?string $role): bool
    {
        return $role !== null && $role !== Auth::ROLE_ELEVE && isset(self::roleModules()[$role]);
    }

    /**
     * Modules accessibles pour chaque rôle (ADMIN = tous).
     *
     * @return array<string,list<string>>
     */
    public static function roleModules(): array
    {
        return [
            Auth::ROLE_ADMIN => [
                self::MODULE_COMPTA,
                self::MODULE_EVENTS,
                self::MODULE_CONTENT,
                self::MODULE_CAFETERIA,
                self::MODULE_GAMES,
            ],
            Auth::ROLE_TRESORERIE    => [self::MODULE_COMPTA],
            Auth::ROLE_EVENEMENTS    => [self::MODULE_EVENTS],
            Auth::ROLE_COMMUNICATION => [self::MODULE_CONTENT],
            Auth::ROLE_CAFETERIA     => [self::MODULE_CAFETERIA],
            Auth::ROLE_JEUX          => [self::MODULE_GAMES],
            Auth::ROLE_ELEVE         => [],
        ];
    }

    /**
     * Modules autorisés pour un rôle donné.
     *
     * @return list<string>
     */
    public static function modulesFor(?string $role): array
    {
        if ($role === null) {
            return [];
        }

        return self::roleModules()[$role] ?? [];
    }

    /**
     * Indique si un rôle a accès à un module.
     */
    public static function allows(?string $role, string $module): bool
    {
        return in_array($module, self::modulesFor($role), true);
    }

    /**
     * Rôles autorisés à accéder à un module (utilisés par Middleware::requireRole).
     *
     * @return list<string>
     */
    public static function rolesForModule(string $module): array
    {
        $roles = [];
        foreach (self::roleModules() as $role => $modules) {
            if (in_array($module, $modules, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }
}
