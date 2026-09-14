<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Permissions;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Sale;
use App\Models\User;

/**
 * Tableau de bord de l'administration.
 *
 * Accessible à tout rôle ayant accès à au moins un module (voir
 * Permissions::adminRoles). Les chiffres financiers ne sont montrés
 * qu'aux rôles autorisés sur le module comptabilité.
 */
final class AdminController extends AdminBaseController
{
    public function index(): void
    {
        $user = $this->guardAdminArea();

        $now = new \DateTime();
        $agg = Sale::monthAggregates((int) $now->format('Y'), (int) $now->format('n'));

        $this->renderAdmin('admin/dashboard', [
            'title'       => 'Tableau de bord',
            'usersCount'  => User::countActive(),
            'eventsCount' => Event::count(),
            'monthCa'     => $agg['ca'],
            'monthProfit' => $agg['profit'],
            'showFinance' => Permissions::allows((string) ($user['role'] ?? ''), Permissions::MODULE_COMPTA),
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
