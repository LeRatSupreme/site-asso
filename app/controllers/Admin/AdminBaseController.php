<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Middleware;
use App\Core\Permissions;

/**
 * Contrôleur de base de l'espace d'administration.
 *
 * Quatre niveaux d'accès :
 *  - guard()          : réservé à ADMIN (inventaire…) ;
 *  - guardSystem()    : ADMIN explicitement autorisé via SYSTEM_ADMINS
 *                      (utilisateurs, paramètres, adhésions…) ;
 *  - guardModule(x)   : ADMIN + rôles auxquels le module x est ouvert
 *                      (voir App\Core\Permissions) ;
 *  - guardAdminArea() : tout rôle ayant accès à au moins un module
 *                      (tableau de bord, wiki).
 */
abstract class AdminBaseController extends Controller
{
    /**
     * Vérifie l'accès administrateur et renvoie l'utilisateur connecté.
     *
     * @return array<string,mixed>
     */
    protected function guard(): array
    {
        Middleware::requireRole([Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN]);

        return Auth::user();
    }

    /**
     * Garde-fou du groupe « Système » (Utilisateurs, Paramètres, adhésions) :
     * rôle SUPERADMIN (Fondateur) = accès systématique ; ADMIN = accès si
     * listé dans SYSTEM_ADMINS (voir Permissions::isSystemAdmin()).
     *
     * @return array<string,mixed>
     */
    protected function guardSystem(): array
    {
        Middleware::requireRole([Auth::ROLE_SUPERADMIN, Auth::ROLE_ADMIN]);

        if (!Permissions::isSystemAdmin()) {
            Middleware::forbidden();
        }

        return Auth::user();
    }

    /**
     * Garde-fou par module : ADMIN + rôles autorisés pour ce module.
     *
     * @return array<string,mixed>
     */
    protected function guardModule(string $module): array
    {
        Middleware::requireRole(Permissions::rolesForModule($module));

        return Auth::user();
    }

    /**
     * Garde-fou de l'espace d'administration (tableau de bord, wiki) :
     * tout rôle ayant accès à au moins un module.
     *
     * @return array<string,mixed>
     */
    protected function guardAdminArea(): array
    {
        Middleware::requireRole(Permissions::adminRoles());

        return Auth::user();
    }

    /**
     * Garde-fou spécifique au module comptabilité.
     *
     * Les routes /admin/compta/* sont accessibles aux rôles ADMIN et
     * TRESORERIE (voir Permissions) ; toutes les autres routes /admin/*
     * restent régies par guard() / guardModule().
     *
     * @return array<string,mixed>
     */
    protected function guardCompta(): array
    {
        return $this->guardModule(Permissions::MODULE_COMPTA);
    }

    /**
     * Journalise une action sensible (audit log).
     */
    protected function audit(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $details = null
    ): void {
        \App\Models\AuditLog::log($action, Auth::id(), $entityType, $entityId, $details);
    }

    /**
     * Rend une vue dans le layout admin.
     *
     * @param array<string,mixed> $data
     */
    protected function renderAdmin(string $view, array $data = []): void
    {
        $this->render($view, $data, 'admin');
    }
}
