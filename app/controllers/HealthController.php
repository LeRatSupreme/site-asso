<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Logger;

/**
 * Endpoint de santé (/health) : vérifie la disponibilité de la base.
 *
 * Répond 200 (JSON) si tout va bien, 503 sinon. Utilisé par le monitoring.
 */
final class HealthController extends Controller
{
    public function health(): void
    {
        $checks = ['app' => true, 'database' => false];

        try {
            db()->query('SELECT 1');
            $checks['database'] = true;
        } catch (\Throwable $e) {
            Logger::error('health: base de données injoignable', ['msg' => $e->getMessage()]);
        }

        $ok = !in_array(false, $checks, true);

        $this->json([
            'status'  => $ok ? 'ok' : 'degraded',
            'checks'  => $checks,
            'time'    => date('c'),
        ], $ok ? 200 : 503);
    }
}
