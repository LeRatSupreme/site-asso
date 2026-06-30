<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Sale;
use App\Models\User;

/**
 * Tableau de bord de l'administration.
 */
final class AdminController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $now = new \DateTime();
        $agg = Sale::monthAggregates((int) $now->format('Y'), (int) $now->format('n'));

        $this->renderAdmin('admin/dashboard', [
            'title'       => 'Tableau de bord',
            'usersCount'  => User::countActive(),
            'eventsCount' => Event::count(),
            'monthCa'     => $agg['ca'],
            'monthProfit' => $agg['profit'],
            'recentAudit' => AuditLog::recent(10),
        ]);
    }

    public function wiki(): void
    {
        $this->guard();

        $this->renderAdmin('admin/wiki/index', [
            'title' => 'Wiki — Guide de l\'admin',
            'user'  => Auth::user(),
        ]);
    }
}
