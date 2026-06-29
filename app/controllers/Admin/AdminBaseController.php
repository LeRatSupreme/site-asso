<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Middleware;

/**
 * Contrôleur de base de l'espace d'administration.
 *
 * Toutes les actions admin requièrent le rôle ADMIN.
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
        Middleware::requireRole([Auth::ROLE_ADMIN]);

        return Auth::user();
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
