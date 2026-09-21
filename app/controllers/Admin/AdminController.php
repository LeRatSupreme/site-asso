<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;

/**
 * Tableau de bord de l'administration.
 *
 * Accessible à tout rôle ayant accès à au moins un module (voir
 * Permissions::adminRoles).
 */
final class AdminController extends AdminBaseController
{
    public function index(): void
    {
        $this->guardAdminArea();

        $this->renderAdmin('admin/dashboard', [
            'title'       => 'Tableau de bord',
            'usersCount'  => User::countActive(),
            'eventsCount' => Event::count(),
            // Journal d'audit réservé au rôle ADMIN.
            'recentAudit' => Auth::isAdmin() ? AuditLog::recent(10) : [],
        ]);
    }

    public function wiki(): void
    {
        $this->guardAdminArea();

        $this->renderAdmin('admin/wiki/index', [
            'title' => 'Wiki — Guide de l\'admin',
            'user'  => Auth::user(),
        ]);
    }
}
