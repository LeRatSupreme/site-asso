<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\CafeteriaOrder;
use App\Models\Event;
use App\Models\User;

/**
 * Tableau de bord de l'administration.
 */
final class AdminController extends AdminBaseController
{
    public function index(): void
    {
        $this->guard();

        $this->renderAdmin('admin/dashboard', [
            'title'          => 'Tableau de bord',
            'usersCount'     => User::countActive(),
            'eventsCount'    => Event::count(),
            'ordersCount'    => count(CafeteriaOrder::allForAdmin()),
            'revenue'        => CafeteriaOrder::revenue(),
            'recentAudit'    => AuditLog::recent(10),
            'recentOrders'   => CafeteriaOrder::allForAdmin(5),
        ]);
    }
}
